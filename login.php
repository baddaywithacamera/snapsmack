<?php
/**
 * SNAPSMACK - Admin login portal.
 * Authenticates users and initializes administrative sessions.
 * Loads per-user preferences, including skin defaults, into the session.
 * Git Version Official Alpha 0.5
 */

require_once 'core/db.php';

// Ensure session is active before processing login or redirects.
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// --- REDIRECT LOGIC ---
// If a valid session already exists, bypass the login screen and go to the dashboard.
if (isset($_SESSION['user_login'])) {
    header("Location: smack-admin.php");
    exit;
}

// --- ADMIN THEME INITIALIZATION ---
// Determines the visual theme of the login page based on global site settings.
if (!isset($settings)) {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

$active_theme    = $settings['active_theme'] ?? 'midnight-lime';
$theme_base      = "assets/adminthemes/{$active_theme}/";
$manifest_path   = $theme_base . "{$active_theme}-manifest.php";
$colour_css_file = "admin-theme-colours-{$active_theme}.css";

// Check for a theme manifest to resolve the specific CSS filename.
if (file_exists($manifest_path)) {
    $m_data = include $manifest_path;
    if (isset($m_data['css_file'])) {
        $colour_css_file = $m_data['css_file'];
    }
}

$active_skin_path = $theme_base . $colour_css_file;

// --- AUTHENTICATION HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_input = trim($_POST['username']);
    $pass_input = $_POST['password'];

    // Retrieve user record including the hash and preferred site-view skin.
    $stmt = $pdo->prepare("SELECT username, password_hash, user_role, preferred_skin FROM snap_users WHERE username = ?");
    $stmt->execute([$user_input]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass_input, $user['password_hash'])) {
        // Initialize administrative session variables.
        $_SESSION['user_login'] = $user['username'];
        $_SESSION['user_role']  = $user['user_role'] ?: 'editor';
        $_SESSION['user_preferred_skin'] = $user['preferred_skin'] ?: null;

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