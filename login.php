<?php
/**
 * SnapSmack Login
 * Version: 2.1 - Session & Role Sync
 */
require_once 'core/auth.php';

// 1. If already logged in, skip to dashboard
if (isset($_SESSION['user_login'])) {
    header("Location: smack-admin.php");
    exit;
}

// 2. Handle Login Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_input = trim($_POST['username']);
    $pass_input = $_POST['password'];

    // Fetch user from DB
    $stmt = $pdo->prepare("SELECT username, password_hash, user_role FROM snap_users WHERE username = ?");
    $stmt->execute([$user_input]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass_input, $user['password_hash'])) {
        // SUCCESS: Establish the session
        $_SESSION['user_login'] = $user['username'];
        $_SESSION['user_role']  = $user['user_role'] ?: 'editor';
        
        header("Location: smack-admin.php");
        exit;
    } else {
        $error = "ACCESS DENIED: Invalid credentials.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | SnapSmack Admin</title>
    <link rel="stylesheet" href="assets/css/admin-theme.css">
</head>
<body class="login-body">

    <div class="login-box">
        <h1>SNAPSMACK</h1>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-error" style="margin-bottom: 20px;">> <?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="control-group">
                <label>IDENTIFIER</label>
                <input type="text" name="username" required autofocus autocomplete="off">
            </div>

            <div class="control-group">
                <label>PASSCODE</label>
                <input type="password" name="password" required>
            </div>

            <button type="submit" class="master-update-btn" style="margin-top: 20px;">AUTHORIZE ACCESS</button>
        </form>

        <div style="margin-top: 30px;">
            <a href="index.php" class="dim" style="text-decoration: none; font-size: 0.7rem;">&larr; RETURN TO SITE</a>
        </div>
    </div>

</body>
</html>