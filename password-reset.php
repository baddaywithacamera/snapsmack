<?php
/**
 * SNAPSMACK - Password Reset
 * Alpha v0.7.9a
 *
 * Two-step email-based password reset.
 * Step 1: Enter email address → receive reset link.
 * Step 2: Click link → set new password.
 * No admin login required — this is the "locked out" path.
 */

require_once 'core/db.php';
require_once 'core/auth-recovery.php';

$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$site_name = $settings['site_name'] ?? 'SnapSmack';
$site_url  = rtrim($settings['site_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/') . '/';

$token   = trim($_GET['token'] ?? '');
$step    = $token ? 'reset' : 'request';
$msg     = '';
$err     = '';
$success = false;

// ─── STEP 2: Validate token + set new password ───────────────────────────────
if ($step === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token     = trim($_POST['token'] ?? '');
    $new_pass  = $_POST['password']  ?? '';
    $confirm   = $_POST['confirm']   ?? '';

    if (empty($new_pass) || strlen($new_pass) < 8) {
        $err = 'Password must be at least 8 characters.';
    } elseif ($new_pass !== $confirm) {
        $err = 'Passwords do not match.';
    } else {
        $user = snapsmack_validate_reset_token($pdo, $token);
        if (!$user) {
            $err = 'This reset link has expired or already been used. Please request a new one.';
            $step = 'request';
        } else {
            $hash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("UPDATE snap_users SET password_hash = ?, force_password_change = 0 WHERE id = ?")
                ->execute([$hash, $user['id']]);
            snapsmack_consume_reset_token($pdo, $token);
            $success = true;
            $msg     = 'Password updated. You can now log in.';
        }
    }
}

// ─── STEP 1: Request reset link ──────────────────────────────────────────────
if ($step === 'request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter a valid email address.';
    } else {
        $reset_token = snapsmack_generate_reset_token($pdo, $email);

        // Always show the same message whether the email exists or not
        // (prevents user enumeration)
        $msg = 'If that email address is registered, a reset link has been sent. Check your inbox and spam folder.';

        if ($reset_token) {
            // Look up username for personalised email
            $u = $pdo->prepare("SELECT username FROM snap_users WHERE LOWER(email) = ?");
            $u->execute([$email]);
            $username = $u->fetchColumn() ?: 'User';
            snapsmack_send_reset_email($email, $username, $reset_token, $site_name, $site_url);
        }
    }
}

// Re-validate token for GET display of step 2
if ($step === 'reset' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $user = snapsmack_validate_reset_token($pdo, $token);
    if (!$user) {
        $err  = 'This reset link has expired or already been used.';
        $step = 'request';
        $token = '';
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($site_name); ?> — Password Reset</title>
<style>
*, *::before, *::after { box-sizing: border-box; }
body   { margin: 0; background: #111; color: #ccc; font-family: 'Segoe UI', system-ui, sans-serif;
         display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.card  { width: 100%; max-width: 400px; padding: 40px 36px; background: #1a1a1a;
         border: 1px solid #2e2e2e; }
h1    { margin: 0 0 6px; font-size: 0.75rem; letter-spacing: 2px; text-transform: uppercase;
        color: #fff; }
.sub  { font-size: 0.8rem; color: #666; margin: 0 0 28px; }
label { display: block; font-size: 0.7rem; letter-spacing: 1px; text-transform: uppercase;
        color: #888; margin-bottom: 6px; }
input { width: 100%; padding: 10px 14px; background: #111; border: 1px solid #333;
        color: #eee; font-size: 0.9rem; margin-bottom: 16px; }
input:focus { outline: none; border-color: #666; }
button { width: 100%; padding: 12px; background: #333; color: #fff; border: none;
         font-size: 0.72rem; letter-spacing: 1.5px; text-transform: uppercase; cursor: pointer; }
button:hover { background: #444; }
.msg  { padding: 12px 14px; font-size: 0.82rem; margin-bottom: 20px; }
.ok   { background: #1a3a1a; border: 1px solid #2d6a2d; color: #8fca8f; }
.err  { background: #3a1a1a; border: 1px solid #6a2d2d; color: #ca8f8f; }
.back { display: block; margin-top: 20px; font-size: 0.75rem; color: #555;
        text-decoration: none; text-align: center; }
.back:hover { color: #aaa; }
</style>
</head>
<body>
<div class="card">
    <h1><?php echo htmlspecialchars($site_name); ?></h1>
    <p class="sub"><?php echo $step === 'reset' ? 'Set a new password' : 'Reset your password'; ?></p>

    <?php if ($msg): ?>
        <div class="msg ok"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
        <div class="msg err"><?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <a href="login.php" class="back">← Back to login</a>

    <?php elseif ($step === 'reset'): ?>
        <form method="POST">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <label>New Password</label>
            <input type="password" name="password" minlength="8" required autofocus>
            <label>Confirm Password</label>
            <input type="password" name="confirm" minlength="8" required>
            <button type="submit">Set New Password</button>
        </form>
        <a href="login.php" class="back">← Back to login</a>

    <?php else: ?>
        <form method="POST">
            <label>Email Address</label>
            <input type="email" name="email" required autofocus
                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            <button type="submit">Send Reset Link</button>
        </form>
        <a href="login.php" class="back">← Back to login</a>
    <?php endif; ?>
</div>
</body>
</html>
