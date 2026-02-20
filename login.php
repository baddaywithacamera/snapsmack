<?php
/**
 * SnapSmack Login
 * Version: 2.4 - Styles Belong In CSS Files
 * Last changed: 2026-02-19
 * -------------------------------------------------------------------------
 * - FIXED: All login styles moved to geometry master (section 17) and
 *   admin-theme-colours-midnight-lime.css (section 13) where they belong.
 * - FIXED: Autofill dark override via inset box-shadow in colours file.
 * - FIXED: .login-body padding-bottom:0 now in geometry, not inline.
 * - REMOVED: Entire scoped <style> block. PHP files serve PHP.
 * -------------------------------------------------------------------------
 */
require_once 'core/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. If already logged in, skip to dashboard
if (isset($_SESSION['user_login'])) {
    header("Location: smack-admin.php");
    exit;
}

// 2. Resolve the active admin colour theme (mirrors admin-header.php logic)
if (!isset($settings)) {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

$active_theme    = $settings['active_theme'] ?? 'midnight-lime';
$theme_base      = "assets/adminthemes/{$active_theme}/";
$manifest_path   = $theme_base . "{$active_theme}-manifest.php";
$colour_css_file = "admin-theme-colours-{$active_theme}.css";

if (file_exists($manifest_path)) {
    $m_data = include $manifest_path;
    if (isset($m_data['css_file'])) {
        $colour_css_file = $m_data['css_file'];
    }
}

$active_skin_path = $theme_base . $colour_css_file;

// 3. Handle Login Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_input = trim($_POST['username']);
    $pass_input = $_POST['password'];

    $stmt = $pdo->prepare("SELECT username, password_hash, user_role FROM snap_users WHERE username = ?");
    $stmt->execute([$user_input]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass_input, $user['password_hash'])) {
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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">

    <link rel="stylesheet" href="assets/css/admin-theme-geometry-master.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($active_skin_path); ?>">
</head>
<body class="login-body">

    <div class="login-container">
        <div class="login-box">
            <h1>SNAPSMACK</h1>

            <?php if (isset($error)): ?>
                <div class="alert alert-error">&gt; <?php echo htmlspecialchars($error); ?></div>
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

                <button type="submit" class="master-update-btn">AUTHORIZE ACCESS</button>
            </form>

            <a href="index.php" class="back-link">&larr; RETURN TO SITE</a>
        </div>
    </div>

</body>
</html>
