<?php
/**
 * SNAPSMACK - System Update Manager
 * Alpha v0.7
 *
 * Admin interface for the self-update system. Displays current version info,
 * checks for updates, shows changelogs and file changes, forces a backup
 * before applying updates, runs schema migrations, and provides rollback
 * capability on failure.
 *
 * Also shows skin registry notifications (new skins available, skin updates).
 *
 * FLOW:
 * 1. CHECK  — Fetch latest release info from update server
 * 2. REVIEW — Display changelog, file changes, migration warnings
 * 3. BACKUP — Forced pre-update backup (no skip option)
 * 4. APPLY  — Download, verify, extract, migrate
 * 5. DONE   — Success screen with version bump, or rollback on failure
 */

require_once 'core/auth.php';
require_once 'core/updater.php';

// --- EARLY CRON DETECTION ---
// Must run before POST handlers that depend on $cron_supported.
// admin-header.php also sets these, but it loads after the handlers.
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
$installed_full = SNAPSMACK_VERSION ?? 'Unknown';

// Also check settings table
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
$last_check = null;
try {
    $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'update_check_result'");
    $stmt->execute();
    $json = $stmt->fetchColumn();
    if ($json) $cached_result = json_decode($json, true);

    $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'last_update_check'");
    $stmt->execute();
    $last_check = $stmt->fetchColumn();
} catch (PDOException $e) {}

// --- ACTION HANDLERS ---
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$flash_msg = '';
$flash_type = 'info';

// CSRF validation for POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
    if (!isset($_POST['csrf']) || !hash_equals($csrf, $_POST['csrf'])) {
        $flash_msg = 'CSRF VALIDATION FAILED. REFRESH AND TRY AGAIN.';
        $flash_type = 'error';
        $action = ''; // block the action
    }
}

// --- ACTION: LIVE CHECK ---
if ($action === 'check') {
    $release_info = updater_fetch_release_info();
    $skin_info = updater_check_skin_registry($pdo);
    $core_status = updater_check_status($installed_version, $release_info);

    $core_update = null;
    if ($core_status === 'update_available') {
        $core_update = [
            'version'        => $release_info['version'] ?? '',
            'version_full'   => $release_info['version_full'] ?? '',
            'released'       => $release_info['released'] ?? '',
            'changelog'      => $release_info['changelog'] ?? [],
            'file_changes'   => $release_info['file_changes'] ?? [],
            'schema_changes' => $release_info['schema_changes'] ?? false,
            'download_size'  => $release_info['download_size'] ?? 0,
            'requires_php'   => $release_info['requires_php'] ?? '8.0',
            'download_url'   => $release_info['download_url'] ?? '',
            'checksum_sha256'=> $release_info['checksum_sha256'] ?? '',
            'signature'      => $release_info['signature'] ?? '',
        ];
    }

    // Store in cache
    $cached_result = [
        'checked_at'         => date('c'),
        'installed_version'  => $installed_version,
        'core_status'        => $core_status,
        'core_update'        => $core_update,
        'new_skins'          => $skin_info['new_skins'],
        'updated_skins'      => $skin_info['updated_skins'],
        'skin_notifications' => $skin_info['total_notifications'],
        'total_notifications'=> ($core_update ? 1 : 0) + $skin_info['total_notifications'],
    ];

    $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('update_check_result', ?)
                           ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)");
    $stmt->execute([json_encode($cached_result, JSON_UNESCAPED_SLASHES)]);

    $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('last_update_check', ?)
                           ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)");
    $stmt->execute([date('Y-m-d H:i:s')]);
    $last_check = date('Y-m-d H:i:s');

    if ($core_status === 'error') {
        $flash_msg = 'COULD NOT REACH UPDATE SERVER. CHECK YOUR CONNECTION.';
        $flash_type = 'error';
    } elseif ($core_status === 'up_to_date' && $skin_info['total_notifications'] === 0) {
        $flash_msg = 'SYSTEM IS UP TO DATE. NO NEW SKINS AVAILABLE.';
        $flash_type = 'success';
    } else {
        $notifications = [];
        if ($core_update) $notifications[] = "Core update available: v{$core_update['version']}";
        if (count($skin_info['new_skins']) > 0) $notifications[] = count($skin_info['new_skins']) . " new skin(s) available";
        if (count($skin_info['updated_skins']) > 0) $notifications[] = count($skin_info['updated_skins']) . " skin update(s) available";
        $flash_msg = strtoupper(implode(' — ', $notifications));
        $flash_type = 'warning';
    }
}

// --- ACTION: APPLY UPDATE ---
$update_result = null;
if ($action === 'apply' && !empty($cached_result['core_update'])) {
    $update = $cached_result['core_update'];
    $steps = [];

    // Step 1: PHP version check
    $required_php = $update['requires_php'] ?? '8.0';
    if (version_compare(PHP_VERSION, $required_php, '<')) {
        $flash_msg = "UPDATE REQUIRES PHP {$required_php}+. YOU ARE RUNNING " . PHP_VERSION . ".";
        $flash_type = 'error';
    } else {
        // Step 2: Force backup
        $backup_error = '';
        $backup_file = updater_create_backup($backup_error);
        if ($backup_file === false) {
            $flash_msg = "BACKUP FAILED: {$backup_error}. UPDATE ABORTED.";
            $flash_type = 'error';
        } else {
            $steps[] = ['label' => 'Backup created', 'status' => 'ok', 'detail' => basename($backup_file)];
            $_SESSION['update_backup_file'] = $backup_file;

            // Step 3: Download
            $dl_error = '';
            $zip_path = updater_download($update['download_url'], $dl_error);
            if ($zip_path === false) {
                $flash_msg = "DOWNLOAD FAILED: {$dl_error}";
                $flash_type = 'error';
                $steps[] = ['label' => 'Download', 'status' => 'fail', 'detail' => $dl_error];
            } else {
                $steps[] = ['label' => 'Download complete', 'status' => 'ok', 'detail' => ''];

                // Step 4: Verify
                $verify_error = '';
                $checksum = $update['checksum_sha256'] ?? '';
                $signature = $update['signature'] ?? '';
                if ($checksum && !updater_verify_package($zip_path, $checksum, $signature, $verify_error)) {
                    $flash_msg = "VERIFICATION FAILED: {$verify_error}";
                    $flash_type = 'error';
                    $steps[] = ['label' => 'Verification', 'status' => 'fail', 'detail' => $verify_error];
                    updater_cleanup();
                } else {
                    $steps[] = ['label' => 'Verification passed', 'status' => 'ok', 'detail' => ''];

                    // Step 5: Extract
                    $extract = updater_extract($zip_path);
                    $steps[] = [
                        'label' => 'Extraction',
                        'status' => $extract['success'] ? 'ok' : 'fail',
                        'detail' => "{$extract['files_updated']} updated, {$extract['files_skipped']} protected"
                    ];

                    if (!$extract['success']) {
                        $flash_msg = 'EXTRACTION FAILED. ERRORS: ' . implode('; ', $extract['errors']);
                        $flash_type = 'error';
                    } else {
                        // Step 6: Schema migrations
                        $migrations = updater_find_migrations($installed_version, $update['version']);
                        if (!empty($migrations)) {
                            $migrate_result = updater_run_migrations($pdo, $migrations);
                            $steps[] = [
                                'label' => 'Schema migrations',
                                'status' => $migrate_result['success'] ? 'ok' : 'fail',
                                'detail' => implode(', ', $migrate_result['applied'])
                            ];

                            if (!$migrate_result['success']) {
                                // Rollback on migration failure
                                $rb_error = '';
                                updater_rollback($backup_file, $rb_error);
                                $flash_msg = 'MIGRATION FAILED. SYSTEM ROLLED BACK. Errors: ' . implode('; ', $migrate_result['errors']);
                                $flash_type = 'error';
                                $steps[] = ['label' => 'Rollback', 'status' => 'ok', 'detail' => 'Restored from backup'];
                                updater_cleanup();
                            }
                        }

                        // If we haven't errored out, finalize
                        if ($flash_type !== 'error') {
                            // Step 7: Update version
                            updater_set_version($pdo, $update['version'], $update['version_full'] ?? "Alpha {$update['version']}");
                            $steps[] = ['label' => 'Version updated', 'status' => 'ok', 'detail' => "v{$installed_version} → v{$update['version']}"];

                            // Clear cached check
                            $pdo->exec("DELETE FROM snap_settings WHERE setting_key = 'update_check_result'");
                            $cached_result = null;

                            updater_cleanup();

                            $flash_msg = "UPDATE COMPLETE. NOW RUNNING v{$update['version']}.";
                            $flash_type = 'success';
                        }
                    }
                }
            }
        }
    }

    $update_result = $steps;
}

// --- ACTION: CRON REGISTRATION ---
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
            $flash_msg = ($ret === 0) ? 'VERSION CHECK JOB REGISTERED. RUNS EVERY 6 HOURS.' : 'FAILED TO REGISTER: ' . implode(' ', $out);
            $flash_type = ($ret === 0) ? 'success' : 'error';
        } else {
            $flash_msg = 'JOB ALREADY REGISTERED.';
            $flash_type = 'success';
        }
    } elseif ($action === 'cron_remove') {
        $cleaned = preg_replace('/.*' . preg_quote($tag, '/') . '.*\n?/', '', $current_cron_str);
        $tmp = tempnam(sys_get_temp_dir(), 'ssck');
        file_put_contents($tmp, trim($cleaned) . "\n");
        exec("crontab {$tmp} 2>&1", $out, $ret);
        unlink($tmp);
        $flash_msg = ($ret === 0) ? 'VERSION CHECK JOB REMOVED.' : 'FAILED TO REMOVE: ' . implode(' ', $out);
        $flash_type = ($ret === 0) ? 'success' : 'error';
    }
}

// Check if version check job is currently registered
$version_job_registered = false;
if ($cron_supported) {
    exec('crontab -l 2>&1', $vc_cron, $vc_rc);
    $version_job_registered = ($vc_rc === 0 && strpos(implode("\n", $vc_cron), '# snapsmack-version-check') !== false);
}

// --- ACTION: ROLLBACK ---
if ($action === 'rollback' && !empty($_SESSION['update_backup_file'])) {
    $rb_error = '';
    $ok = updater_rollback($_SESSION['update_backup_file'], $rb_error);
    if ($ok) {
        $flash_msg = 'ROLLBACK COMPLETE. PREVIOUS VERSION RESTORED.';
        $flash_type = 'success';
        unset($_SESSION['update_backup_file']);
    } else {
        $flash_msg = "ROLLBACK FAILED: {$rb_error}";
        $flash_type = 'error';
    }
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
    .version-current { background: #1a3a1a; color: #4a4; border: 1px solid #2a5a2a; }
    .version-available { background: #3a2a0a; color: #da4; border: 1px solid #5a4a1a; }

    .changelog-list {
        margin: 15px 0;
        padding-left: 20px;
        list-style: disc;
    }
    .changelog-list li {
        margin-bottom: 6px;
        color: inherit;
        opacity: 0.85;
    }

    .file-changes { margin: 15px 0; }
    .file-changes h4 { margin-bottom: 8px; font-size: 0.8rem; letter-spacing: 1px; }
    .file-changes code {
        display: block;
        font-size: 0.75rem;
        padding: 2px 0;
        opacity: 0.7;
    }
    .file-added { color: #6d6; }
    .file-modified { color: #dd6; }
    .file-removed { color: #d66; }

    .step-log { margin: 20px 0; }
    .step-row {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 12px;
        margin-bottom: 4px;
        border-radius: 3px;
        font-family: monospace;
        font-size: 0.8rem;
    }
    .step-ok { background: rgba(40, 120, 40, 0.15); }
    .step-fail { background: rgba(180, 40, 40, 0.15); }
    .step-icon { font-size: 1.1rem; }
    .step-detail { opacity: 0.6; margin-left: auto; }

    .skin-notify-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 15px;
        margin-bottom: 8px;
        border-radius: 3px;
        border-left: 3px solid #4a90d9;
        background: rgba(74, 144, 217, 0.08);
        font-size: 0.85rem;
    }
    .skin-notify-new { border-left-color: #4ad94a; background: rgba(74, 217, 74, 0.08); }
    .skin-notify-update { border-left-color: #d9d94a; background: rgba(217, 217, 74, 0.08); }
    .skin-notify-version { font-family: monospace; opacity: 0.7; }

    .update-warning {
        padding: 12px 18px;
        margin: 15px 0;
        border-radius: 3px;
        border-left: 4px solid #d94a4a;
        background: rgba(217, 74, 74, 0.08);
        font-size: 0.85rem;
    }

    .confirm-box {
        padding: 20px;
        margin: 20px 0;
        border: 2px solid #da4;
        border-radius: 4px;
        background: rgba(221, 170, 68, 0.05);
    }
    .confirm-box p { margin-bottom: 15px; }

    .cron-info {
        margin-top: 20px;
        padding: 12px 18px;
        border-radius: 3px;
        background: rgba(255,255,255,0.03);
        font-family: monospace;
        font-size: 0.75rem;
        line-height: 1.6;
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

    <!-- UPDATE STEPS LOG (if we just ran an update) -->
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
            <div class="update-warning">
                THE UPDATE ENCOUNTERED AN ERROR. You can attempt a rollback to restore the previous version.
            </div>
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

    <!-- CORE UPDATE DETAILS (from cache) -->
    <?php if (!empty($cached_result['core_update'])): ?>
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

        <!-- Changelog -->
        <?php if (!empty($upd['changelog'])): ?>
        <label class="mt-30">CHANGELOG</label>
        <ul class="changelog-list">
            <?php foreach ($upd['changelog'] as $entry): ?>
                <li><?php echo htmlspecialchars($entry); ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <!-- File Changes -->
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

        <!-- Schema Warning -->
        <?php if (!empty($upd['schema_changes'])): ?>
        <div class="update-warning mt-20">
            THIS UPDATE INCLUDES DATABASE SCHEMA CHANGES. A full backup will be created before applying. Migrations will be applied automatically.
        </div>
        <?php endif; ?>

        <!-- PHP Version Check -->
        <?php if (!empty($upd['requires_php']) && version_compare(PHP_VERSION, $upd['requires_php'], '<')): ?>
        <div class="update-warning mt-20">
            THIS UPDATE REQUIRES PHP <?php echo htmlspecialchars($upd['requires_php']); ?>+.
            You are running PHP <?php echo PHP_VERSION; ?>. Update your PHP installation first.
        </div>
        <?php else: ?>
        <!-- Confirm & Apply -->
        <div class="confirm-box">
            <p>Applying this update will:</p>
            <ul class="changelog-list">
                <li>Create a full backup of the current installation</li>
                <li>Download and verify the update package</li>
                <li>Extract new files (protected paths are never overwritten)</li>
                <?php if (!empty($upd['schema_changes'])): ?>
                    <li>Run database schema migrations</li>
                <?php endif; ?>
                <li>Update the version number</li>
            </ul>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                <button type="submit" name="action" value="apply" class="btn-smack master-update-btn"
                        onclick="return confirm('Apply update to v<?php echo htmlspecialchars($upd['version']); ?>? A backup will be created first.');">
                    APPLY UPDATE → v<?php echo htmlspecialchars($upd['version']); ?>
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

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
                        <span style="opacity:0.6;margin-left:10px;"><?php echo htmlspecialchars($skin['description']); ?></span>
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
                <div>
                    <strong><?php echo htmlspecialchars($skin['name']); ?></strong>
                </div>
                <span class="skin-notify-version">v<?php echo htmlspecialchars($skin['from']); ?> → v<?php echo htmlspecialchars($skin['to']); ?></span>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <a href="smack-skin.php?tab=gallery" class="btn-smack mt-25" style="display:inline-block;text-decoration:none;text-align:center;">
            OPEN SKIN GALLERY
        </a>
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
                    <button type="submit" name="action" value="cron_remove" class="btn-smack" <?php echo !$version_job_registered ? 'disabled' : ''; ?>>REMOVE VERSION CHECK</button>
                </div>
            </form>
            <p style="font-size:0.8rem;opacity:0.5;margin-top:15px;">
                Without cron, the dashboard falls back to a 24-hour on-load check.
            </p>
        <?php else: ?>
            <label>CRON ENGINE</label>
            <div class="read-only-display">NOT SUPPORTED ON THIS HOST</div>
            <p style="font-size:0.8rem;opacity:0.5;margin-top:10px;">
                The dashboard will fall back to checking every 24 hours on page load.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
