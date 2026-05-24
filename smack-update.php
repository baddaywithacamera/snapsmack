<?php
/**
 * SNAPSMACK - System Update Manager
 *
 * Admin interface for the self-update system. Displays current version info,
 * checks for updates, shows changelogs and file changes, forces a backup
 * before applying updates, runs schema migrations, and provides rollback
 * capability on failure.
 *
 * Also shows skin registry notifications (new skins available, skin updates).
 *
 * FLOW (staged — each stage is a separate HTTP request to avoid timeouts):
 * 1. CHECK         — Fetch latest release info from update server
 * 2. REVIEW        — Display changelog, file changes, migration warnings
 * 3. STAGE: download  — Download zip to temp dir
 * 4. STAGE: verify    — Checksum + Ed25519 signature check
 * 5. STAGE: backup      — Forced pre-update backup
 * 6. STAGE: premigrate — Extract migrations/ from zip, run against DB BEFORE files go live
 * 7. STAGE: extract    — Extract all remaining files (DB already patched)
 * 8. STAGE: migrate    — Finalize: version bump, deprecated cleanup, asset sync
 * 9. DONE              — Success screen, or rollback on failure
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */




// Bypass the maintenance lock check in core/constants.php — the updater's
// own chunk requests must not be blocked by the lock they created.
define('SNAPSMACK_IS_UPDATER', true);

// smack-update.php manages its own CSRF token (update_csrf) separately
// from the global csrf_token. Exempt from the auto-validator in auth-smack.php
// so the global check doesn't fire before our own check runs.
define('SNAPSMACK_CSRF_EXEMPT', true);
require_once 'core/auth-smack.php';
require_once 'core/updater.php';
require_once 'core/skin-registry.php';

// Fallback for sites upgrading from < 0.7.4 where constants.php
// doesn't yet define snap_version_compare().
if (!function_exists('snap_version_compare')) {
    function snap_version_compare(string $v1, string $v2, string $op = '>'): bool {
        $normalise = function (string $v): string {
            if (preg_match('/^(\d+(?:\.\d+)*)([a-z])$/i', $v, $m)) {
                return $m[1] . '.' . (ord(strtolower($m[2])) - ord('a') + 1);
            }
            return $v . '.0';
        };
        return version_compare($normalise($v1), $normalise($v2), $op);
    }
}

// --- EARLY CRON DETECTION ---
if (!isset($cron_supported)) {
    $cron_supported = false;
    $php_cli_path   = '';
    if (function_exists('exec')) {
        exec('crontab -l 2>&1', $_ct_out, $_ct_code);
        $cron_supported = ($_ct_code === 0);
        $php_cli_path   = trim(exec('which php 2>&1'));
        if (strpos($php_cli_path, '/') !== 0) $php_cli_path = '';
    }
}

// --- CSRF TOKEN ---
if (empty($_SESSION['update_csrf'])) {
    $_SESSION['update_csrf'] = bin2hex(random_bytes(32));
}
$csrf       = $_SESSION['update_csrf'];
$auto_check = false; // set true when check is triggered automatically on page load

// --- CURRENT VERSION ---
$installed_version  = SNAPSMACK_VERSION_SHORT ?? '0.0';
$installed_full     = SNAPSMACK_VERSION ?? 'Unknown';
$installed_checksum = '';

try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_val FROM snap_settings WHERE setting_key IN ('installed_version','installed_checksum')");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
        if ($k === 'installed_version' && snap_version_compare($v, $installed_version, '>')) {
            $installed_version = $v;
        } elseif ($k === 'installed_checksum') {
            $installed_checksum = $v;
        }
    }
} catch (PDOException $e) {}

// --- CACHED CHECK RESULT ---
$cached_result = null;
$last_check    = null;
try {
    $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'update_check_result'");
    $stmt->execute();
    $json = $stmt->fetchColumn();
    if ($json) $cached_result = json_decode($json, true);

    $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'last_update_check'");
    $stmt->execute();
    $last_check = $stmt->fetchColumn();
} catch (PDOException $e) {}

// Normalise cached result — stale JSON from older versions may be missing keys
// added in later releases.  Backfill with safe defaults so the rest of the page
// never hits an undefined-key fatal.
if (is_array($cached_result)) {
    $cached_result += [
        'checked_at'          => '',
        'installed_version'   => '',
        'core_status'         => '',
        'core_update'         => null,
        'new_skins'           => [],
        'updated_skins'       => [],
        'skin_notifications'  => 0,
        'total_notifications' => 0,
        'canonical_schema_url' => '',
        'canonical_schema_sig' => '',
    ];
    if (is_array($cached_result['core_update'])) {
        $cached_result['core_update'] = _normalise_update_array($cached_result['core_update']);
    }
}

/**
 * Backfill safe defaults into an update array so no key is ever undefined.
 * Uses += (union) — existing keys are never overwritten.
 */
function _normalise_update_array(array $u): array {
    return $u + [
        'version'         => '',
        'version_full'    => '',
        'codename'        => '',
        'released'        => '',
        'changelog'       => [],
        'file_changes'    => [],
        'schema_changes'  => false,
        'download_size'   => 0,
        'requires_php'    => '8.0',
        'download_url'    => '',
        'checksum_sha256' => '',
        'signature'       => '',
    ];
}

// --- STAGED UPDATE STATE ---
// Persists between stages via session.
$stage_state = $_SESSION['update_state'] ?? null;
if (is_array($stage_state) && is_array($stage_state['update'] ?? null)) {
    $stage_state['update'] = _normalise_update_array($stage_state['update']);
    $_SESSION['update_state'] = $stage_state;
}

// --- JSON OUTPUT DETECTION ---
// When triggered by the updater modal, all responses are JSON.
// The HTML path is completely unchanged — full-page still works.
$wants_json = (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
           && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
           || !empty($_GET['json']) || !empty($_POST['json']);

// --- ACTION HANDLER ---
$action     = $_POST['action'] ?? $_GET['action'] ?? '';
$flash_msg  = '';
$flash_type = 'info';

// CSRF validation for POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
    if (!isset($_POST['csrf']) || !hash_equals($csrf, $_POST['csrf'])) {
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'stage' => $action, 'message' => 'CSRF validation failed. Refresh and try again.']);
            exit;
        }
        $flash_msg  = 'CSRF VALIDATION FAILED. REFRESH AND TRY AGAIN.';
        $flash_type = 'error';
        $action     = '';
    }
}

// ── PICK UP SESSION FLASH (set by chunked extract redirect) ──────────────────
if (empty($flash_msg) && !empty($_SESSION['stage_flash_msg'])) {
    $flash_msg  = $_SESSION['stage_flash_msg'];
    $flash_type = $_SESSION['stage_flash_type'] ?? 'error';
    unset($_SESSION['stage_flash_msg'], $_SESSION['stage_flash_type']);
}

// ── STAGED EXTRACT: CHUNK HANDLER (GET — outputs standalone page, then exits) ─
//
// Each call processes up to ~12 seconds of zip extraction, saves progress to
// session, and outputs a self-refreshing progress page.  When the zip is fully
// extracted it redirects back to the main update page (stage advances to
// 'extracted').  No JS required — meta-refresh only.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'stage_extract_chunk') {
    $chunk_state = $_SESSION['update_chunk_state'] ?? null;

    // Guard: if either state is gone, something went wrong — bail to main page
    if (!$chunk_state || empty($_SESSION['update_state'])) {
        header('Location: smack-update.php');
        exit;
    }

    $zip_path = $chunk_state['zip_path'];
    $offset   = (int)($chunk_state['offset'] ?? 0);

    // Sanity-check the zip still exists (temp files can vanish on shared hosts)
    if (!file_exists($zip_path)) {
        $_SESSION['stage_flash_msg']  = 'EXTRACTION FAILED: Update package not found on disk. Please start over.';
        $_SESSION['stage_flash_type'] = 'error';
        unset($_SESSION['update_state'], $_SESSION['update_chunk_state']);
        header('Location: smack-update.php');
        exit;
    }

    // Persist session to disk BEFORE starting extraction.
    // If PHP is killed mid-extraction (OOM / server timeout), the session
    // survives intact so the next meta-refresh can retry from the same offset.
    session_write_close();
    @set_time_limit(60); // ask for more time; shared host may ignore this

    $chunk = updater_extract_chunk($zip_path, $offset);

    // Re-open session to record results
    session_start();

    // Accumulate running totals
    $chunk_state['files_updated'] += $chunk['files_updated'];
    $chunk_state['files_skipped'] += $chunk['files_skipped'];
    $chunk_state['errors']         = array_merge($chunk_state['errors'], $chunk['errors']);
    $chunk_state['total']          = $chunk['total'] ?: ($chunk_state['total'] ?? 1);
    $chunk_state['offset']         = $chunk['next_offset'];
    $_SESSION['update_chunk_state'] = $chunk_state;

    if (!$chunk['success']) {
        // Fatal write error — release lock before surfacing error to admin.
        updater_release_lock();
        $_SESSION['update_state']['log'][] = [
            'label'  => 'Extraction failed',
            'status' => 'fail',
            'detail' => implode('; ', $chunk_state['errors']),
        ];
        $_SESSION['stage_flash_msg']  = 'EXTRACTION FAILED: ' . implode('; ', $chunk_state['errors']);
        $_SESSION['stage_flash_type'] = 'error';
        unset($_SESSION['update_chunk_state']);
        header('Location: smack-update.php');
        exit;
    }

    if ($chunk['done']) {
        // All entries processed — release maintenance lock, site is live again.
        updater_release_lock();
        $_SESSION['update_state']['stage'] = 'extracted';
        $_SESSION['update_state']['log'][] = [
            'label'  => 'Files extracted',
            'status' => 'ok',
            'detail' => "{$chunk_state['files_updated']} updated, {$chunk_state['files_skipped']} protected",
        ];
        unset($_SESSION['update_chunk_state']);
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode([
                'status'   => 'ok',
                'stage'    => 'extract',
                'done'     => true,
                'progress' => 100,
                'data'     => [
                    'files_written'     => $chunk_state['files_updated'],
                    'files_skipped'     => $chunk_state['files_skipped'],
                    'protected_skipped' => $chunk_state['files_skipped'],
                ],
            ]);
            exit;
        }
        header('Location: smack-update.php');
        exit;
    }

    if ($wants_json) {
        header('Content-Type: application/json');
        echo json_encode([
            'status'   => 'ok',
            'stage'    => 'extract',
            'done'     => false,
            'progress' => $pct,
            'data'     => [
                'files_written' => $chunk_state['files_updated'],
                'files_skipped' => $chunk_state['files_skipped'],
                'total'         => $total,
            ],
        ]);
        exit;
    }

    // Still extracting — render a self-refreshing progress page
    $total      = $chunk_state['total'] ?: 1;
    $done_count = $chunk_state['offset'];
    $pct        = min(99, (int)(($done_count / $total) * 100)); // cap at 99 until truly done
    $updated    = $chunk_state['files_updated'];
    $skipped    = $chunk_state['files_skipped'];

    header('Content-Type: text/html; charset=UTF-8');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="1;url=smack-update.php?action=stage_extract_chunk">
<title>SnapSmack ― Extracting Update</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    background: #111; color: #ccc; font-family: 'Courier New', monospace;
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; min-height: 100vh;
}
h2 { color: #39FF14; letter-spacing: 3px; font-size: 0.85rem; margin-bottom: 30px; text-transform: uppercase; }
.wrap { width: 440px; max-width: 90vw; }
.pct  { font-size: 1.6rem; color: #39FF14; text-align: center; margin-bottom: 10px; font-weight: bold; }
.bar-bg {
    background: #1a1a1a; border: 1px solid #333; border-radius: 3px;
    height: 22px; overflow: hidden; margin-bottom: 14px;
}
.bar-fill { background: #39FF14; height: 100%; width: <?php echo $pct; ?>%; }
.stats { font-size: 0.75rem; color: #777; text-align: center; line-height: 2; }
.blink { animation: bl 1.2s step-end infinite; }
@keyframes bl { 50% { opacity: 0; } }
</style>
</head>
<body>
<h2>EXTRACTING UPDATE<span class="blink"> _</span></h2>
<div class="wrap">
    <div class="pct"><?php echo $pct; ?>%</div>
    <div class="bar-bg"><div class="bar-fill"></div></div>
    <div class="stats">
        <?php echo number_format($done_count); ?> of <?php echo number_format($total); ?> entries<br>
        <?php echo number_format($updated); ?> written &nbsp;&middot;&nbsp; <?php echo number_format($skipped); ?> protected
    </div>
</div>
</body>
</html>
<?php
    exit;
}

// ── ACTION: LIVE CHECK ────────────────────────────────────────────────────────
// --- REAPPLY CURRENT VERSION ---
// Re-download and re-extract the currently installed version (useful if a release was packaged
// incorrectly and files were missing). Fetches release info for the installed version and
// begins the download stage.
if ($action === 'reapply') {
    $release_info = updater_fetch_release_info($installed_version);

    if ($release_info === false) {
        $flash_msg  = 'REAPPLY FAILED: Could not fetch release info for v' . htmlspecialchars($installed_version) . '.';
        $flash_type = 'error';
    } else {
        // Reset update state and prepare for re-download
        $_SESSION['update_state'] = [
            'stage'   => 'review',
            'update'  => [
                'version'         => $release_info['version']         ?? $installed_version,
                'version_full'    => $release_info['version_full']    ?? '',
                'codename'        => $release_info['codename']        ?? '',
                'released'        => $release_info['released']        ?? '',
                'changelog'       => $release_info['changelog']       ?? [],
                'file_changes'    => $release_info['file_changes']    ?? [],
                'schema_changes'  => $release_info['schema_changes']  ?? false,
                'download_size'   => $release_info['download_size']   ?? 0,
                'requires_php'    => $release_info['requires_php']    ?? '8.0',
                'download_url'    => $release_info['download_url']    ?? '',
                'checksum_sha256' => $release_info['checksum_sha256'] ?? '',
                'signature'       => $release_info['signature']       ?? '',
            ]
        ];
        $stage_state = $_SESSION['update_state'];
        $flash_msg  = 'Reapplying v' . htmlspecialchars($installed_version) . '. Click APPLY to download and re-extract.';
        $flash_type = 'info';
    }
}

// ── SKIN UPDATE ──────────────────────────────────────────────────────────────
if ($action === 'skin_update') {
    $slug         = $_POST['skin_slug']    ?? '';
    $download_url = $_POST['download_url'] ?? '';
    $signature    = $_POST['signature']    ?? '';
    $public_key   = SNAPSMACK_RELEASE_PUBKEY;

    if (empty($slug) || empty($download_url)) {
        $flash_msg  = 'SKIN UPDATE FAILED: MISSING DATA. TRY CHECKING FOR UPDATES AGAIN.';
        $flash_type = 'error';
    } else {
        $result = skin_registry_install($slug, $download_url, $signature, $public_key);
        if ($result['success']) {
            skin_registry_clear_cache();
            $flash_msg  = strtoupper($result['message']);
            $flash_type = 'success';
        } else {
            $flash_msg  = 'SKIN UPDATE FAILED: ' . strtoupper($result['message']);
            $flash_type = 'error';
        }
    }
    // Re-run check so the page reflects the new skin state
    $action     = 'check';
    $auto_check = true;
}

// ── AUTO-CHECK ON PAGE LOAD ───────────────────────────────────────────────────
// Fires on every normal GET so the user sees a fresh result the moment they
// land on the page — no manual "Check" button needed.
// Exception: if we just completed an update, skip the live check. The update
// pipeline already confirmed this is the latest version — hitting the server
// again immediately often fails while PHP/opcache is still settling, producing
// a false "COULD NOT REACH UPDATE SERVER" error. Write up_to_date to cache
// instead and let the next natural check (or RETRY CHECK) hit the server.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !$stage_state && !$action) {
    if (!empty($_SESSION['update_complete_log'])) {
        // Just finished an update — mark up_to_date from cache, skip live check.
        $cached_result = [
            'checked_at'          => date('c'),
            'installed_version'   => $installed_version,
            'core_status'         => 'up_to_date',
            'core_update'         => null,
            'new_skins'           => [],
            'updated_skins'       => [],
            'skin_notifications'  => 0,
            'total_notifications' => 0,
        ];
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('update_check_result', ?)
                       ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
            ->execute([json_encode($cached_result, JSON_UNESCAPED_SLASHES)]);
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('last_update_check', ?)
                       ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
            ->execute([date('Y-m-d H:i:s')]);
        $last_check  = date('Y-m-d H:i:s');
        $core_status = 'up_to_date';
        $core_update = null;
        $skin_info   = ['new_skins' => [], 'updated_skins' => [], 'total_notifications' => 0];
    } else {
        // Only fire a live check if the cache is stale (>6 h) or was an error.
        // Fresh non-error cache: hydrate page vars directly — no server round-trip.
        $cache_age_secs = $last_check ? (time() - strtotime($last_check)) : PHP_INT_MAX;
        $cached_ok = is_array($cached_result)
                  && ($cached_result['core_status'] ?? '') !== ''
                  && ($cached_result['core_status'] ?? '') !== 'error';
        if ($cache_age_secs < 21600 && $cached_ok) {
            $core_status = $cached_result['core_status'];
            $core_update = $cached_result['core_update'] ?? null;
            $skin_info   = [
                'new_skins'           => $cached_result['new_skins']    ?? [],
                'updated_skins'       => $cached_result['updated_skins'] ?? [],
                'total_notifications' => $cached_result['skin_notifications'] ?? 0,
            ];
        } else {
            // Cache stale or missing — trigger JS auto-check (fast mode, 6 s / 1 attempt).
            $core_status = 'checking';
        }
    }
}

// ── AJAX CHECK ENDPOINT — unused; JS auto-check removed 2026-05-24.
// TODO: SECAUDIT — remove check_ajax action and dead JS in a future release.
//       Keeping for now to avoid a 404 if any cached page still posts to it.
if ($action === 'check_ajax') {
    header('Content-Type: application/json');
    // Fast mode: short timeout, single attempt, no skin registry fetch.
    // The JS retry loop drives retries; PHP must fail fast so the next
    // retry fires promptly rather than blocking for 40+ seconds per attempt.
    // Skin notifications are picked up on the full page reload after ok:true.
    $release_info_ax = updater_fetch_release_info(true);
    $skin_info_ax    = updater_check_skin_registry($pdo, true);
    $status_ax       = updater_check_status($installed_version, $release_info_ax, $installed_checksum);
    if ($status_ax !== 'error') {
        $core_upd_ax = null;
        if ($status_ax === 'update_available') {
            $core_upd_ax = [
                'version'         => $release_info_ax['version']         ?? '',
                'version_full'    => $release_info_ax['version_full']    ?? '',
                'codename'        => $release_info_ax['codename']        ?? '',
                'released'        => $release_info_ax['released']        ?? '',
                'changelog'       => $release_info_ax['changelog']       ?? [],
                'file_changes'    => $release_info_ax['file_changes']    ?? [],
                'schema_changes'  => $release_info_ax['schema_changes']  ?? false,
                'download_size'   => $release_info_ax['download_size']   ?? 0,
                'requires_php'    => $release_info_ax['requires_php']    ?? '8.0',
                'download_url'    => $release_info_ax['download_url']    ?? '',
                'checksum_sha256' => $release_info_ax['checksum_sha256'] ?? '',
                'signature'       => $release_info_ax['signature']       ?? '',
            ];
        }
        $cache_ax = [
            'checked_at'          => date('c'),
            'installed_version'   => $installed_version,
            'core_status'         => $status_ax,
            'core_update'         => $core_upd_ax,
            'new_skins'           => $skin_info_ax['new_skins']    ?? [],
            'updated_skins'       => $skin_info_ax['updated_skins'] ?? [],
            'skin_notifications'  => $skin_info_ax['total_notifications'] ?? 0,
            'total_notifications' => ($core_upd_ax ? 1 : 0) + ($skin_info_ax['total_notifications'] ?? 0),
        ];
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('update_check_result', ?)
                       ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
            ->execute([json_encode($cache_ax, JSON_UNESCAPED_SLASHES)]);
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('last_update_check', ?)
                       ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
            ->execute([date('Y-m-d H:i:s')]);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

if ($action === 'check') {
    $release_info = updater_fetch_release_info();
    $skin_info    = updater_check_skin_registry($pdo);
    $core_status  = updater_check_status($installed_version, $release_info, $installed_checksum);

    $core_update = null;
    if ($core_status === 'update_available') {
        $core_update = [
            'version'         => $release_info['version']         ?? '',
            'version_full'    => $release_info['version_full']    ?? '',
            'codename'        => $release_info['codename']        ?? '',
            'released'        => $release_info['released']        ?? '',
            'changelog'       => $release_info['changelog']       ?? [],
            'file_changes'    => $release_info['file_changes']    ?? [],
            'schema_changes'  => $release_info['schema_changes']  ?? false,
            'download_size'   => $release_info['download_size']   ?? 0,
            'requires_php'    => $release_info['requires_php']    ?? '8.0',
            'download_url'    => $release_info['download_url']    ?? '',
            'checksum_sha256' => $release_info['checksum_sha256'] ?? '',
            'signature'       => $release_info['signature']       ?? '',
        ];
    }

    if ($core_status === 'error') {
        // Don't clobber a good cache with a transient network failure.
        // Update the check timestamp so we don't retry on the very next page load,
        // then fall back to the last known-good cache if one exists.
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('last_update_check', ?)
                       ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
            ->execute([date('Y-m-d H:i:s')]);
        $last_check = date('Y-m-d H:i:s');

        $prev_ok = is_array($cached_result)
               && ($cached_result['core_status'] ?? '') !== ''
               && ($cached_result['core_status'] ?? '') !== 'error';
        if ($prev_ok) {
            // Restore page vars from last good cache; show a soft warning on manual check.
            $core_status = $cached_result['core_status'];
            $core_update = $cached_result['core_update'] ?? null;
            $skin_info   = [
                'new_skins'           => $cached_result['new_skins']    ?? [],
                'updated_skins'       => $cached_result['updated_skins'] ?? [],
                'total_notifications' => $cached_result['skin_notifications'] ?? 0,
            ];
            if (!$auto_check) {
                $checked_date = date('M j', strtotime($cached_result['checked_at'] ?? 'now'));
                $flash_msg  = 'COULD NOT REACH UPDATE SERVER — SHOWING LAST KNOWN STATE FROM ' . strtoupper($checked_date) . '.';
                $flash_type = 'warning';
            }
        } else {
            $flash_msg  = 'COULD NOT REACH UPDATE SERVER. CHECK YOUR CONNECTION.';
            $flash_type = 'error';
        }
    } else {
        // Successful check — write to cache.
        $cached_result = [
            'checked_at'          => date('c'),
            'installed_version'   => $installed_version,
            'core_status'         => $core_status,
            'core_update'         => $core_update,
            'new_skins'           => $skin_info['new_skins'],
            'updated_skins'       => $skin_info['updated_skins'],
            'skin_notifications'  => $skin_info['total_notifications'],
            'total_notifications' => ($core_update ? 1 : 0) + $skin_info['total_notifications'],
        ];
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('update_check_result', ?)
                       ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
            ->execute([json_encode($cached_result, JSON_UNESCAPED_SLASHES)]);
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('last_update_check', ?)
                       ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
            ->execute([date('Y-m-d H:i:s')]);
        $last_check = date('Y-m-d H:i:s');

        if (!$auto_check) {
            if ($core_status === 'up_to_date' && $skin_info['total_notifications'] === 0) {
                $flash_msg  = 'SYSTEM IS UP TO DATE. NO NEW SKINS AVAILABLE.';
                $flash_type = 'success';
            } else {
                $notifications = [];
                if ($core_update) $notifications[] = "Core update available: v{$core_update['version']}";
                if (count($skin_info['new_skins'])    > 0) $notifications[] = count($skin_info['new_skins'])    . " new skin(s) available";
                if (count($skin_info['updated_skins']) > 0) $notifications[] = count($skin_info['updated_skins']) . " skin update(s) available";
                $flash_msg  = strtoupper(implode(' â ', $notifications));
                $flash_type = 'warning';
            }
        }
    }

    if ($wants_json) {
        header('Content-Type: application/json');
        $up_to_date = ($core_status === 'up_to_date' && $skin_info['total_notifications'] === 0);
        echo json_encode([
            'status'  => ($core_status === 'error') ? 'error' : 'ok',
            'stage'   => $up_to_date ? 'up_to_date' : 'review',
            'message' => $flash_msg,
            'data'    => [
                'up_to_date'     => $up_to_date,
                'version'        => $core_update['version']       ?? null,
                'version_full'   => $core_update['version_full']  ?? null,
                'codename'       => $core_update['codename']      ?? null,
                'released'       => $core_update['released']      ?? null,
                'changelog'      => $core_update['changelog']     ?? [],
                'file_changes'   => $core_update['file_changes']  ?? [],
                'schema_changes' => $core_update['schema_changes'] ?? false,
                'download_size'  => $core_update['download_size'] ?? 0,
                'csrf_token'     => $csrf,
            ],
        ]);
        exit;
    }
}

// ── STAGED UPDATE: CANCEL ─────────────────────────────────────────────────────
if ($action === 'cancel_update') {
    if (!empty($stage_state['zip_path'])) @unlink($stage_state['zip_path']);
    updater_release_lock(); // no-op if lock doesn't exist; safe to call always
    unset($_SESSION['update_state'], $_SESSION['update_complete_log'], $_SESSION['update_chunk_state']);
    $stage_state = null;
    $flash_msg   = 'UPDATE CANCELLED.';
    $flash_type  = 'info';
}

// ── RESET UPDATE STATE ────────────────────────────────────────────────────────
// Clears all cached update state from session and snap_settings so the update
// check runs fresh. Resets installed_version to match the running constants.php
// value, which is the ground truth on shared hosts where the update can fail
// after file extraction but before finalisation.
// ── REPAIR SIGNING KEY ────────────────────────────────────────────────────────
// ── ACCEPT ROOT-KEY-SIGNED KEY ROTATION ───────────────────────────────────────────
if ($action === 'accept_key_rotation') {
    $rotation     = $_SESSION['pending_key_rotation'] ?? null;
    $repair_error = '';
    if ($rotation && !empty($rotation['new_pubkey']) && updater_repair_pubkey($rotation['new_pubkey'], $repair_error)) {
        unset($_SESSION['pending_key_rotation'],
              $_SESSION['update_state'], $_SESSION['update_complete_log'],
              $_SESSION['update_chunk_state']);
        $stage_state = null;
        $flash_msg  = 'SIGNING KEY UPDATED VIA ROOT-KEY-SIGNED ROTATION. RESET STATE AND CHECK FOR UPDATES TO CONTINUE.';
        $flash_type = 'success';
    } else {
        $flash_msg  = 'KEY ROTATION FAILED: ' . strtoupper($repair_error ?: 'NO VALID ROTATION DATA IN SESSION.');
        $flash_type = 'error';
    }
}

if ($action === 'repair_pubkey') {
    $new_key = trim($_POST['new_pubkey'] ?? '');
    $repair_error = '';
    if (updater_repair_pubkey($new_key, $repair_error)) {
        // Reset update state so the page re-downloads and verifies with new key
        unset($_SESSION['update_state'], $_SESSION['update_complete_log'],
              $_SESSION['update_chunk_state']);
        $stage_state = null;
        $flash_msg  = 'SIGNING KEY UPDATED. RESET UPDATE STATE AND TRY AGAIN.';
        $flash_type = 'success';
    } else {
        $flash_msg  = 'KEY UPDATE FAILED: ' . strtoupper($repair_error);
        $flash_type = 'error';
    }
}

if ($action === 'reset_update_state') {
    // Clear session
    unset($_SESSION['update_state'], $_SESSION['update_complete_log'],
          $_SESSION['update_chunk_state'], $_SESSION['update_backup_file']);
    $stage_state = null;

    // Clear cached check results
    $pdo->exec("DELETE FROM snap_settings WHERE setting_key IN ('update_check_result', 'last_update_check', 'installed_checksum')");

    // Reset installed_version to what constants.php says — the protected file
    // is the reliable source of truth when an update has partially applied.
    $canonical = SNAPSMACK_VERSION_SHORT;
    $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('installed_version', ?)
                           ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)");
    $stmt->execute([$canonical]);
    $installed_version = $canonical;

    $flash_msg  = 'UPDATE STATE RESET. INSTALLED VERSION SET TO ' . $canonical . '.';
    $flash_type = 'success';
}

// ── STAGED UPDATE: STAGE 1 — DOWNLOAD ────────────────────────────────────────
// Prefer the cached check result (normal update flow). Fall back to the session
// update data set by the reapply action so the APPLY button works after reapply
// even when no update notification is cached in snap_settings.
$_stage_download_update = $cached_result['core_update'] ?? null;
if (empty($_stage_download_update) && !empty($_SESSION['update_state']['update'])) {
    $_stage_download_update = $_SESSION['update_state']['update'];
}
if ($action === 'stage_download' && !empty($_stage_download_update)) {
    $update       = $_stage_download_update;
    $required_php = $update['requires_php'] ?? '8.0';

    if (version_compare(PHP_VERSION, $required_php, '<')) {
        $flash_msg  = "UPDATE REQUIRES PHP {$required_php}+. YOU ARE RUNNING " . PHP_VERSION . ".";
        $flash_type = 'error';
    } else {
        $dl_error     = '';
        $download_url = $update['download_url'] ?? '';
        if ($download_url === '') {
            $flash_msg  = 'NO DOWNLOAD URL — RUN "CHECK FOR UPDATES" AGAIN.';
            $flash_type = 'error';
        } else {
            $zip_path = updater_download($download_url, $dl_error);
            if ($zip_path === false) {
                $flash_msg  = "DOWNLOAD FAILED: {$dl_error}";
                $flash_type = 'error';
                if ($wants_json) {
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'stage' => 'download', 'message' => $flash_msg]);
                    exit;
                }
            } else {
                $size_mb = number_format(filesize($zip_path) / 1048576, 1);
                $_SESSION['update_state'] = [
                    'stage'    => 'downloaded',
                    'zip_path' => $zip_path,
                    'update'   => $update,
                    'log'      => [['label' => 'Package downloaded', 'status' => 'ok', 'detail' => "{$size_mb} MB"]],
                ];
                $stage_state = $_SESSION['update_state'];
                if ($wants_json) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'status'  => 'ok',
                        'stage'   => 'download',
                        'message' => 'Package downloaded.',
                        'data'    => ['bytes' => filesize($zip_path), 'filename' => basename($zip_path)],
                    ]);
                    exit;
                }
            }
        }
    }
}

// ── STAGED UPDATE: STAGE 2 — VERIFY ──────────────────────────────────────────
if ($action === 'stage_verify'
    && !empty($stage_state)
    && ($stage_state['stage'] ?? '') === 'downloaded'
) {
    $update   = $stage_state['update'];
    $zip_path = $stage_state['zip_path'];
    $verify_error = '';

    if (!updater_verify_package($zip_path, $update['checksum_sha256'] ?? '', $update['signature'] ?? '', $verify_error)) {
        $flash_msg  = "VERIFICATION FAILED: {$verify_error}";
        $flash_type = 'error';
        @unlink($zip_path);
        unset($_SESSION['update_state']);
        $stage_state = null;
        // If signature failed, check for a root-key-signed rotation announcement
        if (stripos($verify_error, 'signature') !== false) {
            $rotation = updater_fetch_key_rotation();
            if ($rotation) {
                $_SESSION['pending_key_rotation'] = $rotation;
            } else {
                unset($_SESSION['pending_key_rotation']);
            }
        }
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'stage' => 'verify', 'message' => $flash_msg]);
            exit;
        }
    } else {
        $_SESSION['update_state']['stage'] = 'verified';
        $_SESSION['update_state']['log'][] = ['label' => 'Signature verified', 'status' => 'ok', 'detail' => 'SHA-256 + Ed25519 OK'];
        $stage_state = $_SESSION['update_state'];
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode([
                'status'  => 'ok',
                'stage'   => 'verify',
                'message' => 'Signature verified.',
                'data'    => ['checksum_ok' => true, 'signature_ok' => true],
            ]);
            exit;
        }
    }
}

// ── STAGED UPDATE: STAGE 3 — BACKUP ──────────────────────────────────────────
if ($action === 'stage_backup'
    && !empty($stage_state)
    && ($stage_state['stage'] ?? '') === 'verified'
) {
    $backup_error = '';
    $backup_file  = updater_create_backup($backup_error);

    if ($backup_file === false) {
        $flash_msg  = "BACKUP FAILED: {$backup_error}. UPDATE ABORTED.";
        $flash_type = 'error';
        @unlink($stage_state['zip_path'] ?? '');
        unset($_SESSION['update_state']);
        $stage_state = null;
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'stage' => 'backup', 'message' => $flash_msg]);
            exit;
        }
    } else {
        $_SESSION['update_state']['stage']       = 'backed_up';
        $_SESSION['update_state']['backup_file'] = $backup_file;
        $_SESSION['update_backup_file']          = $backup_file; // keep for rollback button
        $_SESSION['update_state']['log'][]       = ['label' => 'Backup created', 'status' => 'ok', 'detail' => basename($backup_file)];
        $stage_state = $_SESSION['update_state'];
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode([
                'status'  => 'ok',
                'stage'   => 'backup',
                'message' => 'Backup created.',
                'data'    => ['backup_path' => basename($backup_file)],
            ]);
            exit;
        }
    }
}

// ── STAGED UPDATE: STAGE 5 — PRE-MIGRATE ────────────────────────────────────
//
// Extract migrations/ from the zip and run pending migrations against the live
// DB BEFORE any PHP files are overwritten. This prevents PDO 500 errors when
// new code references tables or columns that the migration would have added.
if ($action === 'stage_premigrate'
    && !empty($stage_state)
    && ($stage_state['stage'] ?? '') === 'backed_up'
) {
    $zip_path    = $stage_state['zip_path'] ?? '';
    $mig_extract = updater_extract_migrations_only($zip_path);

    foreach ($mig_extract['errors'] as $e) {
        $_SESSION['update_state']['log'][] = ['label' => 'Migration extract warning', 'status' => 'warn', 'detail' => $e];
    }

    if (!$mig_extract['success']) {
        $rb_error = '';
        updater_rollback($stage_state['backup_file'] ?? '', $rb_error);
        $flash_msg  = 'FAILED TO STAGE MIGRATION FILES. SYSTEM ROLLED BACK.';
        $flash_type = 'error';
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'stage' => 'premigrate', 'message' => $flash_msg, 'data' => ['rolled_back' => true]]);
            exit;
        }
    } else {
        $_SESSION['update_state']['log'][] = [
            'label'  => 'Migration files staged',
            'status' => 'ok',
            'detail' => $mig_extract['count'] . ' file(s) extracted from package',
        ];

        $migrations     = updater_find_migrations($pdo);
        $migrate_result = updater_run_migrations($pdo, $migrations);

        if (!$migrate_result['success']) {
            $rb_error = '';
            updater_rollback($stage_state['backup_file'] ?? '', $rb_error);
            $flash_msg  = 'PRE-MIGRATION FAILED — ROLLED BACK. Errors: ' . implode('; ', $migrate_result['errors']);
            $flash_type = 'error';
            $_SESSION['update_state']['log'][] = ['label' => 'Pre-migration failed — rolled back', 'status' => 'fail', 'detail' => implode('; ', $migrate_result['errors'])];
            $stage_state = $_SESSION['update_state'];
            if ($wants_json) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'stage' => 'premigrate', 'message' => $flash_msg, 'data' => ['rolled_back' => true]]);
                exit;
            }
        } else {
            $mig_detail = !empty($migrate_result['applied'])
                ? implode(', ', $migrate_result['applied'])
                : 'none pending';
            $_SESSION['update_state']['log'][] = [
                'label'  => 'Schema + migrations (pre-extract)',
                'status' => 'ok',
                'detail' => $mig_detail,
            ];
            $_SESSION['update_state']['stage'] = 'premigrated';
            $stage_state = $_SESSION['update_state'];
            if ($wants_json) {
                header('Content-Type: application/json');
                echo json_encode([
                    'status'  => 'ok',
                    'stage'   => 'premigrate',
                    'message' => 'Database patched. Ready to extract files.',
                    'data'    => ['migrations_applied' => count($migrate_result['applied'] ?? [])],
                ]);
                exit;
            }
        }
    }
}

// ── STAGED UPDATE: STAGE 4 — EXTRACT (initialises chunked extraction) ─────────
//
// Rather than extracting the entire zip in one request (which times out on
// shared hosts with large font files), we initialise chunk state here and
// immediately redirect to the GET chunk handler.  The chunk handler processes
// the zip in ≤12-second bursts, auto-continuing via meta-refresh, until all
// files are extracted — then it redirects back here with stage = 'extracted'.
if ($action === 'stage_extract'
    && !empty($stage_state)
    && ($stage_state['stage'] ?? '') === 'premigrated'
) {
    // Acquire maintenance lock before first extraction chunk.
    // Public requests will 503 until updater_release_lock() is called.
    updater_acquire_lock($pdo);

    $_SESSION['update_chunk_state'] = [
        'zip_path'      => $stage_state['zip_path'],
        'offset'        => 0,
        'files_updated' => 0,
        'files_skipped' => 0,
        'errors'        => [],
        'total'         => 0,
    ];
    if ($wants_json) {
        // XHR path: don't redirect — JS will poll stage_extract_chunk itself
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'stage' => 'extract', 'message' => 'Extraction initialised.', 'data' => ['polling' => true]]);
        exit;
    }
    header('Location: smack-update.php?action=stage_extract_chunk');
    exit;
}

// ── STAGED UPDATE: STAGE 5 — MIGRATE + FINALIZE ──────────────────────────────
if ($action === 'stage_migrate'
    && !empty($stage_state)
    && ($stage_state['stage'] ?? '') === 'extracted'
) {
    $update     = $stage_state['update'];
    $migrations = updater_find_migrations($pdo);

    // Always run migrations — even with no .sql files, updater_run_migrations()
    // opens with a canonical schema diff that creates any missing tables or columns.
    // Skipping this when $migrations is empty was causing new tables (e.g.
    // snap_ohsnap_keys) to never be created on installs that had no pending SQL files.
    $migrate_result = updater_run_migrations($pdo, $migrations);

    if (!$migrate_result['success']) {
        $rb_error = '';
        updater_rollback($stage_state['backup_file'] ?? '', $rb_error);
        $flash_msg  = 'MIGRATION FAILED. SYSTEM ROLLED BACK. Errors: ' . implode('; ', $migrate_result['errors']);
        $flash_type = 'error';
        $_SESSION['update_state']['log'][] = ['label' => 'Migrations failed — rolled back', 'status' => 'fail', 'detail' => implode('; ', $migrate_result['errors'])];
        $stage_state = $_SESSION['update_state'];
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode([
                'status'  => 'error',
                'stage'   => 'migrate',
                'message' => $flash_msg,
                'data'    => ['rollback_available' => false, 'rolled_back' => true],
            ]);
            exit;
        }
    } else {
        $schema_detail = !empty($migrate_result['schema']['created'])
            ? 'Tables created: ' . implode(', ', $migrate_result['schema']['created'])
            : '';
        $mig_detail = !empty($migrate_result['applied'])
            ? implode(', ', $migrate_result['applied'])
            : 'none';
        $_SESSION['update_state']['log'][] = [
            'label'  => 'Schema sync + migrations',
            'status' => 'ok',
            'detail' => trim("Schema: {$schema_detail} | SQL files: {$mig_detail}", ' |'),
        ];
    }

    if ($flash_type !== 'error') {
        updater_set_version($pdo, $update['version'], $update['version_full'] ?? "Alpha {$update['version']}", $update['codename'] ?? '', $update['checksum_sha256'] ?? '');
        $_SESSION['update_state']['log'][] = ['label' => 'Version updated', 'status' => 'ok', 'detail' => "v{$installed_version} → v{$update['version']}"];

        // Remove files that were deleted from the distribution in this or any earlier release
        $dep_result = updater_remove_deprecated_files($update['version']);
        if (!empty($dep_result['removed'])) {
            $_SESSION['update_state']['log'][] = ['label' => 'Orphan cleanup', 'status' => 'ok', 'detail' => 'Removed: ' . implode(', ', $dep_result['removed'])];
        }
        if (!empty($dep_result['failed'])) {
            $_SESSION['update_state']['log'][] = ['label' => 'Orphan cleanup', 'status' => 'warn', 'detail' => 'Could not remove: ' . implode(', ', $dep_result['failed'])];
        }

        $pdo->exec("DELETE FROM snap_settings WHERE setting_key = 'update_check_result'");

        // SMACKBACK: refresh file integrity manifest before cleanup deletes the ZIP
        $smack_zip = $stage_state['zip_path'] ?? '';
        if ($smack_zip && file_exists($smack_zip)) {
            require_once __DIR__ . '/core/smackback.php';
            $smack_ok = smackback_init_manifest($smack_zip);
            $_SESSION['update_state']['log'][] = [
                'label'  => 'SMACKBACK manifest',
                'status' => $smack_ok ? 'ok' : 'warn',
                'detail' => $smack_ok ? 'File hashes refreshed from update package' : 'Manifest not in package — skipped',
            ];
        }

        updater_cleanup();
        updater_prune_backups(3);

        // Asset sync: fetch any fonts or JS engines missing after the update.
        require_once __DIR__ . '/core/asset-sync.php';
        asset_sync_bust_cache(); // discard cached manifest — new release may add assets
        $sync_msg = asset_sync_run();
        if ($sync_msg !== null) {
            $_SESSION['update_state']['log'][] = ['label' => 'Asset sync', 'status' => 'ok', 'detail' => $sync_msg];
        }

        // Backfill color_family for any pre-existing hex-colour tags.
        require_once __DIR__ . '/core/snap-tags.php';
        $backfilled = snap_backfill_color_families($pdo);
        if ($backfilled > 0) {
            $_SESSION['update_state']['log'][] = ['label' => 'Colour tag backfill', 'status' => 'ok', 'detail' => "{$backfilled} hex tag(s) classified"];
        }

        // Repair .htaccess — rebuild SnapSmack block from canonical template so
        // named routes and security rules are always current after an update.
        $htaccess_path  = __DIR__ . '/.htaccess';
        $template_path  = __DIR__ . '/core/htaccess-template';
        $htaccess_marker = '# SNAPSMACK-HTACCESS-RULES';
        if (file_exists($template_path)) {
            $template_rules = file_get_contents($template_path);
            if ($template_rules !== false) {
                $existing = file_exists($htaccess_path) ? file_get_contents($htaccess_path) : '';
                // Strip old SnapSmack block if present
                $marker_pos = strpos($existing, $htaccess_marker);
                $before = $marker_pos !== false ? rtrim(substr($existing, 0, $marker_pos)) . "\n" : $existing;
                $new_htaccess = ltrim($before) . $template_rules . "\n";
                if (file_put_contents($htaccess_path, $new_htaccess) !== false) {
                    $_SESSION['update_state']['log'][] = ['label' => '.htaccess repair', 'status' => 'ok', 'detail' => 'SnapSmack rules rebuilt from core/htaccess-template'];
                } else {
                    $_SESSION['update_state']['log'][] = ['label' => '.htaccess repair', 'status' => 'warn', 'detail' => 'Could not write .htaccess — check file permissions'];
                }
            }
        } else {
            $_SESSION['update_state']['log'][] = ['label' => '.htaccess repair', 'status' => 'warn', 'detail' => 'core/htaccess-template missing — skipped'];
        }

        // Store log for display after session clear
        $_SESSION['update_complete_log'] = $_SESSION['update_state']['log'];
        unset($_SESSION['update_state']);
        $stage_state = null;

        $flash_msg  = "UPDATE COMPLETE. NOW RUNNING v{$update['version']}.";
        $flash_type = 'success';
        $cached_result = null;

        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode([
                'status'  => 'ok',
                'stage'   => 'done',
                'message' => $flash_msg,
                'data'    => [
                    'new_version'      => $update['version'],
                    'new_version_full' => $update['version_full'] ?? "Alpha {$update['version']}",
                    'migrations_run'   => count($migrate_result['applied'] ?? []),
                    'migrations_skipped' => count($migrate_result['skipped'] ?? []),
                ],
            ]);
            exit;
        }
    }
}

// ── ACTION: MANUAL ZIP UPLOAD ─────────────────────────────────────────────────
$update_result = null;
if ($action === 'upload_zip' && !empty($_FILES['update_zip']['tmp_name'])) {
    $upload_steps = [];
    $upload_file  = $_FILES['update_zip']['tmp_name'];
    $upload_name  = $_FILES['update_zip']['name'] ?? 'unknown.zip';

    if (!preg_match('/\.zip$/i', $upload_name)) {
        $flash_msg  = 'UPLOADED FILE IS NOT A ZIP ARCHIVE.';
        $flash_type = 'error';
    } else {
        if (!is_dir(UPDATER_TEMP_DIR)) mkdir(UPDATER_TEMP_DIR, 0700, true);
        $dest = UPDATER_TEMP_DIR . '/snapsmack-upload.zip';
        move_uploaded_file($upload_file, $dest);
        $upload_steps[] = ['label' => 'Upload received', 'status' => 'ok', 'detail' => $upload_name];

        $expected_checksum  = $cached_result['core_update']['checksum_sha256'] ?? '';
        $expected_signature = $cached_result['core_update']['signature']       ?? '';
        $target_version     = $cached_result['core_update']['version']         ?? '';
        $target_version_full = $cached_result['core_update']['version_full']   ?? '';

        $verified = true;
        if ($expected_checksum) {
            $verify_error = '';
            if (!updater_verify_package($dest, $expected_checksum, $expected_signature, $verify_error)) {
                $verified       = false;
                $upload_steps[] = ['label' => 'Verification', 'status' => 'fail', 'detail' => $verify_error];
                $flash_msg      = "VERIFICATION FAILED: {$verify_error}";
                $flash_type     = 'error';
                updater_cleanup();
            } else {
                $upload_steps[] = ['label' => 'Verification passed', 'status' => 'ok', 'detail' => 'Checksum + signature OK'];
            }
        } else {
            $upload_steps[] = ['label' => 'Verification skipped', 'status' => 'ok', 'detail' => 'No cached manifest to verify against'];
        }

        if ($verified) {
            $backup_error = '';
            $backup_file  = updater_create_backup($backup_error);
            if ($backup_file === false) {
                $flash_msg      = "BACKUP FAILED: {$backup_error}. UPDATE ABORTED.";
                $flash_type     = 'error';
                $upload_steps[] = ['label' => 'Backup', 'status' => 'fail', 'detail' => $backup_error];
                updater_cleanup();
            } else {
                $upload_steps[]                  = ['label' => 'Backup created', 'status' => 'ok', 'detail' => basename($backup_file)];
                $_SESSION['update_backup_file']  = $backup_file;

                $extract        = updater_extract($dest);
                $upload_steps[] = [
                    'label'  => 'Extraction',
                    'status' => $extract['success'] ? 'ok' : 'fail',
                    'detail' => "{$extract['files_updated']} updated, {$extract['files_skipped']} protected"
                ];

                if (!$extract['success']) {
                    $flash_msg  = 'EXTRACTION FAILED. ERRORS: ' . implode('; ', $extract['errors']);
                    $flash_type = 'error';
                } else {
                    // Files are on disk. Save pending state and redirect so that
                    // the migration step runs in a fresh request — loading the
                    // newly extracted updater code rather than the old copy that
                    // is still in memory for this request.
                    $_SESSION['upload_migrate_pending'] = [
                        'target_version'      => $target_version,
                        'target_version_full' => $target_version_full,
                        'target_codename'     => $cached_result['core_update']['codename'] ?? '',
                        'backup_file'         => $backup_file,
                        'installed_version'   => $installed_version,
                        'log'                 => $upload_steps,
                    ];
                    header('Location: smack-update.php?action=stage_migrate_upload');
                    exit;
                }
            }
        }
    }
    $update_result = $upload_steps;
}

// ── ACTION: MIGRATE + FINALIZE AFTER MANUAL ZIP UPLOAD ───────────────────────
// Runs in a fresh request AFTER upload_zip has extracted the package, so the
// newly written updater code is loaded rather than the pre-update copy.
if ($action === 'stage_migrate_upload' && !empty($_SESSION['upload_migrate_pending'])) {
    $pending             = $_SESSION['upload_migrate_pending'];
    unset($_SESSION['upload_migrate_pending']);
    $target_version      = $pending['target_version']      ?? '';
    $target_version_full = $pending['target_version_full'] ?? '';
    $target_codename     = $pending['target_codename']     ?? '';
    $backup_file         = $pending['backup_file']         ?? '';
    $installed_version   = $pending['installed_version']   ?? '';
    $upload_steps        = $pending['log']                 ?? [];

    $migration_ok = true;

    $migrations = updater_find_migrations($pdo);
    if (!empty($migrations)) {
        $migrate_result = updater_run_migrations($pdo, $migrations);
        $upload_steps[] = [
            'label'  => 'Schema migrations',
            'status' => $migrate_result['success'] ? 'ok' : 'fail',
            'detail' => implode(', ', $migrate_result['applied']),
        ];
        if (!$migrate_result['success']) {
            $migration_ok = false;
            $rb_error = '';
            updater_rollback($backup_file, $rb_error);
            $flash_msg  = 'MIGRATION FAILED. SYSTEM ROLLED BACK.';
            $flash_type = 'error';
            $upload_steps[] = ['label' => 'Rollback', 'status' => 'ok', 'detail' => 'Restored from backup'];
            updater_cleanup();
        }
    }

    if ($migration_ok) {
        if ($target_version) {
            updater_set_version($pdo, $target_version, $target_version_full ?: "Alpha {$target_version}", $target_codename);
            $upload_steps[] = ['label' => 'Version updated', 'status' => 'ok', 'detail' => "v{$installed_version} → v{$target_version}"];
        }

        // Remove files that were deleted from the distribution in this or any earlier release
        if ($target_version) {
            $dep_result = updater_remove_deprecated_files($target_version);
            if (!empty($dep_result['removed'])) {
                $upload_steps[] = ['label' => 'Orphan cleanup', 'status' => 'ok', 'detail' => 'Removed: ' . implode(', ', $dep_result['removed'])];
            }
            if (!empty($dep_result['failed'])) {
                $upload_steps[] = ['label' => 'Orphan cleanup', 'status' => 'warn', 'detail' => 'Could not remove: ' . implode(', ', $dep_result['failed'])];
            }
        }

        $pdo->exec("DELETE FROM snap_settings WHERE setting_key = 'update_check_result'");

        // SMACKBACK: refresh file integrity manifest before cleanup (upload path)
        $smack_upload_zip = UPDATER_TEMP_DIR . '/snapsmack-upload.zip';
        if (file_exists($smack_upload_zip)) {
            if (!function_exists('smackback_init_manifest')) {
                require_once __DIR__ . '/core/smackback.php';
            }
            $smack_ok = smackback_init_manifest($smack_upload_zip);
            $upload_steps[] = [
                'label'  => 'SMACKBACK manifest',
                'status' => $smack_ok ? 'ok' : 'warn',
                'detail' => $smack_ok ? 'File hashes refreshed from uploaded package' : 'Manifest not in package — skipped',
            ];
        }

        updater_cleanup();
        updater_prune_backups(3);

        require_once __DIR__ . '/core/asset-sync.php';
        asset_sync_bust_cache();
        $sync_msg = asset_sync_run();
        if ($sync_msg !== null) {
            $upload_steps[] = ['label' => 'Asset sync', 'status' => 'ok', 'detail' => $sync_msg];
        }

        require_once __DIR__ . '/core/snap-tags.php';
        $backfilled = snap_backfill_color_families($pdo);
        if ($backfilled > 0) {
            $upload_steps[] = ['label' => 'Colour tag backfill', 'status' => 'ok', 'detail' => "{$backfilled} hex tag(s) classified"];
        }

        $flash_msg  = $target_version ? "UPDATE COMPLETE VIA UPLOAD. NOW RUNNING v{$target_version}." : "PACKAGE EXTRACTED SUCCESSFULLY.";
        $flash_type = 'success';
    }

    $update_result = $upload_steps;
}

// ── ACTION: CRON REGISTRATION ─────────────────────────────────────────────────
if (($action === 'cron_register' || $action === 'cron_remove') && $cron_supported) {
    $script_path = realpath(__DIR__ . '/cron-version-check.php');
    $cron_line   = "0 */6 * * * {$php_cli_path} {$script_path} >> /dev/null 2>&1";
    $tag         = '# snapsmack-version-check';
    $full_entry  = "{$cron_line} {$tag}";

    exec('crontab -l 2>&1', $current_cron, $rc);
    $current_cron_str = ($rc === 0) ? implode("\n", $current_cron) : '';

    if ($action === 'cron_register') {
        if (strpos($current_cron_str, $tag) === false) {
            $new_cron = trim($current_cron_str) . "\n" . $full_entry . "\n";
            $tmp = tempnam(sys_get_temp_dir(), 'ssck');
            file_put_contents($tmp, $new_cron);
            exec("crontab {$tmp} 2>&1", $out, $ret);
            unlink($tmp);
            $flash_msg  = ($ret === 0) ? 'VERSION CHECK JOB REGISTERED. RUNS EVERY 6 HOURS.' : 'FAILED TO REGISTER: ' . implode(' ', $out);
            $flash_type = ($ret === 0) ? 'success' : 'error';
        } else {
            $flash_msg  = 'JOB ALREADY REGISTERED.';
            $flash_type = 'success';
        }
    } elseif ($action === 'cron_remove') {
        $cleaned = preg_replace('/.*' . preg_quote($tag, '/') . '.*\n?/', '', $current_cron_str);
        $tmp = tempnam(sys_get_temp_dir(), 'ssck');
        file_put_contents($tmp, trim($cleaned) . "\n");
        exec("crontab {$tmp} 2>&1", $out, $ret);
        unlink($tmp);
        $flash_msg  = ($ret === 0) ? 'VERSION CHECK JOB REMOVED.' : 'FAILED TO REMOVE: ' . implode(' ', $out);
        $flash_type = ($ret === 0) ? 'success' : 'error';
    }
}

$version_job_registered = false;
if ($cron_supported) {
    exec('crontab -l 2>&1', $vc_cron, $vc_rc);
    $version_job_registered = ($vc_rc === 0 && strpos(implode("\n", $vc_cron), '# snapsmack-version-check') !== false);
}

// ── ACTION: ROLLBACK ──────────────────────────────────────────────────────────
if ($action === 'rollback' && !empty($_SESSION['update_backup_file'])) {
    $rb_error = '';
    $ok = updater_rollback($_SESSION['update_backup_file'], $rb_error);
    if ($ok) {
        $flash_msg  = 'ROLLBACK COMPLETE. PREVIOUS VERSION RESTORED.';
        $flash_type = 'success';
        unset($_SESSION['update_backup_file']);
    } else {
        $flash_msg  = "ROLLBACK FAILED: {$rb_error}";
        $flash_type = 'error';
    }
}

// ── ACTION: SCHEMA RESYNC ─────────────────────────────────────────────────────
// Diffs the live database against snapsmack_canonical.sql and applies anything
// missing. Uses the same canonical-diff pipeline as the auto-updater.
if ($action === 'schema_resync') {
    $diff  = updater_canonical_diff($pdo);
    $apply = isset($diff['error']) ? ['created' => [], 'columns_added' => [], 'errors' => [$diff['error']]] : updater_apply_canonical_diff($pdo, $diff);
    $summary = [];
    if (!empty($apply['created']))       $summary[] = count($apply['created'])       . ' table(s) created';
    if (!empty($apply['columns_added'])) $summary[] = count($apply['columns_added']) . ' column(s) added';
    if (!empty($apply['errors']))        $summary[] = count($apply['errors'])         . ' error(s)';
    $_SESSION['schema_resync_result'] = $apply;
    $flash_msg  = 'SCHEMA SYNC COMPLETE: ' . (implode(', ', $summary) ?: 'nothing to do') . '.';
    $flash_type = empty($apply['errors']) ? 'success' : 'warning';
}

// ── ACTION: CANONICAL SCHEMA DIFF ────────────────────────────────────────────
// Fetches the canonical SQL from the release server (or falls back to on-disk)
// and diffs it against the live database. Results stored in session for display.
if ($action === 'canonical_diff') {
    // If the cached update check pre-dates canonical_schema_url being added to
    // latest.json (or there is no cached check at all), fetch latest.json now
    // rather than forcing the user to run a check-for-updates first.
    if (empty($cached_result['canonical_schema_url'])) {
        $fresh_manifest = _updater_http_get(UPDATER_API_URL);
        if ($fresh_manifest !== false) {
            $decoded = json_decode($fresh_manifest, true);
            if (is_array($decoded)) {
                $cached_result = $decoded;
            }
        }
    }
    $canonical_url     = $cached_result['canonical_schema_url'] ?? '';
    $canonical_sig_url = $cached_result['canonical_schema_sig'] ?? '';
    $diff = updater_canonical_diff($pdo, $canonical_url, $canonical_sig_url);
    if (!empty($diff['error'])) {
        $flash_msg  = 'CANONICAL DIFF FAILED: ' . strtoupper($diff['error']);
        $flash_type = 'error';
    } else {
        unset($diff['raw_sql']); // Don't bloat the session — re-fetched on apply
        $_SESSION['canonical_diff_result'] = $diff;
        $missing = count($diff['missing_tables']) + count($diff['missing_columns']);
        $flash_msg  = $diff['all_ok']
            ? 'CANONICAL DIFF: DATABASE IS IN SYNC WITH ' . strtoupper($diff['source']) . ' SCHEMA. NOTHING MISSING.'
            : "CANONICAL DIFF: {$missing} ITEM(S) MISSING — SEE BELOW.";
        $flash_type = $diff['all_ok'] ? 'success' : 'warning';
    }
}

// ── ACTION: APPLY CANONICAL DIFF ─────────────────────────────────────────────
if ($action === 'apply_canonical_diff') {
    $stored_diff = $_SESSION['canonical_diff_result'] ?? null;
    if (!$stored_diff) {
        $flash_msg  = 'NO DIFF RESULT IN SESSION — RUN CHECK FIRST.';
        $flash_type = 'error';
    } else {
        // Re-fetch canonical SQL for the apply step (not stored in session).
        // Also resolve URL from latest.json if the cached result is stale.
        if (empty($cached_result['canonical_schema_url'])) {
            $fresh_manifest = _updater_http_get(UPDATER_API_URL);
            if ($fresh_manifest !== false) {
                $decoded = json_decode($fresh_manifest, true);
                if (is_array($decoded)) {
                    $cached_result = $decoded;
                }
            }
        }
        $canonical_url     = $cached_result['canonical_schema_url'] ?? '';
        $canonical_sig_url = $cached_result['canonical_schema_sig'] ?? '';
        $fresh_diff        = updater_canonical_diff($pdo, $canonical_url, $canonical_sig_url);
        if (!empty($fresh_diff['error'])) {
            $flash_msg  = 'COULD NOT FETCH CANONICAL SQL FOR APPLY: ' . strtoupper($fresh_diff['error']);
            $flash_type = 'error';
        } else {
            // Merge stored diff structure with fresh raw_sql
            $stored_diff['raw_sql'] = $fresh_diff['raw_sql'];
            $apply = updater_apply_canonical_diff($pdo, $stored_diff);
            unset($_SESSION['canonical_diff_result']);
            $parts = [];
            if (!empty($apply['created']))       $parts[] = count($apply['created'])       . ' table(s) created';
            if (!empty($apply['columns_added'])) $parts[] = count($apply['columns_added']) . ' column(s) added';
            if (!empty($apply['errors']))        $parts[] = count($apply['errors'])        . ' error(s)';
            $_SESSION['canonical_apply_result'] = $apply;
            $flash_msg  = 'CANONICAL SCHEMA APPLIED: ' . (implode(', ', $parts) ?: 'nothing to do') . '.';
            $flash_type = empty($apply['errors']) ? 'success' : 'warning';
        }
    }
}

// ── ACTION: MARK ALL MIGRATIONS APPLIED ──────────────────────────────────────
// Records every known migration in snap_migrations without executing its SQL.
// Use after manually applying a recovery SQL file so the updater sees a clean
// migration state.
if ($action === 'mark_migrations_applied') {
    try {
        $ps = $pdo->query("
            CREATE TABLE IF NOT EXISTS snap_migrations (
                migration  VARCHAR(100) NOT NULL,
                applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        if ($ps !== false) $ps->closeCursor();

        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO snap_migrations (migration, applied_at) VALUES (?, NOW())"
        );
        $marked = 0;
        foreach (UPDATER_KNOWN_MIGRATIONS as $name) {
            $stmt->execute([$name]);
            if ($stmt->rowCount() > 0) $marked++;
        }
        $flash_msg  = $marked > 0
            ? "MARKED {$marked} MIGRATION(S) AS APPLIED. DATABASE STATE IS NOW CLEAN."
            : 'ALL MIGRATIONS WERE ALREADY RECORDED AS APPLIED. NOTHING CHANGED.';
        $flash_type = 'success';
    } catch (\PDOException $e) {
        $flash_msg  = 'FAILED TO MARK MIGRATIONS: ' . $e->getMessage();
        $flash_type = 'error';
    }
}

// ── ACTION: PURGE GHOST MIGRATION FILES ──────────────────────────────────────
// Deletes any .sql files in /migrations/ that are not in UPDATER_KNOWN_MIGRATIONS
// and have not been recorded in snap_migrations. Safe to run at any time.
if ($action === 'purge_ghosts') {
    $status  = updater_migration_status($pdo);
    $ghosts  = $status['ghosts'] ?? [];
    $deleted = 0;
    $failed  = [];
    foreach ($ghosts as $name) {
        $path = UPDATER_MIGRATIONS_DIR . '/' . $name;
        if (file_exists($path)) {
            if (@unlink($path)) {
                $deleted++;
            } else {
                $failed[] = $name;
            }
        }
    }
    if (!empty($failed)) {
        $flash_msg  = "PURGE INCOMPLETE: could not delete " . implode(', ', $failed) . ". Check file permissions.";
        $flash_type = 'warning';
    } else {
        $flash_msg  = $deleted > 0
            ? "PURGED {$deleted} GHOST FILE(S). MIGRATIONS DIRECTORY IS CLEAN."
            : 'NO GHOST FILES FOUND ON DISK. NOTHING TO DELETE.';
        $flash_type = 'success';
    }
}

// Retrieve completed update log (if we just finalized)
$complete_log = null;
if (!empty($_SESSION['update_complete_log'])) {
    $complete_log = $_SESSION['update_complete_log'];
    unset($_SESSION['update_complete_log']);
}

// --- PAGE RENDER ---
$page_title = "System Updates";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<style>
    .update-section { margin-bottom: 30px; }
    .version-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 3px;
        font-family: monospace;
        font-size: 0.9rem;
        font-weight: bold;
    }
    .version-current   { background: rgba(128,128,128,0.1); color: inherit; border: 1px solid rgba(128,128,128,0.3); }
    .version-available { background: rgba(128,128,128,0.08); color: inherit; opacity: 0.8; border: 1px solid rgba(128,128,128,0.25); }

    .changelog-list { margin: 15px 0; padding-left: 20px; list-style: disc; }
    .changelog-list li { margin-bottom: 6px; color: inherit; opacity: 0.85; }

    .file-changes { margin: 15px 0; }
    .file-changes h4 { margin-bottom: 8px; font-size: 0.8rem; letter-spacing: 1px; }
    .file-changes code { display: block; font-size: 0.75rem; padding: 2px 0; opacity: 0.75; }
    .file-added    { color: inherit; opacity: 0.9; }
    .file-modified { color: inherit; opacity: 0.7; }
    .file-removed  { color: inherit; opacity: 0.5; text-decoration: line-through; }

    .step-log { margin: 20px 0; }
    .step-row {
        display: flex; align-items: center; gap: 12px;
        padding: 8px 12px; margin-bottom: 4px;
        border-radius: 3px; font-family: monospace; font-size: 0.8rem;
    }
    .step-ok   { background: rgba(40, 120, 40, 0.15); }
    .step-fail { background: rgba(180, 40, 40, 0.15); }
    .step-icon  { font-size: 1.1rem; }
    .step-detail { opacity: 0.75; margin-left: auto; }

    .stage-box {
        border: 2px solid #39FF14;
        border-radius: 4px;
        padding: 20px;
        margin-bottom: 24px;
        background: rgba(57, 255, 20, 0.03);
    }
    .stage-box h3 { margin-bottom: 16px; }
    .stage-actions { display: flex; align-items: flex-start; gap: 12px; margin-top: 16px; }
    .stage-next-btn { display: block; }
    .stage-cancel { display: block; }

    .skin-notify-card {
        display: flex; justify-content: space-between; align-items: center;
        padding: 10px 15px; margin-bottom: 8px; border-radius: 3px;
        border-left: 3px solid #4a90d9; background: rgba(74, 144, 217, 0.08); font-size: 0.85rem;
    }
    .skin-notify-new    { border-left-color: rgba(128,128,128,0.5); background: rgba(128,128,128,0.06); }
    .skin-notify-update { border-left-color: #860; background: rgba(200,160,0,0.06); }
    .skin-notify-version { font-family: monospace; opacity: 0.75; }

    .update-warning {
        padding: 12px 18px; margin: 15px 0; border-radius: 3px;
        border-left: 4px solid #d94a4a; background: rgba(217, 74, 74, 0.08); font-size: 0.85rem;
    }
    .confirm-box {
        padding: 20px; margin: 20px 0; border: 2px solid #da4;
        border-radius: 4px; background: rgba(221, 170, 68, 0.05);
    }
    .confirm-box p { margin-bottom: 15px; }
    .confirm-box form { display: inline-block; margin-right: 12px; }
    .cron-info {
        margin-top: 20px; padding: 12px 18px; border-radius: 3px;
        background: rgba(255,255,255,0.03); font-family: monospace; font-size: 0.75rem; line-height: 1.6;
    }

    /* Schema Recovery panel */
    .recovery-table { width: 100%; border-collapse: collapse; font-family: monospace; font-size: 0.8rem; margin: 12px 0; }
    .recovery-table th { text-align: left; padding: 6px 10px; opacity: 0.5; font-size: 0.7rem; letter-spacing: 1px; border-bottom: 1px solid rgba(255,255,255,0.08); }
    .recovery-table td { padding: 5px 10px; border-bottom: 1px solid rgba(255,255,255,0.04); }
    .recovery-table tr:last-child td { border-bottom: none; }
    .migration-ok    { color: #5a5; }
    .migration-pending { opacity: 0.6; }
    .migration-ghost { color: #c74; }
    .recovery-actions { display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap; align-items: center; }
    .update-action-row { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
    .update-action-row form { flex: 1; min-width: 180px; }
    .update-action-row .btn-smack { margin-top: 0; width: 100%; }

    /* Status badge */
    .update-status-badge {
        font-size: 1rem; font-weight: bold; letter-spacing: 0.06em;
        padding: 14px 18px; border-radius: 4px; border-left: 4px solid;
    }
    .status-ok        { background: rgba(40,120,40,0.12);  color: #5c5; border-color: #5c5; }
    .status-available { background: rgba(200,160,0,0.1);   color: #da4; border-color: #da4; }
    .status-error     { background: rgba(180,40,40,0.12);  color: #d55; border-color: #d55; }

    /* Skin update button layout */
    .skin-update-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
    .skin-update-row form { margin: 0; }
    .btn-sm { padding: 6px 16px !important; font-size: 0.78rem !important; margin-top: 0 !important; }
    .skin-new-notice { font-size: 0.85rem; opacity: 0.75; display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }

    /* File picker button */
    .file-pick-btn {
        display: inline-flex; align-items: center; cursor: pointer;
        padding: 8px 18px; border: 1px solid rgba(255,255,255,0.2);
        border-radius: 3px; font-size: 0.78rem; letter-spacing: 0.08em;
        background: rgba(255,255,255,0.05); transition: background 0.15s, border-color 0.15s;
        user-select: none;
    }
    .file-pick-btn:hover { background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.35); }
    .file-pick-btn.has-file { border-color: rgba(100,200,100,0.5); color: #8d8; }

    /* Advanced panel */
    .update-advanced { margin-top: 0; }
    .update-advanced > summary {
        cursor: pointer; padding: 13px 20px;
        background: rgba(255,255,255,0.03); border: 1px dashed rgba(255,255,255,0.15);
        border-radius: 4px; font-size: 0.78rem; letter-spacing: 0.12em;
        opacity: 0.5; list-style: none; user-select: none; margin-bottom: 0;
        display: flex; align-items: center; gap: 10px;
    }
    .update-advanced > summary::-webkit-details-marker { display: none; }
    .update-advanced > summary::before { content: '\25B6'; font-size: 0.6rem; transition: transform 0.2s; }
    .update-advanced[open] > summary::before { content: '\25BC'; }
    .update-advanced > summary { gap: 8px; }
    .update-advanced > summary:hover { opacity: 0.8; background: rgba(255,255,255,0.05); }
    .update-advanced[open] > summary {
        margin-bottom: 0; border-radius: 4px 4px 0 0; opacity: 0.7;
        border-bottom-style: solid; border-bottom-color: rgba(255,255,255,0.08);
    }
</style>

<div class="main">
    <div class="header-row">
        <h2>SYSTEM UPDATES</h2>
    </div>

    <?php if ($flash_msg): ?>
    <div class="alert alert-<?php echo $flash_type === 'error' ? 'danger' : ($flash_type === 'warning' ? 'warning' : 'success'); ?> mb-25">
        &gt; <?php echo htmlspecialchars($flash_msg); ?>
    </div>
    <?php endif; ?>

    <?php
    // ── Pre-compute all values needed in this section ────────────────────────
    $sig_failure      = !empty($flash_msg) && stripos($flash_msg, 'signature') !== false && stripos($flash_msg, 'failed') !== false;
    $pending_rotation = $_SESSION['pending_key_rotation'] ?? null;

    $migration_status     = updater_migration_status($pdo);
    $schema_resync_result = null;
    if (!empty($_SESSION['schema_resync_result'])) {
        $schema_resync_result = $_SESSION['schema_resync_result'];
        unset($_SESSION['schema_resync_result']);
    }
    $has_ghosts  = !empty($migration_status['ghosts']);
    $has_pending = !empty($migration_status['pending']);
    $applied_map = [];
    foreach ($migration_status['applied'] as $row) {
        $applied_map[$row['migration']] = $row['applied_at'];
    }
    $pending_rows         = array_filter(UPDATER_KNOWN_MIGRATIONS, fn($n) => !isset($applied_map[$n]));
    $show_migration_table = !empty($pending_rows) || !empty($migration_status['ghosts']);

    $canonical_diff  = null;
    $canonical_apply = null;
    if (!empty($_SESSION['canonical_diff_result'])) {
        $canonical_diff = $_SESSION['canonical_diff_result'];
    }
    if (!empty($_SESSION['canonical_apply_result'])) {
        $canonical_apply = $_SESSION['canonical_apply_result'];
        unset($_SESSION['canonical_apply_result']);
    }
    $has_canonical_url = !empty($cached_result['canonical_schema_url']);
    $has_canonical_sig = !empty($cached_result['canonical_schema_sig']);
    ?>

    <!-- EMERGENCY: REPAIR SIGNING KEY — always outside Advanced when triggered -->
    <?php
    if ($sig_failure || $pending_rotation || isset($_GET['repair_key'])):
    ?>
    <div class="box update-section" id="repair-key-panel" style="border-color:#c00;">

        <?php if ($pending_rotation): ?>
        <h3 style="color:#fa0;">&#9888; KEY ROTATION DETECTED</h3>
        <p class="dim" style="font-size:0.8rem;margin-bottom:12px;">
            A key rotation announcement was fetched from <code>snapsmack.ca</code> and verified
            against the hardcoded root key. The new release signing key is shown below.
            Accept to update this install and continue.
        </p>
        <table style="font-size:0.78rem;margin-bottom:14px;width:100%;border-collapse:collapse;">
            <tr><td style="opacity:0.5;padding:3px 10px 3px 0;white-space:nowrap;">Issued</td>
                <td style="font-family:monospace;"><?php echo htmlspecialchars($pending_rotation['issued_at']); ?></td></tr>
            <?php if (!empty($pending_rotation['reason'])): ?>
            <tr><td style="opacity:0.5;padding:3px 10px 3px 0;">Reason</td>
                <td><?php echo htmlspecialchars($pending_rotation['reason']); ?></td></tr>
            <?php endif; ?>
            <tr><td style="opacity:0.5;padding:3px 10px 3px 0;">Old key</td>
                <td style="font-family:monospace;word-break:break-all;opacity:0.6;"><?php echo htmlspecialchars($pending_rotation['old_pubkey']); ?></td></tr>
            <tr><td style="opacity:0.5;padding:3px 10px 3px 0;">New key</td>
                <td style="font-family:monospace;word-break:break-all;color:#0f0;"><?php echo htmlspecialchars($pending_rotation['new_pubkey']); ?></td></tr>
        </table>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="action" value="accept_key_rotation" class="btn-smack"
                    style="background:#fa0;color:#000;"
                    onclick="return confirm('Accept this key rotation? It was verified against the root key.');">
                &#10003; ACCEPT KEY ROTATION
            </button>
        </form>

        <?php else: ?>
        <h3 style="color:#c00;">&#9888; REPAIR SIGNING KEY</h3>
        <p class="dim" style="font-size:0.8rem;margin-bottom:16px;">
            The installed public key does not match the key used to sign this release.
            No root-key-signed rotation file was found on the server. Paste the new
            64-character hex public key manually (from Smack Central &rarr; Release Packager
            &rarr; Signing Key, or <code>core/release-pubkey.php</code> in the repo).
        </p>
        <p style="font-size:0.75rem;margin-bottom:12px;opacity:0.6;">
            Current key: <code><?php echo htmlspecialchars(SNAPSMACK_RELEASE_PUBKEY); ?></code>
        </p>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <input type="text" name="new_pubkey" placeholder="64-char hex Ed25519 public key"
                   style="width:100%;font-family:monospace;font-size:0.8rem;padding:8px;
                          background:#111;color:#0f0;border:1px solid #c00;margin-bottom:10px;"
                   maxlength="64" autocomplete="off" spellcheck="false">
            <button type="submit" name="action" value="repair_pubkey" class="btn-smack"
                    style="background:#c00;"
                    onclick="return confirm('Update the signing public key? Only do this if you rotated the keypair.');">
                UPDATE SIGNING KEY
            </button>
        </form>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <!-- COMPLETED UPDATE LOG -->
    <?php if ($complete_log): ?>
    <div class="box update-section">
        <h3>UPDATE LOG</h3>
        <div class="step-log">
            <?php foreach ($complete_log as $step): ?>
            <div class="step-row step-<?php echo $step['status']; ?>">
                <span class="step-icon"><?php echo $step['status'] === 'ok' ? '&#10003;' : '&#10007;'; ?></span>
                <span><?php echo htmlspecialchars($step['label']); ?></span>
                <?php if ($step['detail']): ?>
                    <span class="step-detail"><?php echo htmlspecialchars($step['detail']); ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- UPLOAD RESULT LOG -->
    <?php if ($update_result): ?>
    <div class="box update-section" id="upload-result-log">
        <h3>UPDATE LOG</h3>
        <div class="step-log">
            <?php foreach ($update_result as $step): ?>
            <div class="step-row step-<?php echo $step['status']; ?>">
                <span class="step-icon"><?php echo $step['status'] === 'ok' ? '&#10003;' : '&#10007;'; ?></span>
                <span><?php echo htmlspecialchars($step['label']); ?></span>
                <?php if ($step['detail']): ?>
                    <span class="step-detail"><?php echo htmlspecialchars($step['detail']); ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($flash_type === 'error' && !empty($_SESSION['update_backup_file'])): ?>
        <div class="update-warning">THE UPDATE ENCOUNTERED AN ERROR. You can attempt a rollback.</div>
        <form method="POST">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="action" value="rollback" class="btn-smack"
                    onclick="return confirm('Restore from backup? This will overwrite all files changed by the failed update.');">
                ROLLBACK TO PREVIOUS VERSION
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- UPDATE IN PROGRESS (staged pipeline) -->
    <?php if ($stage_state): ?>
    <?php $stage = $stage_state['stage'] ?? ''; ?>
    <div class="stage-box" id="stage-box">
        <h3>UPDATE IN PROGRESS &mdash; <?php echo strtoupper($stage); ?></h3>

        <div class="step-log">
            <?php foreach (($stage_state['log'] ?? []) as $step): ?>
            <div class="step-row step-<?php echo $step['status']; ?>">
                <span class="step-icon"><?php echo $step['status'] === 'ok' ? '&#10003;' : '&#10007;'; ?></span>
                <span><?php echo htmlspecialchars($step['label']); ?></span>
                <?php if ($step['detail']): ?>
                    <span class="step-detail"><?php echo htmlspecialchars($step['detail']); ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="stage-actions">
        <?php if ($flash_type !== 'error'): ?>
        <form method="POST" class="stage-next-btn">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <?php if ($stage === 'review'): ?>
                <button type="submit" name="action" value="stage_download" class="btn-smack">APPLY &rarr;</button>
            <?php elseif ($stage === 'downloaded'): ?>
                <button type="submit" name="action" value="stage_verify" class="btn-smack">VERIFY PACKAGE &rarr;</button>
            <?php elseif ($stage === 'verified'): ?>
                <button type="submit" name="action" value="stage_backup" class="btn-smack">CREATE BACKUP &rarr;</button>
            <?php elseif ($stage === 'backed_up'): ?>
                <button type="submit" name="action" value="stage_premigrate" class="btn-smack">PATCH DATABASE &rarr;</button>
            <?php elseif ($stage === 'premigrated'): ?>
                <button type="submit" name="action" value="stage_extract" class="btn-smack">EXTRACT FILES &rarr;</button>
            <?php elseif ($stage === 'extracted'): ?>
                <button type="submit" name="action" value="stage_migrate" class="btn-smack">RUN MIGRATIONS &amp; FINALIZE &rarr;</button>
            <?php endif; ?>
        </form>
        <?php else: ?>
        <div class="update-warning">The update encountered an error at this stage. Cancel to start over.</div>
        <?php endif; ?>

        <form method="POST" class="stage-cancel">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="action" value="cancel_update" class="btn-smack"
                    onclick="return confirm('Cancel this update and discard the downloaded package?');"
                    style="opacity:0.5;">
                CANCEL UPDATE
            </button>
        </form>
        </div>
    </div>

    <?php else: ?>
    <!-- ── PRIMARY STATUS CARD ── -->
    <div class="box update-section">
        <?php if ($core_status === 'checking'): ?>
        <div class="update-status-badge" id="check-status-badge" style="opacity:0.7;">&#8943; CHECKING FOR UPDATES&hellip;</div>
        <p class="dim" id="check-status-msg" style="font-size:0.85rem;margin-top:12px;">Hold on&hellip;</p>
        <script>
        (function() {
            var csrf = <?php echo json_encode($csrf); ?>;
            var badge = document.getElementById('check-status-badge');
            var msg   = document.getElementById('check-status-msg');
            var ctrl  = new AbortController();
            var timer = setTimeout(function() { ctrl.abort(); }, 15000);
            fetch(window.location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=check_ajax&csrf=' + encodeURIComponent(csrf),
                signal: ctrl.signal
            }).then(function(r) {
                clearTimeout(timer);
                return r.json();
            }).then(function(d) {
                if (d.ok) {
                    window.location.reload();
                } else {
                    badge.textContent = '— COULD NOT REACH UPDATE SERVER';
                    msg.textContent   = 'Check your server’s outbound HTTPS connection to snapsmack.ca.';
                }
            }).catch(function(e) {
                badge.textContent = '— CHECK TIMED OUT';
                msg.textContent   = 'No response after 15 seconds. Use CHECK NOW to try manually.';
                msg.insertAdjacentHTML('afterend',
                    '<form method="POST" class="mt-20"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>"><button type="submit" name="action" value="check" class="btn-smack">CHECK NOW</button></form>'
                );
            });
        })();
        </script>

        <?php elseif (!$cached_result || $cached_result['core_status'] === 'error'): ?>
        <div class="update-status-badge status-error">&#10007; COULD NOT REACH UPDATE SERVER</div>
        <p class="dim" style="font-size:0.8rem;margin-top:12px;">Check your server&rsquo;s outbound HTTPS connection to snapsmack.ca.</p>
        <form method="POST" class="mt-20">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="action" value="check" class="btn-smack">RETRY CHECK</button>
        </form>

        <?php elseif (!empty($cached_result['core_update'])): ?>
        <?php $upd = $cached_result['core_update']; ?>
        <div class="update-status-badge status-available">&#9888; UPDATE AVAILABLE</div>
        <div class="stat-row mt-20">
            <span class="label">NEW VERSION:</span>
            <span class="version-badge version-available">
                <?php echo htmlspecialchars($upd['version_full'] ?? "v{$upd['version']}"); ?>
            </span>
            <?php if (!empty($upd['codename'])): ?>
                <span class="dim ml-10">&ldquo;<?php echo htmlspecialchars($upd['codename']); ?>&rdquo;</span>
            <?php endif; ?>
        </div>
        <?php if (!empty($upd['changelog'])): ?>
        <ul class="changelog-list mt-20">
            <?php foreach (array_slice($upd['changelog'], 0, 5) as $entry): ?>
                <li><?php echo htmlspecialchars($entry); ?></li>
            <?php endforeach; ?>
            <?php if (count($upd['changelog']) > 5): ?>
                <li style="opacity:0.4;list-style:none;margin-left:-18px;">... and <?php echo count($upd['changelog']) - 5; ?> more</li>
            <?php endif; ?>
        </ul>
        <?php endif; ?>
        <?php if (!empty($upd['schema_changes'])): ?>
        <div class="update-warning mt-15">THIS UPDATE INCLUDES DATABASE SCHEMA CHANGES. Migrations run automatically during apply.</div>
        <?php endif; ?>
        <form method="POST" class="mt-25">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="action" value="stage_download" class="btn-smack">APPLY UPDATE &rarr;</button>
        </form>

        <?php else: ?>
        <div class="update-status-badge status-ok">&#10003; UP TO DATE</div>
        <div class="stat-row mt-20">
            <span class="label">RUNNING:</span>
            <span class="version-badge version-current"><?php echo htmlspecialchars($installed_full); ?></span>
            <?php if (defined('SNAPSMACK_VERSION_CODENAME') && SNAPSMACK_VERSION_CODENAME): ?>
                <span class="dim ml-10">&ldquo;<?php echo htmlspecialchars(SNAPSMACK_VERSION_CODENAME); ?>&rdquo;</span>
            <?php endif; ?>
        </div>
        <form method="POST" class="mt-20">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="action" value="check" class="btn-smack" style="width:auto;padding:0 20px;">CHECK NOW</button>
        </form>
        <?php endif; ?>
        <?php if ($last_check): ?>
        <p class="dim" style="font-size:0.72rem;margin-top:16px;">Checked: <?php echo date('M j, Y g:ia', strtotime($last_check)); ?></p>
        <?php endif; ?>
    </div>
    <?php endif; // $stage_state ?>

    <!-- SKIN UPDATES -->
    <?php if (!empty($cached_result['updated_skins'])): ?>
    <div class="box update-section">
        <h3>SKIN UPDATES</h3>
        <?php foreach ($cached_result['updated_skins'] as $skin): ?>
        <div class="skin-notify-card skin-notify-update skin-update-row">
            <div>
                <strong><?php echo htmlspecialchars($skin['name']); ?></strong>
                <span class="skin-notify-version ml-10">v<?php echo htmlspecialchars($skin['from']); ?> &rarr; v<?php echo htmlspecialchars($skin['to']); ?></span>
            </div>
            <?php if (!empty($skin['download_url'])): ?>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($skin['slug']); ?>">
                <input type="hidden" name="download_url" value="<?php echo htmlspecialchars($skin['download_url']); ?>">
                <input type="hidden" name="signature" value="<?php echo htmlspecialchars($skin['signature'] ?? ''); ?>">
                <?php $skin_confirm = 'Update ' . htmlspecialchars(addslashes($skin['name'])) . ' to v' . htmlspecialchars($skin['to']) . '?'; ?>
                <button type="submit" name="action" value="skin_update" class="btn-smack btn-sm"
                        onclick="return confirm('<?php echo $skin_confirm; ?>');">UPDATE</button>
            </form>
            <?php else: ?>
            <a href="smack-skin.php?tab=gallery" class="btn-smack btn-sm" style="text-decoration:none;">VIEW IN GALLERY</a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($cached_result['new_skins'])): ?>
    <div class="box update-section">
        <div class="skin-new-notice">
            <?php $nc = count($cached_result['new_skins']); ?>
            <?php echo $nc; ?> new skin<?php echo $nc !== 1 ? 's' : ''; ?> available in the Skin Gallery.
            <a href="smack-skin.php?tab=gallery" class="btn-smack btn-sm" style="text-decoration:none;">OPEN GALLERY</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- SCHEMA RECOVERY — shown at top when there are pending migrations or ghost files -->
    <?php if ($schema_resync_result || $has_pending || $has_ghosts): ?>
    <div class="box update-section" <?php echo ($has_pending || $has_ghosts) ? 'style="border-color:rgba(200,120,0,0.5);"' : ''; ?>>
        <h3>SCHEMA RECOVERY</h3>
        <?php if ($schema_resync_result): ?>
        <div style="margin-bottom:16px;">
            <?php if (!empty($schema_resync_result['created'])): ?>
                <div style="font-family:monospace;font-size:0.78rem;margin-bottom:6px;">TABLES CREATED:</div>
                <?php foreach ($schema_resync_result['created'] as $t): ?>
                    <code style="display:block;padding:2px 10px;font-size:0.75rem;opacity:0.85;">+ <?php echo htmlspecialchars($t); ?></code>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($schema_resync_result['columns_added'])): ?>
                <div style="font-family:monospace;font-size:0.78rem;margin:10px 0 6px;">COLUMNS ADDED:</div>
                <?php foreach ($schema_resync_result['columns_added'] as $c): ?>
                    <code style="display:block;padding:2px 10px;font-size:0.75rem;opacity:0.85;">+ <?php echo htmlspecialchars($c); ?></code>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($schema_resync_result['errors'])): ?>
                <div style="font-family:monospace;font-size:0.78rem;margin:10px 0 6px;color:#c44;">ERRORS:</div>
                <?php foreach ($schema_resync_result['errors'] as $e): ?>
                    <code style="display:block;padding:2px 10px;font-size:0.75rem;color:#c44;">&#10007; <?php echo htmlspecialchars($e); ?></code>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($has_ghosts): ?>
        <div class="update-warning" style="margin-bottom:16px;">
            <strong>GHOST FILES DETECTED</strong> &mdash; Migration files on disk not part of any release.
            The updater skips them automatically, but they should be removed.<br><br>
            <?php foreach ($migration_status['ghosts'] as $g): ?>
                <code style="display:block;margin-top:4px;font-size:0.8rem;" class="migration-ghost">&#9888; <?php echo htmlspecialchars($g); ?></code>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($show_migration_table): ?>
        <table class="recovery-table">
            <thead><tr><th>MIGRATION</th><th>STATUS</th><th>APPLIED AT</th></tr></thead>
            <tbody>
            <?php foreach ($pending_rows as $name): ?>
                <tr><td><?php echo htmlspecialchars($name); ?></td><td class="migration-pending">&mdash; PENDING</td><td>&mdash;</td></tr>
            <?php endforeach; ?>
            <?php foreach ($migration_status['ghosts'] as $name): ?>
                <tr><td><?php echo htmlspecialchars($name); ?></td><td class="migration-ghost">&#9888; GHOST</td><td>&mdash;</td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="migration-ok" style="font-size:0.85rem;margin-bottom:16px;">&#10003; All <?php echo count($migration_status['applied']); ?> migrations applied.</p>
        <?php endif; ?>
        <div class="recovery-actions">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <button type="submit" name="action" value="schema_resync" class="btn-smack">RUN SCHEMA SYNC</button>
            </form>
            <?php if ($has_pending): ?>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <button type="submit" name="action" value="mark_migrations_applied" class="btn-smack btn-secondary"
                        onclick="return confirm('Mark all known migrations as applied without running them?\n\nOnly do this if you have already applied the schema changes manually (e.g. via cPanel SQL).');"
                        style="font-size:0.75rem;">MARK ALL MIGRATIONS APPLIED</button>
            </form>
            <?php endif; ?>
            <?php if ($has_ghosts): $ghost_count = count($migration_status['ghosts']); ?>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <button type="submit" name="action" value="purge_ghosts" class="btn-smack btn-secondary"
                        onclick="return confirm('Permanently delete <?php echo $ghost_count; ?> ghost migration file(s) from disk?\n\nThese files are not part of any official release and will never be run by the updater.');"
                        style="font-size:0.75rem;">PURGE GHOST FILES</button>
            </form>
            <?php endif; ?>
        </div>
        <?php if ($has_pending): ?>
        <p class="dim" style="font-size:0.72rem;margin-top:10px;">
            &ldquo;Mark All Applied&rdquo; records all known migrations without running their SQL.
            Use this after running a manual recovery SQL file in cPanel.
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── ADVANCED OPTIONS (collapsed) ── -->
    <details class="update-advanced">
        <summary>ADVANCED OPTIONS</summary>

        <!-- CURRENT INSTALLATION -->
        <div class="box update-section">
            <h3>CURRENT INSTALLATION</h3>
            <div class="stat-row">
                <span class="label">VERSION:</span>
                <span class="version-badge version-current"><?php echo htmlspecialchars($installed_full); ?></span>
                <?php if (defined('SNAPSMACK_VERSION_CODENAME') && SNAPSMACK_VERSION_CODENAME): ?>
                    <span class="dim ml-10">&ldquo;<?php echo htmlspecialchars(SNAPSMACK_VERSION_CODENAME); ?>&rdquo;</span>
                <?php endif; ?>
            </div>
            <div class="stat-row mt-20">
                <span class="label">LAST CHECK:</span>
                <span class="value"><?php echo $last_check ? date('M j, Y g:ia', strtotime($last_check)) : 'NEVER'; ?></span>
            </div>
            <div class="stat-row mt-20">
                <span class="label">SIGNING:</span>
                <span class="value"><?php echo (defined('SNAPSMACK_SIGNING_ENFORCED') && SNAPSMACK_SIGNING_ENFORCED) ? 'ENFORCED' : 'ADVISORY (PLACEHOLDER KEY)'; ?></span>
            </div>
            <div class="update-action-row mt-25">
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <button type="submit" name="action" value="reapply" class="btn-smack"
                            onclick="return confirm('Reapply v<?php echo htmlspecialchars($installed_version); ?>? This will re-download and re-extract all files.\n\nUse this if a release was packaged incorrectly or files were missed.');"
                            style="background:#666;">REAPPLY CURRENT VERSION</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <button type="submit" name="action" value="reset_update_state" class="btn-smack btn-secondary"
                            onclick="return confirm('Reset all update state and re-sync installed version from constants.php?');"
                            style="font-size:0.75rem;opacity:0.6;">RESET UPDATE STATE</button>
                </form>
            </div>
        </div>

        <!-- SCHEMA RECOVERY (routine access — only shown here when no urgent issues) -->
        <?php if (!$schema_resync_result && !$has_pending && !$has_ghosts): ?>
        <div class="box update-section">
            <h3>SCHEMA RECOVERY</h3>
            <p class="dim" style="font-size:0.8rem;margin-bottom:16px;">
                Run a schema sync or inspect migration state without running a full update.
                Use these tools after a failed update or when bringing an older install current manually.
            </p>
            <p class="migration-ok" style="font-size:0.85rem;margin-bottom:16px;">&#10003; All <?php echo count($migration_status['applied']); ?> migrations applied. No pending work.</p>
            <div class="recovery-actions">
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <button type="submit" name="action" value="schema_resync" class="btn-smack">RUN SCHEMA SYNC</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- CANONICAL SCHEMA DIFF -->
        <div class="box update-section">
            <h3>CANONICAL SCHEMA DIFF</h3>
            <p class="dim" style="font-size:0.8rem;margin-bottom:16px;">
                Fetches <em>snapsmack_canonical.sql</em> from the release server and
                compares it against your live database. Catches missing tables or columns
                including cases where an update failed before the on-disk copy was replaced.
                <?php if ($has_canonical_url && $has_canonical_sig): ?>
                    <span style="color:#0f0;"> &mdash; Remote URL + signature available.</span>
                <?php elseif ($has_canonical_url): ?>
                    <span style="color:#fa0;"> &mdash; Remote URL available (no signature).</span>
                <?php else: ?>
                    <span style="opacity:0.5;"> &mdash; No remote URL in manifest; will use on-disk copy.</span>
                <?php endif; ?>
            </p>
            <?php if ($canonical_apply): ?>
            <div style="margin-bottom:16px;">
                <?php if (!empty($canonical_apply['created'])): ?>
                    <div style="font-family:monospace;font-size:0.78rem;margin-bottom:6px;">TABLES CREATED:</div>
                    <?php foreach ($canonical_apply['created'] as $t): ?>
                        <code style="display:block;padding:2px 10px;font-size:0.75rem;color:#0f0;">+ <?php echo htmlspecialchars($t); ?></code>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($canonical_apply['columns_added'])): ?>
                    <div style="font-family:monospace;font-size:0.78rem;margin:10px 0 6px;">COLUMNS ADDED:</div>
                    <?php foreach ($canonical_apply['columns_added'] as $c): ?>
                        <code style="display:block;padding:2px 10px;font-size:0.75rem;color:#0f0;">+ <?php echo htmlspecialchars($c); ?></code>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($canonical_apply['errors'])): ?>
                    <div style="font-family:monospace;font-size:0.78rem;margin:10px 0 6px;color:#c44;">ERRORS:</div>
                    <?php foreach ($canonical_apply['errors'] as $e): ?>
                        <code style="display:block;padding:2px 10px;font-size:0.75rem;color:#c44;">&#10007; <?php echo htmlspecialchars($e); ?></code>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($canonical_diff && !$canonical_diff['all_ok']): ?>
            <div style="margin-bottom:16px;">
                <div style="font-family:monospace;font-size:0.75rem;opacity:0.6;margin-bottom:10px;">
                    SOURCE: <?php echo strtoupper(htmlspecialchars($canonical_diff['source'])); ?>
                    &nbsp;&mdash;&nbsp;
                    <?php echo (int)$canonical_diff['canonical_tables']; ?> TABLE(S) IN CANONICAL SCHEMA
                </div>
                <?php if (!empty($canonical_diff['missing_tables'])): ?>
                    <div style="font-family:monospace;font-size:0.78rem;margin-bottom:6px;color:#fa0;">MISSING TABLES:</div>
                    <?php foreach ($canonical_diff['missing_tables'] as $t): ?>
                        <code style="display:block;padding:2px 10px;font-size:0.75rem;color:#fa0;">&#10007; <?php echo htmlspecialchars($t); ?></code>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($canonical_diff['missing_columns'])): ?>
                    <div style="font-family:monospace;font-size:0.78rem;margin:10px 0 6px;color:#fa0;">MISSING COLUMNS:</div>
                    <?php foreach ($canonical_diff['missing_columns'] as $item): ?>
                        <code style="display:block;padding:2px 10px;font-size:0.75rem;color:#fa0;">
                            &#10007; <?php echo htmlspecialchars($item['table'] . '.' . $item['column']); ?>
                        </code>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php elseif ($canonical_diff && $canonical_diff['all_ok']): ?>
            <div style="font-family:monospace;font-size:0.78rem;color:#0f0;margin-bottom:16px;">
                &#10003; DATABASE MATCHES CANONICAL SCHEMA
                (<?php echo (int)$canonical_diff['canonical_tables']; ?> tables,
                source: <?php echo strtoupper(htmlspecialchars($canonical_diff['source'])); ?>)
            </div>
            <?php endif; ?>
            <div class="recovery-actions">
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <button type="submit" name="action" value="canonical_diff" class="btn-smack">CHECK CANONICAL SCHEMA</button>
                </form>
                <?php if ($canonical_diff && !$canonical_diff['all_ok']): ?>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <button type="submit" name="action" value="apply_canonical_diff" class="btn-smack"
                            onclick="return confirm('Apply all missing tables and columns from the canonical schema?\nThis is safe — operations are idempotent.');">
                        APPLY ALL MISSING
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- MANUAL UPDATE (UPLOAD) -->
        <div class="box update-section" id="manual-upload">
            <h3>MANUAL UPDATE (UPLOAD)</h3>
            <p class="dim" style="font-size:0.8rem;margin-bottom:16px;">
                If this server cannot reach snapsmack.ca, download the update package
                on your own machine and upload it here. The same backup, verification,
                and extraction pipeline will run.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <div style="margin-bottom:14px;">
                    <label style="font-size:0.78rem;opacity:0.6;display:block;margin-bottom:10px;">UPDATE PACKAGE (.ZIP)</label>
                    <label class="file-pick-btn" id="file-pick-label">
                        <input type="file" name="update_zip" accept=".zip" required id="upload-zip-input" style="position:absolute;opacity:0;width:0;height:0;">
                        <span id="file-pick-text">CHOOSE FILE</span>
                    </label>
                    <span id="file-pick-name" style="display:block;margin-top:8px;font-size:0.78rem;opacity:0.5;font-family:monospace;">No file chosen</span>
                </div>
                <button type="submit" name="action" value="upload_zip" class="btn-smack mt-15"
                        onclick="return confirm('Apply update from uploaded zip? A backup will be created first.');">
                    UPLOAD &amp; APPLY
                </button>
            </form>
        </div>

        <!-- AUTOMATED CHECKS -->
        <div class="box update-section" id="automated-checks">
            <h3>AUTOMATED CHECKS</h3>
            <?php if ($cron_supported): ?>
                <label>VERSION CHECK JOB</label>
                <div class="read-only-display"><?php echo $version_job_registered ? 'REGISTERED — RUNS EVERY 6 HOURS' : 'NOT REGISTERED'; ?></div>
                <div class="recovery-actions mt-25">
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                        <button type="submit" name="action" value="cron_register" class="btn-smack"
                                <?php echo $version_job_registered ? 'disabled' : ''; ?>>REGISTER VERSION CHECK</button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                        <button type="submit" name="action" value="cron_remove" class="btn-smack"
                                <?php echo !$version_job_registered ? 'disabled' : ''; ?>
                                onclick="return confirm('Remove the automatic version check cron job?');">REMOVE VERSION CHECK</button>
                    </form>
                </div>
                <p class="dim mt-25" style="font-size:0.8rem;">Without cron, the dashboard falls back to a 24-hour on-load check.</p>
            <?php else: ?>
                <label>CRON ENGINE</label>
                <div class="read-only-display">NOT SUPPORTED ON THIS HOST</div>
                <p class="dim mt-10" style="font-size:0.8rem;">The dashboard will fall back to checking every 24 hours on page load.</p>
            <?php endif; ?>
        </div>

    </details>

</div>


<script>
(function () {
    var log = document.querySelector('.step-log');
    if (log) { log.scrollIntoView({ behavior: 'smooth', block: 'start' }); }

    // File picker label feedback
    var inp   = document.getElementById('upload-zip-input');
    var lbl   = document.getElementById('file-pick-label');
    var fname = document.getElementById('file-pick-name');
    if (inp && lbl && fname) {
        inp.addEventListener('change', function () {
            if (inp.files && inp.files.length > 0) {
                fname.textContent = inp.files[0].name;
                lbl.classList.add('has-file');
            } else {
                fname.textContent = 'No file chosen';
                lbl.classList.remove('has-file');
            }
        });
    }

    // Auto-advance the staged update pipeline.
    // Once the user clicks APPLY UPDATE on the status card, every subsequent
    // stage (download → verify → backup → premigrate → extract → migrate)
    // runs automatically. The only manual step is the initial Apply click.
    // Does not fire when there is an error (alert-danger is on the page).
    var stageBox = document.getElementById('stage-box');
    if (stageBox && !document.querySelector('.alert-danger')) {
        var nextBtn = stageBox.querySelector('.stage-next-btn button[type="submit"]');
        if (nextBtn && !nextBtn.disabled) {
            nextBtn.style.opacity = '0.35';
            var note = document.createElement('p');
            note.style.cssText  = 'font-size:0.72rem;opacity:0.4;margin-top:8px;font-family:monospace;letter-spacing:0.05em;';
            note.textContent    = 'AUTO-CONTINUING...';
            nextBtn.parentNode.appendChild(note);
            // Use click() not form.submit() — form.submit() omits the button
            // name/value so PHP receives no action and the pipeline stalls.
            setTimeout(function () { nextBtn.click(); }, 800);
        }
    }
})();
</script>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
