<?php
/**
 * SNAPSMACK - System Update Manager
 * Alpha v0.7.3a
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
 * 5. STAGE: backup    — Forced pre-update backup
 * 6. STAGE: extract   — Extract files (protected paths never overwritten)
 * 7. STAGE: migrate   — Run schema migrations + bump version
 * 8. DONE          — Success screen, or rollback on failure
 */

require_once 'core/auth.php';
require_once 'core/updater.php';

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
    if ($db_ver && version_compare($db_ver, $installed_version, '>')) {
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

// --- STAGED UPDATE STATE ---
// Persists between stages via session.
$stage_state = $_SESSION['update_state'] ?? null;

// --- ACTION HANDLER ---
$action     = $_POST['action'] ?? $_GET['action'] ?? '';
$flash_msg  = '';
$flash_type = 'info';

// CSRF validation for POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
    if (!isset($_POST['csrf']) || !hash_equals($csrf, $_POST['csrf'])) {
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
        header('Location: smack-update.php');
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
}

// ── STAGED UPDATE: CANCEL ─────────────────────────────────────────────────────
if ($action === 'cancel_update') {
    if (!empty($stage_state['zip_path'])) @unlink($stage_state['zip_path']);
    unset($_SESSION['update_state'], $_SESSION['update_complete_log'], $_SESSION['update_chunk_state']);
    $stage_state = null;
    $flash_msg   = 'UPDATE CANCELLED.';
    $flash_type  = 'info';
}

// ── STAGED UPDATE: STAGE 1 — DOWNLOAD ────────────────────────────────────────
if ($action === 'stage_download' && !empty($cached_result['core_update'])) {
    $update       = $cached_result['core_update'];
    $required_php = $update['requires_php'] ?? '8.0';

    if (version_compare(PHP_VERSION, $required_php, '<')) {
        $flash_msg  = "UPDATE REQUIRES PHP {$required_php}+. YOU ARE RUNNING " . PHP_VERSION . ".";
        $flash_type = 'error';
    } else {
        $dl_error = '';
        $zip_path = updater_download($update['download_url'], $dl_error);
        if ($zip_path === false) {
            $flash_msg  = "DOWNLOAD FAILED: {$dl_error}";
            $flash_type = 'error';
        } else {
            $size_mb = number_format(filesize($zip_path) / 1048576, 1);
            $_SESSION['update_state'] = [
                'stage'    => 'downloaded',
                'zip_path' => $zip_path,
                'update'   => $update,
                'log'      => [['label' => 'Package downloaded', 'status' => 'ok', 'detail' => "{$size_mb} MB"]],
            ];
            $stage_state = $_SESSION['update_state'];
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
    } else {
        $_SESSION['update_state']['stage'] = 'verified';
        $_SESSION['update_state']['log'][] = ['label' => 'Signature verified', 'status' => 'ok', 'detail' => 'SHA-256 + Ed25519 OK'];
        $stage_state = $_SESSION['update_state'];
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
    } else {
        $_SESSION['update_state']['stage']       = 'backed_up';
        $_SESSION['update_state']['backup_file'] = $backup_file;
        $_SESSION['update_backup_file']          = $backup_file; // keep for rollback button
        $_SESSION['update_state']['log'][]       = ['label' => 'Backup created', 'status' => 'ok', 'detail' => basename($backup_file)];
        $stage_state = $_SESSION['update_state'];
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
    && ($stage_state['stage'] ?? '') === 'backed_up'
) {
    $_SESSION['update_chunk_state'] = [
        'zip_path'      => $stage_state['zip_path'],
        'offset'        => 0,
        'files_updated' => 0,
        'files_skipped' => 0,
        'errors'        => [],
        'total'         => 0,
    ];
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

    if (!empty($migrations)) {
        $migrate_result = updater_run_migrations($pdo, $migrations);

        if (!$migrate_result['success']) {
            $rb_error = '';
            updater_rollback($stage_state['backup_file'] ?? '', $rb_error);
            $flash_msg  = 'MIGRATION FAILED. SYSTEM ROLLED BACK. Errors: ' . implode('; ', $migrate_result['errors']);
            $flash_type = 'error';
            $_SESSION['update_state']['log'][] = ['label' => 'Migrations failed — rolled back', 'status' => 'fail', 'detail' => implode('; ', $migrate_result['errors'])];
            $stage_state = $_SESSION['update_state'];
        } else {
            $_SESSION['update_state']['log'][] = ['label' => 'Migrations run', 'status' => 'ok', 'detail' => implode(', ', $migrate_result['applied'])];
        }
    } else {
        $_SESSION['update_state']['log'][] = ['label' => 'No migrations needed', 'status' => 'ok', 'detail' => ''];
    }

    if ($flash_type !== 'error') {
        updater_set_version($pdo, $update['version'], $update['version_full'] ?? "Alpha {$update['version']}", $update['codename'] ?? '');
        $_SESSION['update_state']['log'][] = ['label' => 'Version updated', 'status' => 'ok', 'detail' => "v{$installed_version} → v{$update['version']}"];

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

        // Store log for display after session clear
        $_SESSION['update_complete_log'] = $_SESSION['update_state']['log'];
        unset($_SESSION['update_state']);
        $stage_state = null;

        $flash_msg  = "UPDATE COMPLETE. NOW RUNNING v{$update['version']}.";
        $flash_type = 'success';
        $cached_result = null;
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
                    $migrations = updater_find_migrations($pdo);
                    if (!empty($migrations)) {
                        $migrate_result = updater_run_migrations($pdo, $migrations);
                        $upload_steps[] = [
                            'label'  => 'Schema migrations',
                            'status' => $migrate_result['success'] ? 'ok' : 'fail',
                            'detail' => implode(', ', $migrate_result['applied'])
                        ];
                        if (!$migrate_result['success']) {
                            $rb_error = '';
                            updater_rollback($backup_file, $rb_error);
                            $flash_msg  = 'MIGRATION FAILED. SYSTEM ROLLED BACK.';
                            $flash_type = 'error';
                            $upload_steps[] = ['label' => 'Rollback', 'status' => 'ok', 'detail' => 'Restored from backup'];
                            updater_cleanup();
                        }
                    }

                    if ($flash_type !== 'error') {
                        if ($target_version) {
                            $target_codename = $cached_result['core_update']['codename'] ?? '';
                            updater_set_version($pdo, $target_version, $target_version_full ?: "Alpha {$target_version}", $target_codename);
                            $upload_steps[] = ['label' => 'Version updated', 'status' => 'ok', 'detail' => "v{$installed_version} → v{$target_version}"];
                        }
                        $pdo->exec("DELETE FROM snap_settings WHERE setting_key = 'update_check_result'");
                        $cached_result = null;
                        updater_cleanup();
                        updater_prune_backups(3);

                        // Asset sync: fetch any missing fonts or JS engines.
                        require_once __DIR__ . '/core/asset-sync.php';
                        asset_sync_bust_cache();
                        $sync_msg = asset_sync_run();
                        if ($sync_msg !== null) {
                            $upload_steps[] = ['label' => 'Asset sync', 'status' => 'ok', 'detail' => $sync_msg];
                        }

                        $flash_msg  = $target_version ? "UPDATE COMPLETE VIA UPLOAD. NOW RUNNING v{$target_version}." : "PACKAGE EXTRACTED SUCCESSFULLY.";
                        $flash_type = 'success';
                    }
                }
            }
        }
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
    .stage-next-btn { margin-top: 16px; }
    .stage-cancel { margin-top: 10px; }

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
    .cron-info {
        margin-top: 20px; padding: 12px 18px; border-radius: 3px;
        background: rgba(255,255,255,0.03); font-family: monospace; font-size: 0.75rem; line-height: 1.6;
    }
</style>

<div class="main">
    <h2>SYSTEM UPDATES</h2>

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
            <span class="value"><?php echo SNAPSMACK_SIGNING_ENFORCED ? 'ENFORCED' : 'ADVISORY (PLACEHOLDER KEY)'; ?></span>
        </div>
        <form method="POST" class="mt-25">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <button type="submit" name="action" value="check" class="btn-smack">CHECK FOR UPDATES NOW</button>
        </form>
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
    <div class="stage-box">
        <h3>UPDATE IN PROGRESS — <?php echo strtoupper($stage); ?></h3>

        <!-- Accumulated log -->
        <div class="step-log">
            <?php foreach ($stage_state['log'] as $step): ?>
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
            <?php if ($stage === 'downloaded'): ?>
                <button type="submit" name="action" value="stage_verify" class="btn-smack">VERIFY PACKAGE →</button>
            <?php elseif ($stage === 'verified'): ?>
                <button type="submit" name="action" value="stage_backup" class="btn-smack">CREATE BACKUP →</button>
            <?php elseif ($stage === 'backed_up'): ?>
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
            THIS UPDATE INCLUDES DATABASE SCHEMA CHANGES. A full backup will be created before applying. Migrations will run automatically.
        </div>
        <?php endif; ?>

        <?php if (!empty($upd['requires_php']) && version_compare(PHP_VERSION, $upd['requires_php'], '<')): ?>
        <div class="update-warning mt-20">
            THIS UPDATE REQUIRES PHP <?php echo htmlspecialchars($upd['requires_php']); ?>+.
            You are running PHP <?php echo PHP_VERSION; ?>. Update your PHP installation first.
        </div>
        <?php else: ?>
        <div class="confirm-box">
            <p>Applying this update will:</p>
            <ul class="changelog-list">
                <li>Download the update package from snapsmack.ca</li>
                <li>Verify the checksum and Ed25519 signature</li>
                <li>Create a full backup of the current installation</li>
                <li>Extract new files (protected paths are never overwritten)</li>
                <?php if (!empty($upd['schema_changes'])): ?>
                    <li>Run database schema migrations</li>
                <?php endif; ?>
                <li>Update the version number</li>
            </ul>
            <p class="dim text-sm" style="margin-bottom:15px;">
                Each step runs as a separate request — no timeouts on slow connections.
            </p>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <button type="submit" name="action" value="stage_download" class="btn-smack master-update-btn"
                        onclick="return confirm('Begin update to v<?php echo htmlspecialchars($upd['version']); ?>?');">
                    APPLY UPDATE → v<?php echo htmlspecialchars($upd['version']); ?>
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- MANUAL UPLOAD FALLBACK -->
    <div class="box update-section">
        <h3>MANUAL UPDATE (UPLOAD)</h3>
        <p class="dim text-sm mb-15">
            If this server cannot reach snapsmack.ca, download the update zip on your own machine and upload it here.
            The same backup, verification, and extraction pipeline will run.
        </p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
            <div class="lens-input-wrapper">
                <label>UPDATE PACKAGE (.zip)</label>
                <input type="file" name="update_zip" accept=".zip" required style="padding: 8px;">
            </div>
            <button type="submit" name="action" value="upload_zip" class="btn-smack mt-15"
                    onclick="return confirm('Apply update from uploaded zip? A backup will be created first.');">
                UPLOAD &amp; APPLY
            </button>
        </form>
    </div>

    <!-- SKIN NOTIFICATIONS -->
    <?php if (!empty($cached_result['new_skins']) || !empty($cached_result['updated_skins'])): ?>
    <div class="box update-section">
        <h3>SKIN REGISTRY</h3>
        <?php if (!empty($cached_result['new_skins'])): ?>
        <label>NEW SKINS AVAILABLE</label>
        <?php foreach ($cached_result['new_skins'] as $skin): ?>
            <div class="skin-notify-card skin-notify-new">
                <div>
                    <strong><?php echo htmlspecialchars($skin['name']); ?></strong>
                    <?php if ($skin['description']): ?>
                        <span class="dim ml-10"><?php echo htmlspecialchars($skin['description']); ?></span>
                    <?php endif; ?>
                </div>
                <span class="skin-notify-version">v<?php echo htmlspecialchars($skin['version']); ?></span>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($cached_result['updated_skins'])): ?>
        <label class="<?php echo !empty($cached_result['new_skins']) ? 'mt-25' : ''; ?>">SKIN UPDATES AVAILABLE</label>
        <?php foreach ($cached_result['updated_skins'] as $skin): ?>
            <div class="skin-notify-card skin-notify-update">
                <div><strong><?php echo htmlspecialchars($skin['name']); ?></strong></div>
                <span class="skin-notify-version">v<?php echo htmlspecialchars($skin['from']); ?> → v<?php echo htmlspecialchars($skin['to']); ?></span>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <a href="smack-skin.php?tab=gallery" class="btn-smack mt-25 btn-block">OPEN SKIN GALLERY</a>
    </div>
    <?php endif; ?>

    <!-- CRON SETUP -->
    <div class="box update-section">
        <h3>AUTOMATED CHECKS</h3>
        <?php if ($cron_supported): ?>
            <label>VERSION CHECK JOB</label>
            <div class="read-only-display"><?php echo $version_job_registered ? 'REGISTERED — RUNS EVERY 6 HOURS' : 'NOT REGISTERED'; ?></div>
            <form method="POST" class="mt-25">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <div class="action-grid-dual">
                    <button type="submit" name="action" value="cron_register" class="btn-smack" <?php echo $version_job_registered ? 'disabled' : ''; ?>>REGISTER VERSION CHECK</button>
                    <button type="submit" name="action" value="cron_remove"   class="btn-smack" <?php echo !$version_job_registered ? 'disabled' : ''; ?>>REMOVE VERSION CHECK</button>
                </div>
            </form>
            <p class="dim text-sm mt-15">Without cron, the dashboard falls back to a 24-hour on-load check.</p>
        <?php else: ?>
            <label>CRON ENGINE</label>
            <div class="read-only-display">NOT SUPPORTED ON THIS HOST</div>
            <p class="dim text-sm mt-10">The dashboard will fall back to checking every 24 hours on page load.</p>
        <?php endif; ?>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
