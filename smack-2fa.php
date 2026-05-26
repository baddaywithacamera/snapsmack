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
}

// Build QR provisioning URI if there's a pending secret
$totp_uri = '';
$qr_url   = '';
if ($pending_secret) {
    $label    = $user['username'] . ($user['email'] ? ' (' . $user['email'] . ')' : '');
    $totp_uri = totp_uri($pending_secret, $label);
    $qr_url   = totp_qr_url($totp_uri);
}

require_once 'core/admin-header.php';
require_once 'core/sidebar.php';
?>

<div class="main">
        <div class="page-header-row">
            <h1 class="page-title">Two-Factor Authentication</h1>
        </div>

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
            <button type="button" id="copy-recovery-codes-btn" class="ss-btn ss-btn-secondary">Copy All Codes</button>
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
                <button type="submit" class="ss-btn ss-btn-secondary">Generate New Recovery Codes</button>
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
                <button type="submit" class="ss-btn ss-btn-danger"
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
                <div style="display: flex; gap: 12px; align-items: center;">
                    <button type="submit" class="master-update-btn">Activate 2FA</button>
                    <a href="smack-2fa.php" class="ss-btn ss-btn-secondary">Start Over</a>
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
            <p class="setting-desc">You'll need an authenticator app: Google Authenticator, Authy, 1Password, Bitwarden, or any TOTP-compatible app.</p>
            <form method="POST" style="margin-top: 20px;">
                <input type="hidden" name="action" value="generate">
                <button type="submit" class="master-update-btn">Set Up Two-Factor Authentication</button>
            </form>
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
