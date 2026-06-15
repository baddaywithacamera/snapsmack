<?php
/**
 * SNAPSMACK - Two-Factor Authentication Setup
 *
 * Allows admin users to enable, verify, and disable TOTP 2FA on their account.
 * Recovery codes are generated at setup time and shown once.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';
require_once 'core/db.php';
require_once 'core/totp.php';

$page_title   = '2FA Setup';
$current_page = 'smack-2fa.php';

// Load current user's 2FA state
$stmt = $pdo->prepare(
    "SELECT id, username, email, totp_secret, totp_enabled, totp_recovery_json
     FROM snap_users WHERE id = ? LIMIT 1"
);
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$is_enabled     = !empty($user['totp_enabled']);
$pending_secret = $_SESSION['totp_pending_secret'] ?? null;  // secret generated but not yet confirmed
$message        = '';
$message_type   = '';
$new_codes      = null; // plain recovery codes to show once

// ── POST handler ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Generate a new secret (step 1 of setup) ────────────────────────────
    if ($action === 'generate' && !$is_enabled) {
        $secret = totp_generate_secret();
        $_SESSION['totp_pending_secret'] = $secret;
        $pending_secret = $secret;
    }

    // ── Confirm setup by verifying first code (step 2 of setup) ───────────
    elseif ($action === 'confirm' && !$is_enabled && $pending_secret) {
        $submitted = trim($_POST['totp_code'] ?? '');
        if (totp_verify($pending_secret, $submitted)) {
            // Generate recovery codes
            $codes      = totp_generate_recovery_codes();
            $new_codes  = $codes['plain'];
            $json       = json_encode($codes['hashed']);

            $pdo->prepare(
                "UPDATE snap_users
                 SET totp_secret = ?, totp_enabled = 1, totp_recovery_json = ?
                 WHERE id = ?"
            )->execute([$pending_secret, $json, $_SESSION['user_id']]);

            unset($_SESSION['totp_pending_secret']);
            $pending_secret = null;
            $is_enabled     = true;

            // Reload user
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $message      = '2FA is now active on your account. Save your recovery codes — they are shown once only.';
            $message_type = 'success';
        } else {
            $message      = 'Code did not match. Check your authenticator app and try again.';
            $message_type = 'error';
        }
    }

    // ── Disable 2FA (requires valid TOTP code to confirm intent) ──────────
    elseif ($action === 'disable' && $is_enabled) {
        $submitted = trim($_POST['totp_code'] ?? '');
        if (totp_verify($user['totp_secret'], $submitted)) {
            $pdo->prepare(
                "UPDATE snap_users
                 SET totp_secret = NULL, totp_enabled = 0, totp_recovery_json = NULL
                 WHERE id = ?"
            )->execute([$_SESSION['user_id']]);

            $is_enabled = false;
            $message      = '2FA has been disabled on your account.';
            $message_type = 'success';

            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $message      = 'Incorrect code. 2FA has not been disabled.';
            $message_type = 'error';
        }
    }

    // ── Regenerate recovery codes (requires valid TOTP) ───────────────────
    elseif ($action === 'regen_recovery' && $is_enabled) {
        $submitted = trim($_POST['totp_code'] ?? '');
        if (totp_verify($user['totp_secret'], $submitted)) {
            $codes     = totp_generate_recovery_codes();
            $new_codes = $codes['plain'];
            $json      = json_encode($codes['hashed']);

            $pdo->prepare(
                "UPDATE snap_users SET totp_recovery_json = ? WHERE id = ?"
            )->execute([$json, $_SESSION['user_id']]);

            $message      = 'New recovery codes generated. Save them — the old ones no longer work.';
            $message_type = 'success';

            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $message      = 'Incorrect code. Recovery codes were not changed.';
            $message_type = 'error';
        }
    }
    // ── Revoke a trusted device ───────────────────────────
    elseif ($action === 'revoke_device') {
        $device_id = (int)($_POST['device_id'] ?? 0);
        if ($device_id > 0) {
            try {
                $pdo->prepare(
                    "DELETE FROM snap_totp_devices WHERE id = ? AND user_id = ?"
                )->execute([$device_id, $_SESSION['user_id']]);
                $message      = 'Trusted device removed.';
                $message_type = 'success';
            } catch (PDOException $e) { /* table may not exist yet */ }
        }
    }

    // ── Revoke all trusted devices ───────────────────────
    elseif ($action === 'revoke_all_devices') {
        try {
            $pdo->prepare(
                "DELETE FROM snap_totp_devices WHERE user_id = ?"
            )->execute([$_SESSION['user_id']]);
            $message      = 'All trusted devices removed.';
            $message_type = 'success';
        } catch (PDOException $e) { /* table may not exist yet */ }
    }
}

// Load trusted devices for this user (only shown when 2FA is enabled)
$trusted_devices = [];
if ($is_enabled) {
    try {
        $td_stmt = $pdo->prepare(
            "SELECT id, device_hint, created_at, expires_at
             FROM snap_totp_devices
             WHERE user_id = ? AND expires_at > NOW()
             ORDER BY created_at DESC"
        );
        $td_stmt->execute([$_SESSION['user_id']]);
        $trusted_devices = $td_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* table may not exist yet on older installs */ }
}

// Build QR provisioning URI if there's a pending secret
$totp_uri = '';
$qr_url   = '';
if ($pending_secret) {
    $label    = $user['username'] . ($user['email'] ? ' (' . $user['email'] . ')' : '');
    $totp_uri = totp_uri($pending_secret, $label);
    $qr_url   = totp_qr_url($totp_uri);
}

// --- FORCE-2FA COUNTDOWN (spec #1) ---
// Days remaining in the 30-day grace window from installed_at. <=0 = overdue.
$twofa_days_left   = null;
$twofa_enforced    = false;
try {
    $_ia = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'installed_at' LIMIT 1")->fetchColumn();
    if ($_ia && ($_ia_ts = strtotime((string)$_ia))) {
        $_deadline       = $_ia_ts + (30 * 86400);
        $twofa_days_left = (int)ceil(($_deadline - time()) / 86400);
        $twofa_enforced  = time() > $_deadline;
    }
} catch (PDOException $e) { /* non-fatal */ }
$twofa_required_now = $twofa_enforced || (($_GET['enrol'] ?? '') === 'required');
?>
<?php
require_once 'core/admin-header.php';
require_once 'core/sidebar.php';
?>

<div class="main">
        <div class="page-header-row">
            <h1 class="page-title">Two-Factor Authentication</h1>
        </div>

        <?php if (!$is_enabled && $twofa_required_now): ?>
            <div class="alert alert-error">
                <strong>Two-factor authentication is now required.</strong>
                Set it up below to continue using the admin. (Lost your authenticator and recovery codes?
                Ask the site owner to place the <code>core/release-2fa-override</code> emergency file.)
            </div>
        <?php elseif (!$is_enabled && $twofa_days_left !== null): ?>
            <div class="alert alert-warning">
                <strong>Two-factor authentication will be required in
                <?php echo max(0, (int)$twofa_days_left); ?> day<?php echo (int)$twofa_days_left === 1 ? '' : 's'; ?>.</strong>
                Set it up now to avoid an interruption at login.
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($new_codes): ?>
        <!-- ── Recovery codes — shown once ──────────────────────────────── -->
        <div class="setting-section setting-section-highlight">
            <h2 class="section-title">Your Recovery Codes</h2>
            <p class="setting-desc">
                These codes let you access your account if you lose your authenticator app.
                Each code works once. Save them somewhere safe — they will not be shown again.
            </p>
            <div class="recovery-codes-grid">
                <?php foreach ($new_codes as $code): ?>
                    <code class="recovery-code-item"><?php echo htmlspecialchars($code); ?></code>
                <?php endforeach; ?>
            </div>
            <button type="button" id="copy-recovery-codes-btn" class="btn-smack">Copy All Codes</button>
            <span id="copy-confirm" class="copy-confirm-msg">Copied.</span>
        </div>
        <?php endif; ?>

        <?php if ($is_enabled): ?>
        <!-- ── 2FA is active ─────────────────────────────────────────────── -->
        <div class="setting-section">
            <div class="status-row">
                <span class="totp-status-badge active">● ACTIVE</span>
                <h2 class="section-title">Two-Factor Authentication Is On</h2>
            </div>
            <p class="setting-desc">Your account requires a 6-digit code from your authenticator app on every login.</p>
        </div>

        <div class="setting-section">
            <h2 class="section-title">Regenerate Recovery Codes</h2>
            <p class="setting-desc">Use this if your existing recovery codes are lost or compromised. Current codes are invalidated immediately.</p>
            <form method="POST" style="margin-top: 16px;">
                <input type="hidden" name="action" value="regen_recovery">
                <div class="control-group" style="max-width: 280px;">
                    <label>CONFIRM WITH AUTHENTICATOR CODE</label>
                    <input type="text" name="totp_code" maxlength="6" pattern="\d{6}"
                           inputmode="numeric" autocomplete="one-time-code"
                           placeholder="000000" required>
                </div>
                <button type="submit" class="btn-smack">Generate New Recovery Codes</button>
            </form>
        </div>

        <div class="setting-section setting-section-danger-zone">
            <h2 class="section-title section-title-danger">Disable Two-Factor Authentication</h2>
            <p class="setting-desc">Removing 2FA makes your account less secure. You will need your current authenticator code to confirm.</p>
            <form method="POST" style="margin-top: 16px;">
                <input type="hidden" name="action" value="disable">
                <div class="control-group" style="max-width: 280px;">
                    <label>AUTHENTICATOR CODE</label>
                    <input type="text" name="totp_code" maxlength="6" pattern="\d{6}"
                           inputmode="numeric" autocomplete="one-time-code"
                           placeholder="000000" required>
                </div>
                <button type="submit" class="btn-smack btn-danger"
                        onclick="return confirm('Disable 2FA? Your account will only be protected by your password.');">
                    Disable 2FA
                </button>
            </form>
        </div>

        <?php elseif ($pending_secret): ?>
        <!-- ── Step 2: scan QR and confirm ──────────────────────────────── -->
        <div class="setting-section">
            <h2 class="section-title">Scan the QR Code</h2>
            <p class="setting-desc">Open your authenticator app (Google Authenticator, Authy, 1Password, Bitwarden — any TOTP app) and scan the code below. Then enter the 6-digit code it shows to confirm setup.</p>

            <div class="totp-qr-row">
                <div>
                    <canvas id="totp-qr-canvas"
                            style="display: block; border: 4px solid var(--bg-elevated);">
                    </canvas>
                </div>
                <div>
                    <p class="setting-desc"><strong>Can't scan?</strong> Enter this key manually:</p>
                    <code class="totp-manual-key"><?php echo htmlspecialchars(chunk_split($pending_secret, 4, ' ')); ?></code>
                    <p class="setting-desc setting-desc-sm">
                        Account: <strong><?php echo htmlspecialchars($user['username']); ?></strong><br>
                        Type: Time-based (TOTP) &nbsp;·&nbsp; Digits: 6 &nbsp;·&nbsp; Period: 30s
                    </p>
                </div>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="confirm">
                <div class="control-group" style="max-width: 280px;">
                    <label>ENTER 6-DIGIT CODE TO CONFIRM</label>
                    <input type="text" name="totp_code" maxlength="6" pattern="\d{6}"
                           inputmode="numeric" autocomplete="one-time-code"
                           placeholder="000000" autofocus required>
                </div>
                <div style="display: flex; gap: 12px; align-items: stretch;">
                    <button type="submit" class="master-update-btn" style="flex: 2; width: auto; margin-top: 0;">ACTIVATE 2FA</button>
                    <a href="smack-2fa.php" class="btn-smack btn-danger" style="flex: 1; width: auto; margin-top: 0;">START OVER</a>
                </div>
            </form>
        </div>

        <?php else: ?>
        <!-- ── Step 1: not set up yet ────────────────────────────────────── -->
        <div class="setting-section">
            <div class="status-row">
                <span class="totp-status-badge inactive">○ INACTIVE</span>
                <h2 class="section-title">Two-Factor Authentication Is Off</h2>
            </div>
            <p class="setting-desc">Add a second layer of security to your account. After enabling, every login will require your password <em>plus</em> a 6-digit code from your authenticator app.</p>
            <div class="setting-desc" style="margin-top:12px;">
                <p style="margin:0 0 6px;">Any TOTP (RFC 6238) authenticator app works. Scan the QR or enter the key. Recommended:</p>
                <p style="margin:0 0 4px;"><strong>Open-source (recommended):</strong>
                    Aegis Authenticator (Android),
                    Ente Auth (iOS/Android/desktop),
                    2FAS (iOS/Android).</p>
                <p style="margin:0;"><strong>Also fine:</strong>
                    Google Authenticator, Microsoft Authenticator, 1Password, Authy, Bitwarden.</p>
            </div>
            <form method="POST" style="margin-top: 20px;">
                <input type="hidden" name="action" value="generate">
                <button type="submit" class="master-update-btn">Set Up Two-Factor Authentication</button>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($is_enabled): ?>
        <div class="setting-section">
            <h2 class="section-title">Trusted Devices</h2>
            <p class="setting-desc">Devices where you checked &ldquo;Trust this device&rdquo; at login. These skip the authenticator code prompt until the trust expires.</p>

            <?php if (empty($trusted_devices)): ?>
                <p class="setting-desc" style="margin-top:12px;"><em>No trusted devices.</em></p>
            <?php else: ?>
                <table class="smack-table" style="margin-top:14px;">
                    <thead>
                        <tr>
                            <th>Device</th>
                            <th>Trusted On</th>
                            <th>Expires</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($trusted_devices as $td): ?>
                        <tr>
                            <td style="font-size:0.8rem;word-break:break-all;max-width:320px;"><?php echo htmlspecialchars($td['device_hint'] ?: 'Unknown device'); ?></td>
                            <td style="white-space:nowrap;font-size:0.8rem;"><?php echo htmlspecialchars(date('M j, Y', strtotime($td['created_at']))); ?></td>
                            <td style="white-space:nowrap;font-size:0.8rem;"><?php echo htmlspecialchars(date('M j, Y', strtotime($td['expires_at']))); ?></td>
                            <td>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="revoke_device">
                                    <input type="hidden" name="device_id" value="<?php echo (int)$td['id']; ?>">
                                    <button type="submit" class="btn-smack btn-smack-small btn-danger">REVOKE</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <form method="POST" style="margin-top:12px;">
                    <input type="hidden" name="action" value="revoke_all_devices">
                    <button type="submit" class="btn-smack btn-smack-small btn-danger"
                            onclick="return confirm('Revoke all trusted devices? You will need to enter your authenticator code on next login.')">
                        REVOKE ALL
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>

</div>

<?php require_once 'core/admin-footer.php'; ?>
<script src="assets/js/smack-admin-2fa.js"></script>
<?php if ($pending_secret && $totp_uri): ?>
<script>var _smackTotpUri = <?php echo json_encode($totp_uri); ?>;</script>
<script src="assets/js/smack-qr.min.js"></script>
<script>
(function () {
    'use strict';
    function renderQR() {
        var canvas = document.getElementById('totp-qr-canvas');
        if (!canvas || !window.SmackQR || !window._smackTotpUri) return;
        SmackQR.toCanvas(canvas, _smackTotpUri, {
            width: 220, margin: 2,
            color: { dark: '#000000', light: '#ffffff' }
        }, function (err) {
            if (err) canvas.insertAdjacentHTML('afterend',
                '<p style="color:red;font-size:12px">QR render failed — use the manual key below.</p>');
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderQR);
    } else {
        renderQR();
    }
}());
</script>
<?php endif; ?>
<?php // ===== SNAPSMACK EOF =====