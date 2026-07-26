<?php
/**
 * SNAPSMACK — Break-Glass Card administration
 *
 * Generates or rotates the signed, site-bound, one-use recovery card.
 * Card creation is gated by the standard password + TOTP step-up check.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once __DIR__ . '/core/auth-smack.php';
require_once __DIR__ . '/core/reauth.php';
require_once __DIR__ . '/core/break-glass.php';

$page_title   = 'Break the Glass';
$current_page = 'smack-break-glass.php';
$message      = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $check = reauth_verify($pdo, (string)($_POST['password'] ?? ''), (string)($_POST['totp_code'] ?? ''));
    if (!$check['ok']) {
        $message      = $check['error'];
        $message_type = 'error';
    } else {
        try {
            $card = break_glass_generate($pdo, (int)$_SESSION['user_id']);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . SNAP_BREAK_GLASS_FILENAME . '"');
            header('Content-Length: ' . strlen($card['contents']));
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            echo $card['contents'];
            exit;
        } catch (Throwable $e) {
            error_log('SnapSmack Break-Glass generation failed: ' . $e->getMessage());
            $message      = 'The card could not be generated. Nothing was rotated.';
            $message_type = 'error';
        }
    }
}

$status       = break_glass_setting_get($pdo, 'break_glass_status', 'not-generated');
$generated_at = break_glass_setting_get($pdo, 'break_glass_generated_at');
$consumed_at  = break_glass_setting_get($pdo, 'break_glass_consumed_at');

require_once __DIR__ . '/core/admin-header.php';
require_once __DIR__ . '/core/sidebar.php';
?>
<div class="main">
    <div class="header-row"><h2>BREAK THE GLASS</h2></div>

    <?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type === 'error' ? 'error' : 'success'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <div class="box">
        <h3>TOTAL-LOCKOUT RECOVERY CARD</h3>
        <p class="skin-desc-text">
            This is the recovery hatch for losing your password, authenticator, and recovery codes
            at the same time. It is cryptographically signed, bound to this installation, and works
            once. Keep it offline. Anyone holding the file can take control of this site.
        </p>
        <p><strong>STATUS:</strong> <?php echo htmlspecialchars(strtoupper($status)); ?>
        <?php if ($generated_at): ?><br><strong>GENERATED:</strong> <?php echo htmlspecialchars($generated_at); ?><?php endif; ?>
        <?php if ($consumed_at): ?><br><strong>LAST USED:</strong> <?php echo htmlspecialchars($consumed_at); ?><?php endif; ?></p>
    </div>

    <div class="box">
        <h3><?php echo $status === 'active' ? 'ROTATE CARD' : 'GENERATE CARD'; ?></h3>
        <p class="skin-desc-text">
            Generating a card immediately revokes every older card for this site. The download is
            the only copy: SnapSmack keeps the public verification key, never the recovery file or
            a reusable private signing key.
        </p>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="generate">
            <div class="control-group" style="max-width:420px">
                <label>CURRENT PASSWORD</label>
                <input type="password" name="password" autocomplete="current-password" required>
            </div>
            <div class="control-group" style="max-width:240px">
                <label>AUTHENTICATOR CODE</label>
                <input type="text" name="totp_code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required>
            </div>
            <button type="submit" class="master-update-btn"
                    onclick="return confirm('Generate a new Break-Glass Card and revoke the old one?');">
                <?php echo $status === 'active' ? 'ROTATE & DOWNLOAD CARD' : 'GENERATE & DOWNLOAD CARD'; ?>
            </button>
        </form>
    </div>

    <div class="box">
        <h3>WHEN EVERYTHING IS GONE</h3>
        <ol class="skin-desc-text">
            <li>Upload <code><?php echo SNAP_BREAK_GLASS_FILENAME; ?></code> to the SnapSmack web root by FTP. Do not rename it.</li>
            <li>Visit <code>/break-glass.php</code>.</li>
            <li>Verify the site and account shown, then type <code>BURN IT</code>.</li>
            <li>Set a new password and enrol 2FA again.</li>
            <li>Generate a replacement card and remove every stray copy of the consumed one.</li>
        </ol>
    </div>
</div>
<?php require_once __DIR__ . '/core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
