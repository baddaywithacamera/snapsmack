<?php
/**
 * SNAPSMACK - Logged-in User Profile & Default Signature
 *
 * Lets any authenticated user (admin or editor) update their own display name,
 * email address, and per-user default closing signatures (light & dark surface).
 * Scoped strictly to $_SESSION['user_id'] so no user can modify another user's
 * signature or account.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once 'core/auth-smack.php';
require_once 'core/signature-contract.php';

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    header('Location: smack-admin.php');
    exit;
}

snap_user_signature_ensure_columns($pdo);

$msg      = '';
$msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $display_name = trim((string)($_POST['display_name'] ?? ''));
    $email        = trim((string)($_POST['email'] ?? ''));
    $light_raw    = snap_signature_default_sanitise($_POST['default_signature_light_image_id'] ?? '');
    $dark_raw     = snap_signature_default_sanitise($_POST['default_signature_dark_image_id'] ?? '');
    $light_val    = ($light_raw !== '') ? (int)$light_raw : null;
    $dark_val     = ($dark_raw !== '') ? (int)$dark_raw : null;

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg      = 'Please enter a valid email address.';
        $msg_type = 'error';
    } else {
        $upd = $pdo->prepare(
            "UPDATE snap_users
             SET display_name = NULLIF(?, ''),
                 email = ?,
                 default_signature_light_image_id = ?,
                 default_signature_dark_image_id = ?
             WHERE id = ?"
        );
        $upd->execute([$display_name, $email, $light_val, $dark_val, $uid]);

        $_SESSION['user_default_signature_light_image_id'] = $light_val ?? 0;
        $_SESSION['user_default_signature_dark_image_id']  = $dark_val ?? 0;
        $msg = 'Profile and default signature saved.';
    }
}

$stmt = $pdo->prepare(
    "SELECT id, username, display_name, email, user_role,
            default_signature_light_image_id, default_signature_dark_image_id
     FROM snap_users WHERE id = ? LIMIT 1"
);
$stmt->execute([$uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('User not found.');
}

$page_title   = 'My Profile';
$current_page = 'smack-profile.php';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>MY PROFILE: <?php echo htmlspecialchars(strtoupper((string)$user['username'])); ?></h2>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="alert <?php echo $msg_type === 'error' ? 'alert-error' : 'alert-success'; ?>">
            <?php echo htmlspecialchars($msg); ?>
        </div>
    <?php endif; ?>

    <div class="box">
        <h3>ACCOUNT &amp; DEFAULT SIGNATURE</h3>
        <form method="POST">
            <label>USERNAME (IMMUTABLE)</label>
            <div class="read-only-display"><?php echo htmlspecialchars((string)$user['username']); ?></div>

            <label>DISPLAY NAME</label>
            <input type="text" name="display_name" maxlength="100" value="<?php echo htmlspecialchars((string)($user['display_name'] ?? '')); ?>">

            <label>EMAIL ADDRESS</label>
            <input type="email" name="email" value="<?php echo htmlspecialchars((string)($user['email'] ?? '')); ?>" required>

            <?php snap_user_signature_default_control($pdo, $user); ?>

            <button type="submit" name="save_profile" class="master-update-btn">SAVE PROFILE</button>
        </form>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
