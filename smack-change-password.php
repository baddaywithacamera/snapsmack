<?php
/**
 * SNAPSMACK - Forced Password Change
 * Alpha v0.7.9
 *
 * Shown after a recovery code login. The session flag force_password_change
 * must be set. Clears the flag and the DB column on successful save.
 */

require_once 'core/auth.php';
require_once 'core/auth-recovery.php';

// Must be reached via forced change — redirect away if not applicable.
if (empty($_SESSION['force_password_change'])) {
    header("Location: smack-dashboard.php");
    exit;
}

$err = '';
$uid = $_SESSION['user_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_pass = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';

    if (strlen($new_pass) < 8) {
        $err = 'Password must be at least 8 characters.';
    } elseif ($new_pass !== $confirm) {
        $err = 'Passwords do not match.';
    } else {
        $hash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("UPDATE snap_users SET password_hash = ? WHERE id = ?")
            ->execute([$hash, $uid]);
        snapsmack_clear_force_change($pdo, $uid);
        unset($_SESSION['force_password_change']);
        header("Location: smack-dashboard.php?msg=password_changed");
        exit;
    }
}

$page_title = "Set New Password";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>SET A NEW PASSWORD</h2>
    </div>

    <div class="box">
        <p style="color:var(--text-muted, #888); font-size:0.85rem; margin-bottom:20px;">
            You logged in with a one-time recovery code. You must set a new password before continuing.
        </p>

        <?php if ($err): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($err); ?></div>
        <?php endif; ?>

        <form method="POST">
            <label>NEW PASSWORD</label>
            <input type="password" name="password" minlength="8" required autofocus>

            <label>CONFIRM PASSWORD</label>
            <input type="password" name="confirm" minlength="8" required>

            <button type="submit" class="master-update-btn">SET PASSWORD &amp; CONTINUE</button>
        </form>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
