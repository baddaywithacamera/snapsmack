<?php
/**
 * SNAPSMACK - SMACKBACK File Integrity Monitor
 *
 * Admin interface for SMACKBACK: status dashboard, breach management,
 * manual verification, incident log, and settings.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';
require_once 'core/smackback.php';

// ─── LOAD SETTINGS ──────────────────────────────────────────────────────────

if (!isset($settings)) {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
}

$smack_enabled    = ($settings['smackback_enabled']       ?? '0') === '1';
$smack_mode       = $settings['smackback_mode']            ?? 'lockout';
$smack_status     = $settings['smackback_status']          ?? 'clean';
$smack_breach_at  = $settings['smackback_breach_at']       ?? '';
$smack_breach_files_json = $settings['smackback_breach_files'] ?? '[]';
$smack_breach_files = json_decode($smack_breach_files_json, true) ?? [];
$smack_last_verify = $settings['smackback_last_full_verify'] ?? '';
$smack_alert_email = $settings['smackback_alert_email']    ?? '';
$smack_pageload   = ($settings['smackback_pageload_check'] ?? '0') === '1';

$is_breach = ($smack_status === 'breach');

// ─── ACTION: AJAX / POST ────────────────────────────────────────────────────

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$wants_json = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
           && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// RESTORE SINGLE FILE
if ($action === 'restore' && isset($_GET['restore'])) {
    $path   = trim($_GET['restore']);
    $result = smackback_restore_file($path);

    // Re-check if any breach remains
    $remaining = $pdo->query(
        "SELECT COUNT(*) FROM snap_file_manifest WHERE last_status IN ('tampered','truncated','corrupted','missing')"
    )->fetchColumn();

    if ((int)$remaining === 0 && $is_breach) {
        smackback_resolve_breach('restore');
    }

    if ($wants_json) {
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
    header('Location: smack-smackback.php?msg=' . urlencode($result['message']));
    exit;
}

// RESTORE ALL
if ($action === 'restore_all' || isset($_GET['restore_all'])) {
    $result = smackback_restore_all_breached();

    if ($wants_json) {
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
    $msg = $result['all_clear']
        ? 'All files restored. Breach cleared.'
        : 'Partial restore: ' . count($result['restored']) . ' restored, ' . count($result['failed']) . ' failed.';
    header('Location: smack-smackback.php?msg=' . urlencode($msg));
    exit;
}

// RUN FULL VERIFY
if ($action === 'run_verify' || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['run_verify'] ?? '') === '1')) {
    $result = smackback_verify_all();

    if ($result['status'] === 'breach') {
        smackback_handle_breach(
            $result['tampered'],
            $result['missing'],
            $result['truncated'] ?? [],
            $result['corrupted'] ?? []
        );
    } else {
        $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('smackback_last_full_verify', ?)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
        )->execute([date('Y-m-d H:i:s')]);
    }

    if ($wants_json) {
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    if ($result['status'] === 'breach') {
        $parts = [];
        if (count($result['tampered']))             $parts[] = count($result['tampered'])  . ' tampered';
        if (count($result['truncated'] ?? []))      $parts[] = count($result['truncated']) . ' truncated';
        if (count($result['corrupted'] ?? []))      $parts[] = count($result['corrupted']) . ' corrupted';
        if (count($result['missing']))              $parts[] = count($result['missing'])   . ' missing';
        $msg = 'BREACH DETECTED: ' . implode(', ', $parts) . '.';
    } else {
        $msg = "{$result['ok']} files verified clean in {$result['duration']}s.";
    }
    header('Location: smack-smackback.php?msg=' . urlencode($msg));
    exit;
}

// RUN SKIN JS SCAN
if ($action === 'run_skin_js_scan' || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['run_skin_js_scan'] ?? '') === '1')) {
    $result = smackback_run_skin_js_scan();

    if ($wants_json) {
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    $summary = "Skin JS scan complete: {$result['violations']} violation(s), {$result['warnings']} warning(s).";
    header('Location: smack-smackback.php?msg=' . urlencode($summary));
    exit;
}

// SAVE SKIN JS SETTINGS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_skin_js_settings'] ?? '') === '1') {
    $allow = ($_POST['skin_allow_custom_js'] ?? '0') === '1' ? '1' : '0';
    $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('skin_allow_custom_js', ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    )->execute([$allow]);
    header('Location: smack-smackback.php?msg=Skin+JS+settings+saved.');
    exit;
}

// RE-INITIALISE BASELINE FROM DISK
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['reinit_baseline'] ?? '') === '1') {
    $ok = smackback_init_from_disk();
    $msg = $ok ? 'Baseline re-initialised from disk. All files re-hashed.' : 'Re-init failed — check error log.';
    header('Location: smack-smackback.php?msg=' . urlencode($msg));
    exit;
}

// SAVE SETTINGS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_settings'] ?? '') === '1') {
    $new_enabled    = ($_POST['smackback_enabled']       ?? '0') === '1' ? '1' : '0';
    $new_mode       = in_array($_POST['smackback_mode'] ?? '', ['alert','lockout','paranoid'], true)
                    ? $_POST['smackback_mode'] : 'lockout';
    $new_pageload   = ($_POST['smackback_pageload_check'] ?? '0') === '1' ? '1' : '0';
    $new_email      = trim($_POST['smackback_alert_email'] ?? '');

    $upsert = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    );
    $upsert->execute(['smackback_enabled',        $new_enabled]);
    $upsert->execute(['smackback_mode',            $new_mode]);
    $upsert->execute(['smackback_pageload_check',  $new_pageload]);
    $upsert->execute(['smackback_alert_email',     $new_email]);

    header('Location: smack-smackback.php?msg=Settings+saved.');
    exit;
}

// ─── LOAD PAGE DATA ─────────────────────────────────────────────────────────

// File count
$manifest_count = 0;
$skin_breakdown = [];
try {
    $manifest_count = (int) $pdo->query("SELECT COUNT(*) FROM snap_file_manifest")->fetchColumn();
    $rows = $pdo->query(
        "SELECT COALESCE(skin_id, '[core]') AS skin, COUNT(*) AS cnt
         FROM snap_file_manifest GROUP BY skin_id ORDER BY skin_id IS NULL DESC, skin_id"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $skin_breakdown[] = $r['skin'] . ': ' . $r['cnt'];
    }
} catch (PDOException $e) { }

// Incident log
$incidents = [];
try {
    $incidents = $pdo->query(
        "SELECT * FROM snap_smackback_log ORDER BY detected_at DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { }

// Skin JS scan results
$skin_js_findings_json  = $settings['skin_js_violations_json']  ?? '[]';
$skin_js_scan_at        = $settings['skin_js_scan_at']           ?? '';
$skin_js_violation_count = (int)($settings['skin_js_violation_count'] ?? 0);
$skin_js_findings       = json_decode($skin_js_findings_json, true) ?? [];
$skin_allow_custom_js   = ($settings['skin_allow_custom_js'] ?? '0') === '1';

$flash_msg = $_GET['msg'] ?? '';

// ─── PAGE RENDER ─────────────────────────────────────────────────────────────

$page_title = 'SMACKBACK';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>SMACKBACK — FILE INTEGRITY MONITOR</h2>

    <?php if ($flash_msg): ?>
        <div class="alert">> <?php echo htmlspecialchars($flash_msg); ?></div>
    <?php endif; ?>

    <!-- ── STATUS PANEL ──────────────────────────────────────────────────── -->
    <section class="settings-section">
        <h3><?php if ($is_breach): ?>⚠ BREACH DETECTED<?php elseif ($smack_enabled): ?>✓ CLEAN<?php else: ?>SMACKBACK DISABLED<?php endif; ?></h3>

        <table class="settings-table">
            <tr>
                <td class="label">Status</td>
                <td><?php
                    if (!$smack_enabled) {
                        echo '<span style="color:#888">Disabled</span>';
                    } elseif ($is_breach) {
                        echo '<strong style="color:#cc2200">BREACH</strong>';
                    } else {
                        echo '<span style="color:#5a9a5a">Clean</span>';
                    }
                ?></td>
            </tr>
            <tr>
                <td class="label">Response mode</td>
                <td><?php echo htmlspecialchars(strtoupper($smack_mode)); ?></td>
            </tr>
            <tr>
                <td class="label">Files monitored</td>
                <td>
                    <?php echo number_format($manifest_count); ?>
                    <?php if (!empty($skin_breakdown)): ?>
                        <span class="dim">(<?php echo implode(', ', $skin_breakdown); ?>)</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td class="label">Last full verify</td>
                <td><?php echo $smack_last_verify ? htmlspecialchars($smack_last_verify) : '<span class="dim">Never</span>'; ?></td>
            </tr>
        </table>
    </section>

    <?php if ($is_breach): ?>
    <!-- ── BREACH DETAIL ─────────────────────────────────────────────────── -->
    <section class="settings-section" style="border-left: 4px solid #cc2200;">
        <h3 style="color:#cc2200">BREACH DETAIL</h3>
        <p>Detected: <strong><?php echo htmlspecialchars($smack_breach_at ?: 'Unknown'); ?></strong></p>
        <br>

        <table class="settings-table" style="margin-bottom:20px;">
            <thead>
                <tr>
                    <th>File</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $breach_status_colours = [
                'TAMPERED'  => '#cc2200',
                'MISSING'   => '#ff6600',
                'TRUNCATED' => '#e07800',
                'CORRUPTED' => '#cc9900',
            ];
            foreach ($smack_breach_files as $entry):
                $bp   = htmlspecialchars($entry['path']   ?? '');
                $bst  = strtoupper($entry['status'] ?? 'UNKNOWN');
                $bcol = $breach_status_colours[$bst] ?? '#cc2200';
            ?>
                <tr>
                    <td style="font-family:monospace;font-size:0.88rem;"><?php echo $bp; ?></td>
                    <td style="color:<?php echo $bcol; ?>;font-weight:700;"><?php echo $bst; ?></td>
                    <td>
                        <a href="smack-smackback.php?action=restore&restore=<?php echo urlencode($entry['path'] ?? ''); ?>"
                           class="btn btn-sm"
                           onclick="return confirm('Restore <?php echo $bp; ?> from update server?');">
                            Restore
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
            <a href="smack-smackback.php?restore_all=1"
               class="btn btn-danger"
               onclick="return confirm('Restore all tampered files from the update server?');">
                Restore All Tampered Files
            </a>
            <a href="smack-update.php" class="btn">Run Full Update Instead</a>
        </div>
        <p class="dim" style="font-size:0.85rem;">
            After restoring, change your FTP credentials and check your hosting panel access logs.
        </p>
    </section>
    <?php endif; ?>

    <!-- ── MANUAL VERIFICATION ───────────────────────────────────────────── -->
    <section class="settings-section">
        <h3>MANUAL VERIFICATION</h3>
        <p style="margin-bottom:16px;">Hash all monitored files and compare against the stored baseline.
           Takes a fraction of a second on typical installs.</p>

        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="run_verify" value="1">
            <button type="submit" class="btn">Run Full Verification Now</button>
        </form>

        <?php
        // Show last verify result summary if available from URL (redirect after verify)
        if (!empty($flash_msg) && (strpos($flash_msg, 'verified clean') !== false || strpos($flash_msg, 'BREACH') !== false)):
        ?>
            <div class="alert" style="margin-top:16px;">> <?php echo htmlspecialchars($flash_msg); ?></div>
        <?php endif; ?>
    </section>

    <!-- ── INCIDENT LOG ──────────────────────────────────────────────────── -->
    <section class="settings-section">
        <h3>INCIDENT LOG</h3>
        <?php if (empty($incidents)): ?>
            <p class="dim">No incidents recorded.</p>
        <?php else: ?>
            <table class="settings-table">
                <thead>
                    <tr>
                        <th>Detected</th>
                        <th>Resolved</th>
                        <th>Files</th>
                        <th>Resolution</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($incidents as $inc): ?>
                    <tr>
                        <td style="font-size:0.85rem;"><?php echo htmlspecialchars($inc['detected_at']); ?></td>
                        <td style="font-size:0.85rem;"><?php echo $inc['resolved_at'] ? htmlspecialchars($inc['resolved_at']) : '<span class="dim">Open</span>'; ?></td>
                        <td><?php echo (int)$inc['file_count']; ?></td>
                        <td><?php echo $inc['resolution'] ? htmlspecialchars($inc['resolution']) : '<span class="dim">—</span>'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <!-- ── SETTINGS ─────────────────────────────────────────────────────── -->
    <section class="settings-section">
        <h3>SETTINGS</h3>

        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="save_settings" value="1">

            <table class="settings-table">
                <tr>
                    <td class="label">Enable SMACKBACK</td>
                    <td>
                        <label class="toggle-wrap">
                            <input type="checkbox" name="smackback_enabled" value="1"<?php echo $smack_enabled ? ' checked' : ''; ?>>
                            <span>Active</span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td class="label">Response mode</td>
                    <td>
                        <label><input type="radio" name="smackback_mode" value="alert"<?php echo $smack_mode === 'alert' ? ' checked' : ''; ?>>
                            <strong>Alert</strong> — banner in admin, no lockout</label><br>
                        <label><input type="radio" name="smackback_mode" value="lockout"<?php echo $smack_mode === 'lockout' ? ' checked' : ''; ?>>
                            <strong>Lockout</strong> (recommended) — all admin pages redirect here until resolved</label><br>
                        <label><input type="radio" name="smackback_mode" value="paranoid"<?php echo $smack_mode === 'paranoid' ? ' checked' : ''; ?>>
                            <strong>Paranoid</strong> — Lockout + hub breach reporting (Phase 2)</label>
                    </td>
                </tr>
                <tr>
                    <td class="label">Pageload stat check</td>
                    <td>
                        <label class="toggle-wrap">
                            <input type="checkbox" name="smackback_pageload_check" value="1"<?php echo $smack_pageload ? ' checked' : ''; ?>>
                            <span>Check file mtimes on public page loads (very fast — no file reads unless mtime changed)</span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td class="label">Alert email</td>
                    <td>
                        <input type="email" name="smackback_alert_email"
                               value="<?php echo htmlspecialchars($smack_alert_email); ?>"
                               placeholder="<?php echo htmlspecialchars($settings['admin_email'] ?? $settings['site_email'] ?? ''); ?>"
                               style="width:300px;">
                        <p class="dim" style="font-size:0.82rem;margin-top:4px;">Leave blank to use the site admin email.</p>
                    </td>
                </tr>
            </table>

            <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>
    </section>

    <!-- ── NETWORK ALERT (Layer 2 — SC global YELLOW) ──────────────────── -->
    <?php
    // Load network alert state for this section
    require_once 'core/network-alert.php';

    // Handle actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_nalert'] ?? '') === '1') {
        $na_send    = ($_POST['network_alert_send']    ?? '0') === '1' ? '1' : '0';
        $na_receive = ($_POST['network_alert_receive'] ?? '0') === '1' ? '1' : '0';
        $na_sc_url  = trim($_POST['network_alert_sc_url'] ?? 'https://snapsmack.ca');
        if (empty($na_sc_url)) $na_sc_url = 'https://snapsmack.ca';

        $na_up = $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
        );
        $na_up->execute(['network_alert_send',    $na_send]);
        $na_up->execute(['network_alert_receive', $na_receive]);
        $na_up->execute(['network_alert_sc_url',  $na_sc_url]);

        header('Location: smack-smackback.php?msg=Network+alert+settings+saved.');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['nalert_check_now'] ?? '') === '1') {
        $na_sc_url = trim($settings['network_alert_sc_url'] ?? 'https://snapsmack.ca');
        $polled    = nalert_poll_sc($na_sc_url);
        $msg       = $polled ? "Checked — status: {$polled}" : 'Check failed (SC unreachable or not opted in to receive).';
        header('Location: smack-smackback.php?msg=' . urlencode($msg));
        exit;
    }

    $na = nalert_get_local();

    $na_status_labels = [
        'green'       => '<span style="color:#5a9a5a">&#9679; Green — no advisory</span>',
        'yellow_slow' => '<span style="color:#cc9900">&#9679; YELLOW (advisory) — network-wide alert active</span>',
        'yellow_fast' => '<span style="color:#ffcc00;font-weight:700;">&#9679; YELLOW FAST — coordinated threat detected</span>',
    ];
    $na_status_display = $na_status_labels[$na['status']] ?? htmlspecialchars($na['status']);
    ?>
    <section id="network-alert" class="settings-section">
        <h3>NETWORK ALERT <span class="dim" style="font-weight:400;font-size:0.8rem;">(Layer 2 — Smack Central global)</span></h3>
        <p style="margin-bottom:16px;color:#999;font-size:0.88rem;line-height:1.7;">
            Opt-in to the SnapSmack network alert system. If Smack Central detects a coordinated
            breach affecting multiple installs, it broadcasts a YELLOW alert to all opted-in sites.
            Entirely separate from your local SMACKBACK RED alerts — those never leave your server.
        </p>

        <table class="settings-table" style="margin-bottom:20px;">
            <tr>
                <td class="label">Current network status</td>
                <td><?php echo $na_status_display; ?>
                    <?php if ($na['since']): ?>
                        <span class="dim" style="font-size:0.82rem;"> since <?php echo htmlspecialchars($na['since']); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($na['message']): ?>
            <tr>
                <td class="label">SC message</td>
                <td><?php echo htmlspecialchars($na['message']); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td class="label">Last checked</td>
                <td><?php echo $na['last_checked'] ? htmlspecialchars($na['last_checked']) : '<span class="dim">Never</span>'; ?>
                    <form method="post" style="display:inline;margin-left:16px;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="nalert_check_now" value="1">
                        <button type="submit" class="btn btn-sm">Check Now</button>
                    </form>
                </td>
            </tr>
        </table>

        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="save_nalert" value="1">

            <table class="settings-table">
                <tr>
                    <td class="label">Send breach data to SC</td>
                    <td>
                        <label class="toggle-wrap">
                            <input type="checkbox" name="network_alert_send" value="1"<?php echo $na['send'] ? ' checked' : ''; ?>>
                            <span>Contribute breach reports to the network</span>
                        </label>
                        <p class="dim" style="font-size:0.82rem;margin-top:4px;">
                            Reports contain: site name, server IP, affected file paths, timestamps, and SHA-256 hashes. No visitor data, no content.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td class="label">Receive YELLOW alerts</td>
                    <td>
                        <label class="toggle-wrap">
                            <input type="checkbox" name="network_alert_receive" value="1"<?php echo $na['receive'] ? ' checked' : ''; ?>>
                            <span>Show SC network alerts in the admin panel</span>
                        </label>
                        <p class="dim" style="font-size:0.82rem;margin-top:4px;">
                            You can receive alerts without sending data (courtesy opt-in). SC is privately hosted — we do our best but cannot guarantee uptime.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td class="label">Smack Central URL</td>
                    <td>
                        <input type="url" name="network_alert_sc_url"
                               value="<?php echo htmlspecialchars($na['sc_url']); ?>"
                               style="width:320px;">
                        <p class="dim" style="font-size:0.82rem;margin-top:4px;">Only change this if you run a private SC instance.</p>
                    </td>
                </tr>
            </table>

            <button type="submit" class="btn btn-primary">Save Network Alert Settings</button>
        </form>
    </section>

    <!-- ── SKIN JS SECURITY SCAN ────────────────────────────────────────── -->
    <section class="settings-section">
        <h3>SKIN JS SECURITY SCAN</h3>
        <p style="margin-bottom:16px;color:#999;font-size:0.88rem;line-height:1.7;">
            Scans all non-base installed skins for inline JavaScript, <code>eval()</code> calls,
            <code>atob()</code>, <code>document.write()</code>, and external scripts loaded from
            untrusted domains. Base skins (50-shades-of-noah-grey, new-horizon) are always trusted.
            <strong>Violations</strong> indicate active risk.
            <strong>Warnings</strong> are suspicious but may be legitimate.
        </p>

        <!-- Status row -->
        <table class="settings-table" style="margin-bottom:20px;">
            <tr>
                <td class="label">Last scan</td>
                <td>
                    <?php if ($skin_js_scan_at): ?>
                        <?php echo htmlspecialchars($skin_js_scan_at); ?>
                        —
                        <?php if ($skin_js_violation_count > 0): ?>
                            <strong style="color:var(--danger,#e33);"><?php echo $skin_js_violation_count; ?> violation(s)</strong>
                        <?php else: ?>
                            <span style="color:var(--success,#4c4);">No violations</span>
                        <?php endif; ?>
                        (<?php
                            $wc = count(array_filter($skin_js_findings, fn($f) => $f['severity'] === 'warning'));
                            echo $wc . ' warning' . ($wc !== 1 ? 's' : '');
                        ?>)
                    <?php else: ?>
                        <span class="dim">Never scanned</span>
                    <?php endif; ?>
                    <form method="post" style="display:inline;margin-left:16px;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="run_skin_js_scan" value="1">
                        <button type="submit" class="btn btn-sm">Scan Now</button>
                    </form>
                </td>
            </tr>
        </table>

        <?php if (!empty($skin_js_findings)): ?>
            <?php
                $by_skin = [];
                foreach ($skin_js_findings as $f) {
                    $by_skin[$f['skin']][] = $f;
                }
            ?>
            <?php foreach ($by_skin as $slug => $skin_findings): ?>
                <?php
                    $sv = count(array_filter($skin_findings, fn($f) => $f['severity'] === 'violation'));
                    $sw = count(array_filter($skin_findings, fn($f) => $f['severity'] === 'warning'));
                    $si = count(array_filter($skin_findings, fn($f) => $f['severity'] === 'info'));
                ?>
                <details style="margin-bottom:12px;border:1px solid var(--border,#333);padding:10px 14px;background:var(--input-bg,#111);" <?php echo $sv > 0 ? 'open' : ''; ?>>
                    <summary style="cursor:pointer;font-weight:700;font-size:0.9rem;letter-spacing:1px;">
                        <?php echo htmlspecialchars($slug); ?>
                        <?php if ($sv > 0): ?><span style="margin-left:8px;color:var(--danger,#e33);font-size:0.8rem;"><?php echo $sv; ?> VIOLATION<?php echo $sv !== 1 ? 'S' : ''; ?></span><?php endif; ?>
                        <?php if ($sw > 0): ?><span style="margin-left:6px;color:var(--warning,#f90);font-size:0.8rem;"><?php echo $sw; ?> WARNING<?php echo $sw !== 1 ? 'S' : ''; ?></span><?php endif; ?>
                        <?php if ($si > 0 && $sv === 0 && $sw === 0): ?><span style="margin-left:6px;color:#888;font-size:0.8rem;"><?php echo $si; ?> INFO</span><?php endif; ?>
                    </summary>
                    <table style="width:100%;margin-top:10px;font-size:0.82rem;border-collapse:collapse;">
                        <thead>
                            <tr style="border-bottom:1px solid var(--border,#333);color:#888;">
                                <th style="text-align:left;padding:4px 8px;">SEV</th>
                                <th style="text-align:left;padding:4px 8px;">TYPE</th>
                                <th style="text-align:left;padding:4px 8px;">FILE</th>
                                <th style="text-align:left;padding:4px 8px;">LINE</th>
                                <th style="text-align:left;padding:4px 8px;">DETAIL</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($skin_findings as $f): ?>
                            <?php
                                $sev_color = match($f['severity']) {
                                    'violation' => 'var(--danger,#e33)',
                                    'warning'   => 'var(--warning,#f90)',
                                    default     => '#888',
                                };
                            ?>
                            <tr style="border-bottom:1px solid var(--border,#222);">
                                <td style="padding:4px 8px;color:<?php echo $sev_color; ?>;font-weight:700;text-transform:uppercase;font-size:0.78rem;">
                                    <?php echo htmlspecialchars($f['severity']); ?>
                                </td>
                                <td style="padding:4px 8px;font-family:monospace;"><?php echo htmlspecialchars($f['type']); ?></td>
                                <td style="padding:4px 8px;font-family:monospace;font-size:0.78rem;"><?php echo htmlspecialchars($f['file']); ?></td>
                                <td style="padding:4px 8px;text-align:center;"><?php echo (int)$f['line']; ?></td>
                                <td style="padding:4px 8px;color:#aaa;"><?php echo htmlspecialchars($f['detail']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endforeach; ?>
        <?php elseif ($skin_js_scan_at): ?>
            <p style="color:var(--success,#4c4);margin-bottom:16px;">✓ No findings — all installed skins are clean.</p>
        <?php endif; ?>

        <!-- Settings -->
        <form method="post" style="margin-top:16px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="save_skin_js_settings" value="1">
            <table class="settings-table">
                <tr>
                    <td class="label">Allow custom JS in skins</td>
                    <td>
                        <label class="toggle-wrap">
                            <input type="checkbox" name="skin_allow_custom_js" value="1"<?php echo $skin_allow_custom_js ? ' checked' : ''; ?>>
                            <span>Permit inline scripts and external JS in third-party skins</span>
                        </label>
                        <p class="dim" style="font-size:0.82rem;margin-top:4px;">
                            When enabled, inline scripts are downgraded to info and external scripts to warnings.
                            <code>eval()</code> is always flagged as a violation regardless of this setting.
                        </p>
                    </td>
                </tr>
            </table>
            <button type="submit" class="btn btn-primary" style="margin-top:12px;">Save</button>
        </form>
    </section>

    <!-- ── RE-INITIALISE BASELINE ────────────────────────────────────────── -->
    <section class="settings-section">
        <h3>RE-INITIALISE BASELINE</h3>
        <p style="margin-bottom:16px;">
            Re-hash all monitored files from disk and update the baseline.
            Use this after a legitimate manual file edit (rare — normally updates handle this automatically).
            <strong>Do not use this if a breach is active</strong> — it would bless the tampered files.
        </p>

        <form method="post"
              onsubmit="return confirm('Re-initialise from disk? Only do this after a verified legitimate change. Do NOT use during an active breach.');">
            <?php csrf_field(); ?>
            <input type="hidden" name="reinit_baseline" value="1">
            <button type="submit" class="btn btn-warn">Re-initialise Baseline from Disk</button>
        </form>
    </section>

</div><!-- .main -->

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
