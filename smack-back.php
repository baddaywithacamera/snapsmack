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
$smack_hub_pending_disable = ($settings['smackback_hub_pending_disable'] ?? '0') === '1';
$smack_hub_pending_mode    = $settings['smackback_hub_pending_mode'] ?? '';

$is_breach = ($smack_status === 'breach');

// ─── ACTION: AJAX / POST ────────────────────────────────────────────────────

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$wants_json = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
           && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// RESTORE SINGLE FILE
if ($action === 'restore' && isset($_GET['restore'])) {
    $path   = trim($_GET['restore']);
    $result = smackback_restore_file($path);

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
    header('Location: smack-back.php?msg=' . urlencode($result['message']));
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
    header('Location: smack-back.php?msg=' . urlencode($msg));
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
        // A clean full verify means any prior breach is resolved. Clear the flag so the
        // admin isn't left locked out — previously only RESTORE cleared a breach, and
        // RESTORE reverts files to the old baseline (undoing legitimate changes).
        if ($is_breach) {
            smackback_resolve_breach('manual');
        }
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
    header('Location: smack-back.php?msg=' . urlencode($msg));
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
    header('Location: smack-back.php?msg=' . urlencode($summary));
    exit;
}

// SAVE SKIN JS SETTINGS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_skin_js_settings'] ?? '') === '1') {
    $allow = ($_POST['skin_allow_custom_js'] ?? '0') === '1' ? '1' : '0';
    $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('skin_allow_custom_js', ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    )->execute([$allow]);
    header('Location: smack-back.php?msg=Skin+JS+settings+saved.');
    exit;
}

// RE-INITIALISE BASELINE FROM DISK
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['reinit_baseline'] ?? '') === '1') {
    $ok = smackback_init_from_disk();
    if ($ok && $is_breach) {
        // Re-baselining accepts the current disk as authoritative, so the breach is
        // resolved. Clear it here — re-init alone never cleared smackback_status before,
        // which left the admin stuck on the breach screen with RESTORE (a code revert)
        // as the only exit. This path is distinct from RESTORE: no files are reverted.
        smackback_resolve_breach('reinit');
    }
    $msg = $ok ? 'Baseline re-initialised from disk. All files re-hashed.' : 'Re-init failed — check error log.';
    header('Location: smack-back.php?msg=' . urlencode($msg));
    exit;
}

// CONFIRM HUB-REQUESTED SMACKBACK DISABLE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['smackback_hub_confirm_disable'] ?? '') === '1') {
    $upsert = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)");
    $upsert->execute(['smackback_enabled',            '0']);
    $upsert->execute(['smackback_hub_pending_disable', '0']);
    header('Location: smack-back.php?msg=SMACKBACK+disabled+as+requested+by+hub.');
    exit;
}

// REJECT HUB-REQUESTED SMACKBACK DISABLE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['smackback_hub_reject_disable'] ?? '') === '1') {
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
        ->execute(['smackback_hub_pending_disable', '0']);
    header('Location: smack-back.php?msg=Hub+disable+request+rejected.+SMACKBACK+remains+active.');
    exit;
}

// CONFIRM HUB-REQUESTED SMACKBACK MODE DOWNGRADE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['smackback_hub_confirm_mode'] ?? '') === '1') {
    $upsert = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)");
    $upsert->execute(['smackback_mode',            'alert']);
    $upsert->execute(['smackback_hub_pending_mode', '']);
    header('Location: smack-back.php?msg=SMACKBACK+mode+changed+to+alert+as+requested+by+hub.');
    exit;
}

// REJECT HUB-REQUESTED SMACKBACK MODE DOWNGRADE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['smackback_hub_reject_mode'] ?? '') === '1') {
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
        ->execute(['smackback_hub_pending_mode', '']);
    header('Location: smack-back.php?msg=Hub+mode+change+rejected.+SMACKBACK+remains+in+lockout+mode.');
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

    header('Location: smack-back.php?msg=Settings+saved.');
    exit;
}

// SAVE NETWORK ALERT SETTINGS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_nalert'] ?? '') === '1') {
    require_once 'core/network-alert.php';
    $hub_owns_netalert = ($settings['hub_controls_netalert'] ?? '0') === '1';
    $na_push_enable = ($_POST['network_alert_push_enabled'] ?? '0') === '1';
    if (empty($_POST['network_alert_sc_url'])) $_POST['network_alert_sc_url'] = 'https://snapsmack.ca';
    $na_sc_url = trim($_POST['network_alert_sc_url'] ?? 'https://snapsmack.ca');
    if (empty($na_sc_url)) $na_sc_url = 'https://snapsmack.ca';

    $na_up = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    );

    // Only write send/receive if hub doesn't own them — otherwise it would wipe hub-set values
    if (!$hub_owns_netalert) {
        $na_send    = ($_POST['network_alert_send']    ?? '0') === '1' ? '1' : '0';
        $na_receive = ($_POST['network_alert_receive'] ?? '0') === '1' ? '1' : '0';
        $na_up->execute(['network_alert_send',    $na_send]);
        $na_up->execute(['network_alert_receive', $na_receive]);
        $na_up->execute(['network_alert_sc_url',  $na_sc_url]);
    }

    // ── Push subscription ─────────────────────────────────────────────────────
    // Load current push state to detect a change
    $cur_push_rows = $pdo->query(
        "SELECT setting_key, setting_val FROM snap_settings
         WHERE setting_key IN ('network_alert_push_enabled','network_alert_push_registered')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $was_push_enabled    = ($cur_push_rows['network_alert_push_enabled'] ?? '0') === '1';
    $is_push_registered  = ($cur_push_rows['network_alert_push_registered'] ?? '0') === '1';

    if ($na_push_enable && !$was_push_enabled) {
        // Turning on: save enabled flag, then register with SC
        $na_up->execute(['network_alert_push_enabled', '1']);
        $na_up->execute(['network_alert_push_unregister_pending', '0']);
        nalert_register_push($na_sc_url);
    } elseif (!$na_push_enable && $was_push_enabled) {
        // Turning off: mark unregister pending, attempt now, clear if successful
        $na_up->execute(['network_alert_push_enabled',            '0']);
        $na_up->execute(['network_alert_push_unregister_pending', '1']);
        if ($is_push_registered) {
            nalert_unregister_push($na_sc_url);
        }
    }
    // No change — leave push state alone

    header('Location: smack-back.php?msg=Network+alert+settings+saved.');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['nalert_check_now'] ?? '') === '1') {
    require_once 'core/network-alert.php';
    $na_sc_url = trim($settings['network_alert_sc_url'] ?? 'https://snapsmack.ca');
    $polled    = nalert_poll_sc($na_sc_url);
    $msg       = $polled ? "Checked — status: {$polled}" : 'Check failed (SC unreachable or not opted in to receive).';
    header('Location: smack-back.php?msg=' . urlencode($msg));
    exit;
}

// ─── LOAD PAGE DATA ─────────────────────────────────────────────────────────

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

$incidents = [];
try {
    $incidents = $pdo->query(
        "SELECT * FROM snap_smackback_log ORDER BY detected_at DESC LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { }

$skin_js_findings_json   = $settings['skin_js_violations_json']  ?? '[]';
$skin_js_scan_at         = $settings['skin_js_scan_at']           ?? '';
$skin_js_violation_count = (int)($settings['skin_js_violation_count'] ?? 0);
$skin_js_findings        = json_decode($skin_js_findings_json, true) ?? [];
$skin_allow_custom_js    = ($settings['skin_allow_custom_js'] ?? '0') === '1';

require_once 'core/network-alert.php';
$na = nalert_get_local();

// Push subscription state
$na_push_rows = [];
try {
    $na_push_rows = $pdo->query(
        "SELECT setting_key, setting_val FROM snap_settings
         WHERE setting_key IN (
             'network_alert_push_enabled',
             'network_alert_push_registered',
             'network_alert_push_unregister_pending'
         )"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) { }
$na_push_enabled    = ($na_push_rows['network_alert_push_enabled']            ?? '0') === '1';
$na_push_registered = ($na_push_rows['network_alert_push_registered']         ?? '0') === '1';
$na_push_unregpend  = ($na_push_rows['network_alert_push_unregister_pending'] ?? '0') === '1';

$na_status_labels = [
    'green'       => '<span style="color:var(--success,#5a9a5a)">&#9679; Green — no advisory</span>',
    'yellow_slow' => '<span style="color:#cc9900">&#9679; YELLOW (advisory) — network-wide alert active</span>',
    'yellow_fast' => '<span style="color:#ffcc00;font-weight:700;">&#9679; YELLOW FAST — coordinated threat detected</span>',
];
$na_status_display = $na_status_labels[$na['status']] ?? htmlspecialchars($na['status']);

$flash_msg = $_GET['msg'] ?? '';

// ─── PAGE RENDER ─────────────────────────────────────────────────────────────

$page_title = 'SMACKBACK';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>SMACKBACK — FILE INTEGRITY MONITOR</h2>

    <?php if ($flash_msg): ?>
        <div class="alert alert-success">> <?php echo htmlspecialchars($flash_msg); ?></div>
    <?php endif; ?>

    <!-- ── STATUS ──────────────────────────────────────────────────────────── -->
    <div class="box">
        <h3><?php
            if ($is_breach)        echo '⚠ BREACH DETECTED';
            elseif ($smack_enabled) echo 'STATUS';
            else                    echo 'STATUS';
        ?></h3>
        <div class="dash-grid">
            <div class="stat-box">
                <div class="stat-val"><?php
                    if (!$smack_enabled)  echo '<span class="dim">DISABLED</span>';
                    elseif ($is_breach)   echo '<span style="color:#cc2200">BREACH</span>';
                    else                  echo 'CLEAN';
                ?></div>
                <div class="stat-label">SMACKBACK STATUS</div>
            </div>
            <div class="stat-box">
                <div class="stat-val"><?php echo htmlspecialchars(strtoupper($smack_mode)); ?></div>
                <div class="stat-label">RESPONSE MODE</div>
            </div>
            <div class="stat-box">
                <div class="stat-val"><?php echo number_format($manifest_count); ?></div>
                <div class="stat-label">FILES MONITORED<?php if (!empty($skin_breakdown)): ?> <span class="dim" style="font-size:0.7rem;font-weight:400;">(<?php echo implode(', ', $skin_breakdown); ?>)</span><?php endif; ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-val" style="font-size:0.9rem;"><?php echo $smack_last_verify ? htmlspecialchars($smack_last_verify) : '<span class="dim">Never</span>'; ?></div>
                <div class="stat-label">LAST FULL VERIFY</div>
            </div>
        </div>
    </div>

    <?php if ($is_breach): ?>
    <!-- ── BREACH DETAIL ─────────────────────────────────────────────────── -->
    <div class="box" style="border-left: 4px solid #cc2200;">
        <h3 style="color:#cc2200">BREACH DETAIL</h3>
        <p class="dim" style="margin-bottom:16px;">Detected: <strong style="color:inherit"><?php echo htmlspecialchars($smack_breach_at ?: 'Unknown'); ?></strong></p>

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
        <div class="stat-row" style="display:grid;grid-template-columns:1fr 90px 130px;align-items:center;gap:16px;padding:10px 0;">
            <span style="font-family:monospace;font-size:0.88rem;"><?php echo $bp; ?></span>
            <span style="color:<?php echo $bcol; ?>;font-weight:700;"><?php echo $bst; ?></span>
            <a href="smack-back.php?action=restore&restore=<?php echo urlencode($entry['path'] ?? ''); ?>"
               class="btn-smack btn-warning"
               style="width:100%;margin-top:0;"
               onclick="return confirm('Restore <?php echo $bp; ?> from update server?');">
                RESTORE
            </a>
        </div>
        <?php endforeach; ?>

        <div class="form-action-row" style="margin-top:16px;">
            <a href="smack-back.php?restore_all=1"
               class="btn-smack btn-danger"
               onclick="return confirm('Restore all tampered files from the update server?');">
                RESTORE ALL TAMPERED FILES
            </a>
            <a href="smack-update.php" class="btn-smack">RUN FULL UPDATE INSTEAD</a>
        </div>
        <p class="dim" style="font-size:0.85rem;margin-top:12px;">
            After restoring, change your FTP credentials and check your hosting panel access logs.
        </p>
    </div>
    <?php endif; ?>

    <!-- ── MANUAL VERIFICATION ───────────────────────────────────────────── -->
    <div class="box">
        <h3>MANUAL VERIFICATION</h3>
        <p class="dim" style="margin-bottom:20px;">Hash all monitored files and compare against the stored baseline. Takes a fraction of a second on typical installs.</p>
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="run_verify" value="1">
            <button type="submit" class="master-update-btn">RUN FULL VERIFICATION NOW</button>
        </form>
    </div>

    <!-- ── INCIDENT LOG ──────────────────────────────────────────────────── -->
    <div class="box">
        <h3>INCIDENT LOG</h3>
        <?php if (empty($incidents)): ?>
            <p class="dim">No incidents recorded.</p>
        <?php else: ?>
            <?php foreach ($incidents as $inc): ?>
            <div class="stat-row" style="display:grid;grid-template-columns:1fr 1fr auto auto;gap:16px;align-items:center;padding:10px 0;font-size:0.88rem;">
                <span><?php echo htmlspecialchars($inc['detected_at']); ?></span>
                <span><?php echo $inc['resolved_at'] ? htmlspecialchars($inc['resolved_at']) : '<span class="dim">Open</span>'; ?></span>
                <span><?php echo (int)$inc['file_count']; ?> files</span>
                <span class="dim"><?php echo $inc['resolution'] ? htmlspecialchars($inc['resolution']) : '—'; ?></span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ── HUB PENDING DISABLE ───────────────────────────────────────────── -->
    <?php if ($smack_hub_pending_disable): ?>
    <div class="box" style="border:2px solid #cc6600;background:rgba(204,102,0,0.08);">
        <h3 style="color:#cc6600;">⚠ HUB HAS REQUESTED SMACKBACK BE DISABLED</h3>
        <p style="line-height:1.7;margin-bottom:20px;">
            Your network hub has pushed a request to turn off file integrity monitoring on this site.
            This was held for your confirmation because disabling SMACKBACK is a high-risk action —
            a compromised hub could use it to silence tamper detection before attacking a spoke.<br><br>
            <strong>Only approve if you made this change yourself from your own hub.</strong>
        </p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <form method="post" onsubmit="return confirm('Disable SMACKBACK on this site as requested by hub?');">
                <?php csrf_field(); ?>
                <input type="hidden" name="smackback_hub_confirm_disable" value="1">
                <button type="submit" class="btn-smack btn-danger">APPROVE — DISABLE SMACKBACK</button>
            </form>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="smackback_hub_reject_disable" value="1">
                <button type="submit" class="btn-smack">REJECT — KEEP SMACKBACK ACTIVE</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── HUB PENDING MODE DOWNGRADE ───────────────────────────────────── -->
    <?php if ($smack_hub_pending_mode !== ''): ?>
    <div class="box" style="border:2px solid #cc6600;background:rgba(204,102,0,0.08);">
        <h3 style="color:#cc6600;">⚠ HUB HAS REQUESTED SMACKBACK MODE CHANGE</h3>
        <p style="line-height:1.7;margin-bottom:20px;">
            Your network hub has pushed a request to change SMACKBACK protection mode from
            <strong>LOCKOUT</strong> to <strong><?php echo strtoupper(htmlspecialchars($smack_hub_pending_mode)); ?></strong>.
            This was held for your confirmation because downgrading protection mode is high-risk —
            a compromised hub could weaken tamper detection before attacking a spoke.<br><br>
            <strong>Only approve if you made this change yourself from your own hub.</strong>
        </p>
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <form method="post" onsubmit="return confirm('Change SMACKBACK mode as requested by hub?');">
                <?php csrf_field(); ?>
                <input type="hidden" name="smackback_hub_confirm_mode" value="1">
                <button type="submit" class="btn-smack btn-danger">APPROVE — CHANGE TO <?php echo strtoupper(htmlspecialchars($smack_hub_pending_mode)); ?></button>
            </form>
            <form method="post">
                <?php csrf_field(); ?>
                <input type="hidden" name="smackback_hub_reject_mode" value="1">
                <button type="submit" class="btn-smack">REJECT — KEEP LOCKOUT MODE</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── SETTINGS ─────────────────────────────────────────────────────── -->
    <div class="box">
        <h3>SETTINGS</h3>
        <?php if (($settings['hub_controls_smackback'] ?? '0') === '1'): ?>
        <div class="dash-grid">
            <div class="lens-input-wrapper">
                <label>ENABLED</label>
                <div class="read-only-display"><?php echo $smack_enabled ? 'YES' : 'NO'; ?></div>
                <span class="dim" style="font-size:0.75rem;margin-top:4px;display:block;">⊘ MANAGED BY NETWORK HUB</span>
            </div>
            <div class="lens-input-wrapper">
                <label>RESPONSE MODE</label>
                <div class="read-only-display"><?php echo htmlspecialchars(strtoupper($smack_mode)); ?></div>
            </div>
        </div>
        <p class="dim" style="font-size:0.85rem;margin-top:8px;">Enabled state and response mode are controlled by the network hub. Pageload check and alert email are yours to set.</p>
        <form method="post" style="margin-top:16px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="save_settings" value="1">
            <input type="hidden" name="smackback_enabled" value="<?php echo $smack_enabled ? '1' : '0'; ?>">
            <input type="hidden" name="smackback_mode" value="<?php echo htmlspecialchars($smack_mode); ?>">
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>
                        <input type="checkbox" name="smackback_pageload_check" value="1"<?php echo $smack_pageload ? ' checked' : ''; ?>>
                        PAGELOAD STAT CHECK
                    </label>
                    <span class="dim" style="font-size:0.82rem;">Check file mtimes on public page loads (very fast — no file reads unless mtime changed)</span>
                </div>
                <div class="lens-input-wrapper">
                    <label>ALERT EMAIL <span class="field-tip" data-tip="Leave blank to use the site admin email.">ⓘ</span></label>
                    <input type="email" name="smackback_alert_email"
                           value="<?php echo htmlspecialchars($smack_alert_email); ?>"
                           placeholder="<?php echo htmlspecialchars($settings['admin_email'] ?? $settings['site_email'] ?? ''); ?>">
                </div>
            </div>
            <div class="form-action-row">
                <button type="submit" class="master-update-btn">SAVE SETTINGS</button>
            </div>
        </form>
        <?php else: ?>
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="save_settings" value="1">

            <!-- ── MASTER SWITCH ── -->
            <div style="display:flex;align-items:center;gap:16px;padding:16px 0 20px;border-bottom:1px solid var(--border,#333);margin-bottom:24px;">
                <label class="toggle-switch" style="flex-shrink:0;margin:0;">
                    <input type="checkbox" id="smackback_enabled_toggle" name="smackback_enabled" value="1"<?php echo $smack_enabled ? ' checked' : ''; ?>>
                    <span class="toggle-slider"></span>
                </label>
                <div>
                    <div style="font-size:1rem;font-weight:700;letter-spacing:.08em;">ENABLE SMACKBACK</div>
                    <div class="dim" style="font-size:0.82rem;margin-top:2px;">File integrity monitoring. Hashes PHP, JS, and CSS at baseline; re-verifies on schedule and every admin login.</div>
                </div>
            </div>

            <!-- ── DEPENDENT SETTINGS ── -->
            <div id="smackback-sub-settings"<?php echo $smack_enabled ? '' : ' style="opacity:0.38;pointer-events:none;"'; ?>>
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <label>RESPONSE MODE</label>
                        <label class="radio-option" style="margin-bottom:8px;">
                            <input type="radio" name="smackback_mode" value="alert"<?php echo $smack_mode === 'alert' ? ' checked' : ''; ?>>
                            <strong>ALERT</strong> — banner in admin, no lockout
                        </label>
                        <label class="radio-option" style="margin-bottom:8px;">
                            <input type="radio" name="smackback_mode" value="lockout"<?php echo $smack_mode === 'lockout' ? ' checked' : ''; ?>>
                            <strong>LOCKOUT</strong> (recommended) — all admin pages redirect here until resolved
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="smackback_mode" value="paranoid"<?php echo $smack_mode === 'paranoid' ? ' checked' : ''; ?>>
                            <strong>PARANOID</strong> — lockout + hub breach reporting (Phase 2)
                        </label>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>PAGELOAD STAT CHECK</label>
                        <label class="toggle-switch" style="margin:8px 0;">
                            <input type="checkbox" name="smackback_pageload_check" value="1"<?php echo $smack_pageload ? ' checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="dim" style="font-size:0.82rem;">Check file mtimes on public page loads (very fast — no file reads unless mtime changed)</span>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>ALERT EMAIL <span class="field-tip" data-tip="Leave blank to use the site admin email.">ⓘ</span></label>
                        <input type="email" name="smackback_alert_email"
                               value="<?php echo htmlspecialchars($smack_alert_email); ?>"
                               placeholder="<?php echo htmlspecialchars($settings['admin_email'] ?? $settings['site_email'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <div class="form-action-row">
                <button type="submit" class="master-update-btn">SAVE SETTINGS</button>
            </div>
        </form>
        <script>
        (function(){
            var tog = document.getElementById('smackback_enabled_toggle');
            var sub = document.getElementById('smackback-sub-settings');
            if (!tog || !sub) return;
            tog.addEventListener('change', function(){
                sub.style.opacity       = this.checked ? '' : '0.38';
                sub.style.pointerEvents = this.checked ? '' : 'none';
            });
        })();
        </script>
        <?php endif; // hub_controls_smackback ?>
    </div>

    <!-- ── NETWORK ALERT ─────────────────────────────────────────────────── -->
    <div class="box" id="network-alert">
        <h3>NETWORK ALERT <span class="dim" style="font-weight:400;font-size:0.8rem;">(Layer 2 — Smack Central global)</span></h3>
        <p class="dim" style="margin-bottom:20px;line-height:1.7;font-size:0.88rem;">
            Opt-in to the SnapSmack network alert system. If Smack Central detects a coordinated
            breach affecting multiple installs, it broadcasts a YELLOW alert to all opted-in sites.
            Entirely separate from your local SMACKBACK RED alerts — those never leave your server.
        </p>

        <div class="dash-grid" style="margin-bottom:20px;">
            <div class="stat-box">
                <div class="stat-val" style="font-size:0.9rem;"><?php echo $na_status_display; ?><?php if ($na['since']): ?> <span class="dim" style="font-size:0.75rem;">since <?php echo htmlspecialchars($na['since']); ?></span><?php endif; ?></div>
                <div class="stat-label">CURRENT NETWORK STATUS</div>
            </div>
            <div class="stat-box">
                <div class="stat-val" style="font-size:0.9rem;"><?php echo $na['last_checked'] ? htmlspecialchars($na['last_checked']) : '<span class="dim">Never</span>'; ?></div>
                <div class="stat-label">LAST CHECKED
                    <form method="post" style="display:inline;margin-left:10px;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="nalert_check_now" value="1">
                        <button type="submit" class="btn-smack" style="font-size:0.75rem;padding:2px 10px;">CHECK NOW</button>
                    </form>
                </div>
            </div>
            <?php if ($na['message']): ?>
            <div class="stat-box">
                <div class="stat-val" style="font-size:0.9rem;"><?php echo htmlspecialchars($na['message']); ?></div>
                <div class="stat-label">SC MESSAGE</div>
            </div>
            <?php endif; ?>
        </div>

        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="save_nalert" value="1">
            <?php if (($settings['hub_controls_netalert'] ?? '0') === '1'): ?>
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>CONTRIBUTE BREACH REPORTS</label>
                    <div class="read-only-display"><?php echo $na['send'] ? 'YES' : 'NO'; ?></div>
                    <span class="dim" style="font-size:0.75rem;margin-top:4px;display:block;">⊘ MANAGED BY NETWORK HUB</span>
                </div>
                <div class="lens-input-wrapper">
                    <label>RECEIVE YELLOW ALERTS</label>
                    <div class="read-only-display"><?php echo $na['receive'] ? 'YES' : 'NO'; ?></div>
                </div>
                <div class="lens-input-wrapper">
                    <label>SMACK CENTRAL URL</label>
                    <div class="read-only-display"><?php echo htmlspecialchars($na['sc_url']); ?></div>
                </div>
            </div>
            <p class="dim" style="font-size:0.85rem;margin-top:8px;">Send and receive settings are controlled by the network hub. Push subscription below is yours to manage.</p>
            <?php else: ?>
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>
                        <input type="checkbox" name="network_alert_send" value="1"<?php echo $na['send'] ? ' checked' : ''; ?>>
                        CONTRIBUTE BREACH REPORTS TO THE NETWORK
                    </label>
                    <span class="dim" style="font-size:0.82rem;">Reports contain: site name, server IP, affected file paths, timestamps, and SHA-256 hashes. No visitor data, no content.</span>
                </div>
                <div class="lens-input-wrapper">
                    <label>
                        <input type="checkbox" name="network_alert_receive" value="1"<?php echo $na['receive'] ? ' checked' : ''; ?>>
                        RECEIVE YELLOW ALERTS
                    </label>
                    <span class="dim" style="font-size:0.82rem;">Show SC network alerts in the admin panel. You can receive without sending (courtesy opt-in).</span>
                </div>
                <div class="lens-input-wrapper">
                    <label>SMACK CENTRAL URL <span class="field-tip" data-tip="Only change this if you run a private SC instance.">ⓘ</span></label>
                    <input type="url" name="network_alert_sc_url" value="<?php echo htmlspecialchars($na['sc_url']); ?>">
                </div>
            </div>
            <?php endif; // hub_controls_netalert ?>

            <!-- ── PUSH NOTIFICATIONS ──────────────────────────────────────── -->
            <div style="margin-top:24px;border-top:1px solid var(--border,#2a2a2a);padding-top:20px;">
                <div style="display:flex;align-items:flex-start;gap:16px;">
                    <label class="toggle-switch" style="flex-shrink:0;margin-top:2px;">
                        <input type="checkbox" name="network_alert_push_enabled" value="1"<?php echo $na_push_enabled ? ' checked' : ''; ?> id="nalert-push-toggle">
                        <span class="toggle-slider"></span>
                    </label>
                    <div>
                        <div style="font-weight:700;font-size:0.88rem;letter-spacing:0.05em;margin-bottom:6px;">
                            IMMEDIATE BREACH PUSH NOTIFICATIONS
                            <?php if ($na_push_enabled && $na_push_registered): ?>
                                <span style="color:var(--success,#5a9a5a);font-size:0.75rem;font-weight:400;margin-left:8px;">&#10003; Registered with SC</span>
                            <?php elseif ($na_push_enabled && !$na_push_registered): ?>
                                <span style="color:#cc9900;font-size:0.75rem;font-weight:400;margin-left:8px;">&#9888; Registration pending</span>
                            <?php elseif ($na_push_unregpend): ?>
                                <span style="color:#cc9900;font-size:0.75rem;font-weight:400;margin-left:8px;">&#9888; Removal pending SC confirmation</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:0.82rem;line-height:1.7;color:var(--text-dim,#888);max-width:640px;">
                            When enabled, Smack Central will push an alert directly to this site the moment
                            a coordinated breach is detected across the network — instead of waiting up to
                            30 minutes for the next poll.
                        </div>
                        <div style="margin-top:10px;padding:12px 16px;background:var(--bg-offset,#111);border-left:3px solid #cc9900;font-size:0.8rem;line-height:1.8;color:var(--text-dim,#888);max-width:640px;">
                            <strong style="color:var(--text,#ddd);display:block;margin-bottom:4px;">&#9432; Privacy disclosure — read before enabling</strong>
                            Enabling this transmits your <strong>site URL</strong> and <strong>site name</strong> to Smack Central,
                            where they are stored to enable delivery. A unique push token (generated locally on your server,
                            never derived from your URL) is also stored — SC uses it to authenticate pushes so no other party
                            can spoof a network alert to your site.<br><br>
                            This is the <strong>only</strong> information retained. No visitor data, no post content, no admin credentials.<br><br>
                            <strong>To opt out:</strong> turn this off and save. Your site will send a deletion request to SC on every admin
                            page load until SC confirms the record has been removed. You can verify removal by contacting
                            <a href="mailto:privacy@snapsmack.ca" style="color:var(--accent,#0af);">privacy@snapsmack.ca</a>.
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-action-row">
                <button type="submit" class="master-update-btn">SAVE NETWORK ALERT SETTINGS</button>
            </div>
        </form>
    </div>

    <!-- ── SKIN JS SECURITY SCAN ────────────────────────────────────────── -->
    <div class="box">
        <h3>SKIN JS SECURITY SCAN</h3>
        <p class="dim" style="margin-bottom:16px;line-height:1.7;font-size:0.88rem;">
            Scans all non-base installed skins for inline JavaScript, <code>eval()</code> calls,
            <code>atob()</code>, <code>document.write()</code>, and external scripts loaded from
            untrusted domains. Base skins are always trusted.
            <strong>Violations</strong> indicate active risk. <strong>Warnings</strong> are suspicious but may be legitimate.
        </p>

        <div class="dash-grid" style="margin-bottom:20px;">
            <div class="stat-box">
                <div class="stat-val" style="font-size:0.9rem;">
                    <?php if ($skin_js_scan_at): ?>
                        <?php echo htmlspecialchars($skin_js_scan_at); ?>
                        —
                        <?php if ($skin_js_violation_count > 0): ?>
                            <span style="color:#cc2200"><?php echo $skin_js_violation_count; ?> violation(s)</span>
                        <?php else: ?>
                            <span class="msg">No violations</span>
                        <?php endif; ?>
                        <span class="dim">(<?php
                            $wc = count(array_filter($skin_js_findings, fn($f) => $f['severity'] === 'warning'));
                            echo $wc . ' warning' . ($wc !== 1 ? 's' : '');
                        ?>)</span>
                    <?php else: ?>
                        <span class="dim">Never scanned</span>
                    <?php endif; ?>
                </div>
                <div class="stat-label">LAST SCAN
                    <form method="post" style="display:inline;margin-left:10px;">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="run_skin_js_scan" value="1">
                        <button type="submit" class="btn-smack" style="font-size:0.75rem;padding:2px 10px;">SCAN NOW</button>
                    </form>
                </div>
            </div>
        </div>

        <?php if (!empty($skin_js_findings)):
            $by_skin = [];
            foreach ($skin_js_findings as $f) { $by_skin[$f['skin']][] = $f; }
            foreach ($by_skin as $slug => $skin_findings):
                $sv = count(array_filter($skin_findings, fn($f) => $f['severity'] === 'violation'));
                $sw = count(array_filter($skin_findings, fn($f) => $f['severity'] === 'warning'));
                $si = count(array_filter($skin_findings, fn($f) => $f['severity'] === 'info'));
        ?>
            <details style="margin-bottom:12px;" <?php echo $sv > 0 ? 'open' : ''; ?>>
                <summary style="cursor:pointer;font-weight:700;font-size:0.9rem;letter-spacing:1px;padding:8px 0;">
                    <?php echo htmlspecialchars($slug); ?>
                    <?php if ($sv > 0): ?><span style="margin-left:8px;color:#cc2200;font-size:0.8rem;"><?php echo $sv; ?> VIOLATION<?php echo $sv !== 1 ? 'S' : ''; ?></span><?php endif; ?>
                    <?php if ($sw > 0): ?><span style="margin-left:6px;color:#cc9900;font-size:0.8rem;"><?php echo $sw; ?> WARNING<?php echo $sw !== 1 ? 'S' : ''; ?></span><?php endif; ?>
                    <?php if ($si > 0 && $sv === 0 && $sw === 0): ?><span style="margin-left:6px;color:#888;font-size:0.8rem;"><?php echo $si; ?> INFO</span><?php endif; ?>
                </summary>
                <?php foreach ($skin_findings as $f):
                    $sev_color = match($f['severity']) {
                        'violation' => '#cc2200',
                        'warning'   => '#cc9900',
                        default     => '#888',
                    };
                ?>
                <div class="stat-row" style="display:grid;grid-template-columns:80px 140px 1fr 60px 1fr;gap:10px;align-items:center;padding:8px 0;font-size:0.82rem;">
                    <span style="color:<?php echo $sev_color; ?>;font-weight:700;text-transform:uppercase;"><?php echo htmlspecialchars($f['severity']); ?></span>
                    <span style="font-family:monospace;"><?php echo htmlspecialchars($f['type']); ?></span>
                    <span style="font-family:monospace;font-size:0.78rem;"><?php echo htmlspecialchars($f['file']); ?></span>
                    <span style="text-align:center;"><?php echo (int)$f['line']; ?></span>
                    <span class="dim"><?php echo htmlspecialchars($f['detail']); ?></span>
                </div>
                <?php endforeach; ?>
            </details>
        <?php endforeach;
        elseif ($skin_js_scan_at): ?>
            <p class="msg" style="margin-bottom:16px;">✓ No findings — all installed skins are clean.</p>
        <?php endif; ?>

        <form method="post" style="margin-top:16px;">
            <?php csrf_field(); ?>
            <input type="hidden" name="save_skin_js_settings" value="1">
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>
                        <input type="checkbox" name="skin_allow_custom_js" value="1"<?php echo $skin_allow_custom_js ? ' checked' : ''; ?>>
                        ALLOW CUSTOM JS IN SKINS
                    </label>
                    <span class="dim" style="font-size:0.82rem;">Permits inline scripts and external JS in third-party skins. <code>eval()</code> is always flagged regardless.</span>
                </div>
            </div>
            <div class="form-action-row">
                <button type="submit" class="master-update-btn">SAVE SKIN JS SETTINGS</button>
            </div>
        </form>
    </div>

    <!-- ── RE-INITIALISE BASELINE ────────────────────────────────────────── -->
    <div class="box">
        <h3>RE-INITIALISE BASELINE</h3>
        <p class="dim" style="margin-bottom:20px;line-height:1.7;">
            Re-hash all monitored files from disk and update the baseline.
            Use this after a legitimate manual file edit.
            <strong>Do not use this if a breach is active</strong> — it would bless the tampered files.
        </p>
        <form method="post"
              onsubmit="return confirm('Re-initialise from disk? Only do this after a verified legitimate change. Do NOT use during an active breach.');">
            <?php csrf_field(); ?>
            <input type="hidden" name="reinit_baseline" value="1">
            <button type="submit" class="btn-smack btn-danger">RE-INITIALISE BASELINE FROM DISK</button>
        </form>
    </div>

</div><!-- .main -->

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
