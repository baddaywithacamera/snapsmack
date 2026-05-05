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




require_once 'core/auth.php';
require_once 'core/updater.php';

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
$csrf = $_SESSION['update_csrf'];

// --- CURRENT VERSION ---
$installed_version = SNAPSMACK_VERSION_SHORT ?? '0.0';
$installed_full    = SNAPSMACK_VERSION ?? 'Unknown';

try {
    $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'installed_version'");
    $stmt->execute();
    $db_ver = $stmt->fetchColumn();
    if ($db_ver && snap_version_compare($db_ver, $installed_version, '>')) {
        $installed_version = $db_ver;
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
        // Fatal write error — surface to main page via session flash
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
        // All entries processed — advance stage and return to main page
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
        $flash_msg  = 'Reapplying v' . htmlspecialchars($installed_version) . '. Click APPLY to download and re-extract.';
        $flash_type = 'info';
    }
}

if ($action === 'check') {
    $release_info = updater_fetch_release_info();
    $skin_info    = updater_check_skin_registry($pdo);
    $core_status  = updater_check_status($installed_version, $release_info);

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

    $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('update_check_result', ?)
                           ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)");
    $stmt->execute([json_encode($cached_result, JSON_UNESCAPED_SLASHES)]);

    $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('last_update_check', ?)
                           ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)");
    $stmt->execute([date('Y-m-d H:i:s')]);
    $last_check = date('Y-m-d H:i:s');

    if ($core_status === 'error') {
        $flash_msg  = 'COULD NOT REACH UPDATE SERVER. CHECK YOUR CONNECTION.';
        $flash_type = 'error';
    } elseif ($core_status === 'up_to_date' && $skin_info['total_notifications'] === 0) {
        $flash_msg  = 'SYSTEM IS UP TO DATE. NO NEW SKINS AVAILABLE.';
        $flash_type = 'success';
    } else {
        $notifications = [];
        if ($core_update) $notifications[] = "Core update available: v{$core_update['version']}";
        if (count($skin_info['new_skins'])    > 0) $notifications[] = count($skin_info['new_skins'])    . " new skin(s) available";
        if (count($skin_info['updated_skins']) > 0) $notifications[] = count($skin_info['updated_skins']) . " skin update(s) available";
        $flash_msg  = strtoupper(implode(' — ', $notifications));
        $flash_type = 'warning';
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
    $pdo->exec("DELETE FROM snap_settings WHERE setting_key IN ('update_check_result', 'last_update_check')");

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
        updater_set_version($pdo, $update['version'], $update['version_full'] ?? "Alpha {$update['version']}", $update['codename'] ?? '');
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
    .stage-next-btn { margin-top: 16px; display: inline-block; }
    .stage-cancel { margin-top: 10px; display: inline-block; margin-left: 12px; }

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

    <!-- CURRENT VERSION INFO -->
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
                <button type="submit" name="action" value="check" class="btn-smack">CHECK FOR UPDATES NOW</button>
            </form>
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

    <!-- REPAIR SIGNING KEY PANEL — shown when a signature failure has just occurred -->
    <?php
    $sig_failure      = isset($flash_msg) && stripos($flash_msg, 'signature') !== false && stripos($flash_msg, 'failed') !== false;
    $pending_rotation = $_SESSION['pending_key_rotation'] ?? null;
    ?>
    <?php if ($sig_failure || $pending_rotation || isset($_GET['repair_key'])): ?>
    <div class="box update-section" id="repair-key-panel" style="border-color:#c00;">

        <?php if ($pending_rotation): ?>
        <!-- Root-key-signed rotation available — no manual paste needed -->
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
        <!-- Manual fallback — no rotation file found on server -->
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

    <!-- SCHEMA RECOVERY PANEL -->
    <?php
    $migration_status   = updater_migration_status($pdo);
    $schema_resync_result = null;
    if (!empty($_SESSION['schema_resync_result'])) {
        $schema_resync_result = $_SESSION['schema_resync_result'];
        unset($_SESSION['schema_resync_result']);
    }
    $has_ghosts  = !empty($migration_status['ghosts']);
    $has_pending = !empty($migration_status['pending']);
    ?>
    <div class="box update-section">
        <h3>SCHEMA RECOVERY</h3>
        <p class="dim" style="font-size:0.8rem;margin-bottom:16px;">
            Run a schema sync or inspect migration state without running a full update.
            Use these tools after a failed update or when bringing an older install current manually.
        </p>

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
                    <code style="display:block;padding:2px 10px;font-size:0.75rem;color:#c44;">✗ <?php echo htmlspecialchars($e); ?></code>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($has_ghosts): ?>
        <div class="update-warning" style="margin-bottom:16px;">
            <strong>GHOST FILES DETECTED</strong> — The following migration files are on disk but are not part of any official release.
            The updater will skip them automatically, but they should be removed to keep the directory clean.<br><br>
            <?php foreach ($migration_status['ghosts'] as $g): ?>
                <code style="display:block;margin-top:4px;font-size:0.8rem;" class="migration-ghost">⚠ <?php echo htmlspecialchars($g); ?></code>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php
        $applied_map = [];
        foreach ($migration_status['applied'] as $row) {
            $applied_map[$row['migration']] = $row['applied_at'];
        }
        $pending_rows = array_filter(UPDATER_KNOWN_MIGRATIONS, fn($n) => !isset($applied_map[$n]));
        $show_table   = !empty($pending_rows) || !empty($migration_status['ghosts']);
        ?>

        <?php if ($show_table): ?>
        <table class="recovery-table">
            <thead>
                <tr><th>MIGRATION</th><th>STATUS</th><th>APPLIED AT</th></tr>
            </thead>
            <tbody>
            <?php foreach ($pending_rows as $name): ?>
                <tr>
                    <td><?php echo htmlspecialchars($name); ?></td>
                    <td class="migration-pending">— PENDING</td>
                    <td>—</td>
                </tr>
            <?php endforeach; ?>
            <?php foreach ($migration_status['ghosts'] as $name): ?>
                <tr>
                    <td><?php echo htmlspecialchars($name); ?></td>
                    <td class="migration-ghost">⚠ GHOST</td>
                    <td>—</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="migration-ok" style="font-size:0.85rem; margin-bottom:16px;">✓ All <?php echo count($migration_status['applied']); ?> migrations applied. No pending work.</p>
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
            <?php if ($has_ghosts):
                $ghost_count = count($migration_status['ghosts']); ?>
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

    <!-- CANONICAL SCHEMA DIFF PANEL -->
    <?php
    $canonical_diff   = null;
    $canonical_apply  = null;
    if (!empty($_SESSION['canonical_diff_result'])) {
        $canonical_diff = $_SESSION['canonical_diff_result'];
        // Keep in session until user applies or navigates away
    }
    if (!empty($_SESSION['canonical_apply_result'])) {
        $canonical_apply = $_SESSION['canonical_apply_result'];
        unset($_SESSION['canonical_apply_result']);
    }
    $has_canonical_url = !empty($cached_result['canonical_schema_url']);
    $has_canonical_sig = !empty($cached_result['canonical_schema_sig']);
    ?>
    <div class="box update-section">
        <h3>CANONICAL SCHEMA DIFF</h3>
        <p class="dim" style="font-size:0.8rem;margin-bottom:16px;">
            Fetches <em>snapsmack_canonical.sql</em> from the release server and
            compares it against your live database. This is the same check the
            auto-updater runs — catches missing tables or columns including cases
            where an update failed before the on-disk copy was replaced.
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
                    <code style="display:block;padding:2px 10px;font-size:0.75rem;color:#c44;">✗ <?php echo htmlspecialchars($e); ?></code>
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
                    <code style="display:block;padding:2px 10px;font-size:0.75rem;color:#fa0;">✗ <?php echo htmlspecialchars($t); ?></code>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($canonical_diff['missing_columns'])): ?>
                <div style="font-family:monospace;font-size:0.78rem;margin:10px 0 6px;color:#fa0;">MISSING COLUMNS:</div>
                <?php foreach ($canonical_diff['missing_columns'] as $item): ?>
                    <code style="display:block;padding:2px 10px;font-size:0.75rem;color:#fa0;">
                        ✗ <?php echo htmlspecialchars($item['table'] . '.' . $item['column']); ?>
                    </code>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php elseif ($canonical_diff && $canonical_diff['all_ok']): ?>
        <div style="font-family:monospace;font-size:0.78rem;color:#0f0;margin-bottom:16px;">
            ✓ DATABASE MATCHES CANONICAL SCHEMA
            (<?php echo (int)$canonical_diff['canonical_tables']; ?> tables,
            source: <?php echo strtoupper(htmlspecialchars($canonical_diff['source'])); ?>)
        </div>
        <?php endif; ?>

        <div class="recovery-actions">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <button type="submit" name="action" value="canonical_diff" class="btn-smack">
                    CHECK CANONICAL SCHEMA
                </button>
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

    <!-- COMPLETED UPDATE LOG -->
    <?php if ($complete_log): ?>
    <div class="box update-section">
        <h3>UPDATE LOG</h3>
        <div class="step-log">
            <?php foreach ($complete_log as $step): ?>
            <div class="step-row step-<?php echo $step['status']; ?>">
                <span class="step-icon"><?php echo $step['status'] === 'ok' ? '✓' : '✗'; ?></span>
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
    <div class="box update-section">
        <h3>UPDATE LOG</h3>
        <div class="step-log">
            <?php foreach ($update_result as $step): ?>
            <div class="step-row step-<?php echo $step['status']; ?>">
                <span class="step-icon"><?php echo $step['status'] === 'ok' ? '✓' : '✗'; ?></span>
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

    <!-- STAGED UPDATE IN PROGRESS -->
    <?php if ($stage_state): ?>
    <?php $stage = $stage_state['stage'] ?? ''; ?>
    <div class="stage-box" id="stage-box">
        <h3>UPDATE IN PROGRESS — <?php echo strtoupper($stage); ?></h3>

        <!-- Accumulated log -->
        <div class="step-log">
            <?php foreach (($stage_state['log'] ?? []) as $step): ?>
            <div class="step-row step-<?php echo $step['status']; ?>">
                <span class="step-icon"><?php echo $step['status'] === 'ok' ? '✓' : '✗'; ?></span>
                <span><?php echo htmlspecialchars($step['label']); ?></span>
                <?php if ($step['detail']): ?>
                    <span class="step-detail"><?php echo htmlspecialchars($step['detail']); ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($flash_type !== 'error'): ?>
        <!-- Next stage button -->
        <form method="POST" class="stage-next-btn">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <?php if ($stage === 'review'): ?>
                <button type="submit" name="action" value="stage_download" class="btn-smack">APPLY →</button>
            <?php elseif ($stage === 'downloaded'): ?>
                <button type="submit" name="action" value="stage_verify" class="btn-smack">VERIFY PACKAGE →</button>
            <?php elseif ($stage === 'verified'): ?>
                <button type="submit" name="action" value="stage_backup" class="btn-smack">CREATE BACKUP →</button>
            <?php elseif ($stage === 'backed_up'): ?>
                <button type="submit" name="action" value="stage_premigrate" class="btn-smack">PATCH DATABASE →</button>
            <?php elseif ($stage === 'premigrated'): ?>
                <button type="submit" name="action" value="stage_extract" class="btn-smack">EXTRACT FILES →</button>
            <?php elseif ($stage === 'extracted'): ?>
                <button type="submit" name="action" value="stage_migrate" class="btn-smack">RUN MIGRATIONS &amp; FINALIZE →</button>
            <?php endif; ?>
        </form>
        <?php else: ?>
        <div class="update-warning">The update encountered an error at this stage. Cancel to start over.</div>
        <?php endif; ?>

        <!-- Cancel -->
        <form method="POST" class="stage-cancel">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="action" value="cancel_update" class="btn-smack"
                    onclick="return confirm('Cancel this update and discard the downloaded package?');"
                    style="opacity:0.5;">
                CANCEL UPDATE
            </button>
        </form>
    </div>

    <?php elseif (!empty($cached_result['core_update'])): ?>
    <!-- CORE UPDATE DETAILS (only shown when not in a staged update) -->
    <?php $upd = $cached_result['core_update']; ?>
    <div class="box update-section">
        <h3>CORE UPDATE AVAILABLE</h3>
        <div class="stat-row">
            <span class="label">NEW VERSION:</span>
            <span class="version-badge version-available">
                <?php echo htmlspecialchars($upd['version_full'] ?? "v{$upd['version']}"); ?>
            </span>
        </div>
        <?php if (!empty($upd['released'])): ?>
        <div class="stat-row mt-20">
            <span class="label">RELEASED:</span>
            <span class="value"><?php echo htmlspecialchars($upd['released']); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($upd['download_size'])): ?>
        <div class="stat-row mt-20">
            <span class="label">PACKAGE SIZE:</span>
            <span class="value"><?php echo number_format($upd['download_size'] / 1048576, 1); ?> MB</span>
        </div>
        <?php endif; ?>

        <?php if (!empty($upd['changelog'])): ?>
        <label class="mt-30">CHANGELOG</label>
        <ul class="changelog-list">
            <?php foreach ($upd['changelog'] as $entry): ?>
                <li><?php echo htmlspecialchars($entry); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <?php if (!empty($upd['file_changes'])): ?>
        <div class="file-changes mt-20">
            <label>FILE CHANGES</label>
            <?php if (!empty($upd['file_changes']['added'])): ?>
                <h4 class="file-added mt-20">ADDED (<?php echo count($upd['file_changes']['added']); ?>)</h4>
                <?php foreach ($upd['file_changes']['added'] as $f): ?>
                    <code class="file-added">+ <?php echo htmlspecialchars($f); ?></code>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($upd['file_changes']['modified'])): ?>
                <h4 class="file-modified mt-20">MODIFIED (<?php echo count($upd['file_changes']['modified']); ?>)</h4>
                <?php foreach ($upd['file_changes']['modified'] as $f): ?>
                    <code class="file-modified">~ <?php echo htmlspecialchars($f); ?></code>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!empty($upd['file_changes']['removed'])): ?>
                <h4 class="file-removed mt-20">REMOVED (<?php echo count($upd['file_changes']['removed']); ?>)</h4>
                <?php foreach ($upd['file_changes']['removed'] as $f): ?>
                    <code class="file-removed">- <?php echo htmlspecialchars($f); ?></code>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($upd['schema_changes'])): ?>
        <div class="update-warning mt-20">
            THIS UPDATE INCLUDES DATABASE SCHEMA CHANGES. Migrations will run automatically during the apply step.
        </div>

        <?php endif; ?>

        <form method="POST" class="mt-30">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="action" value="stage_download" class="btn-smack">APPLY UPDATE &rarr;</button>
        </form>

    </div>

    <?php else: ?>
    <div class="box update-section">
        <h3>UP TO DATE</h3>
        <p class="dim" style="font-size:0.85rem;">No update available. Your installation is running the latest release.</p>
    </div>
    <?php endif; ?>

</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
