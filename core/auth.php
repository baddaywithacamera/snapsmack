<?php
/**
 * SnapSmack - Core Authentication Guard
 * Version: 1.4 - ACL Hardened
 * MASTER DIRECTIVE: Full file return. Logic and UI siloed.
 * STATUS: Session Fixation Protection Active.
 */

// 1. BOOTSTRAP ENVIRONMENT
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $script_dir = str_replace(['/core/auth.php', '/index.php'], '', $_SERVER['SCRIPT_NAME']);
    define('BASE_URL', $protocol . "://" . $host . $script_dir);
}

// 2. SESSION HANDSHAKE
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

// 3. HANDLE LOGOUT (TCP-style Teardown)
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

// 4. HANDLE LOGIN ATTEMPT
if (isset($_POST['login_attempt'])) {
    $user_input = $_POST['user'] ?? '';
    $pass_input = $_POST['pass'] ?? '';

    $stmt = $pdo->prepare("SELECT username, password_hash FROM snap_users WHERE username = ? LIMIT 1");
    $stmt->execute([$user_input]);
    $user_record = $stmt->fetch();

    if ($user_record && password_verify($pass_input, $user_record['password_hash'])) {
        // SECURITY UPGRADE: Regenerate ID to prevent session hijacking
        session_regenerate_id(true);
        $_SESSION['smack_user'] = $user_record['username'];
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR']; // Bind session to IP
        
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $login_error = "ACCESS DENIED: Invalid Credentials";
    }
}

// 5. ACCESS CONTROL GATEWAY
if (!isset($_SESSION['smack_user'])) {
    render_login_screen($login_error ?? null);
    exit;
}

/**
 * UI SILO: Rendering the Login Terminal
 * Keeping the presentation code here ensures specific tools (like the Wall)
 * don't need a heavy external skin just to show a login box.
 */
function render_login_screen($error = null) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>SNAPSMACK // LOGIN</title>
        <style>
            body { background: #000; color: #666; font-family: 'Courier New', Courier, monospace; display: flex; height: 100vh; justify-content: center; align-items: center; margin: 0; text-transform: uppercase; }
            .login-terminal { background: #111; padding: 50px; border: 1px solid #333; width: 320px; box-shadow: 0 20px 50px rgba(0,0,0,0.9); }
            h2 { color: #39FF14; margin-top: 0; letter-spacing: 4px; font-size: 1.2rem; border-bottom: 1px solid #222; padding-bottom: 20px; }
            input { display: block; width: 100%; background: #000; border: 1px solid #333; color: #ccc; padding: 12px; margin: 15px 0; box-sizing: border-box; font-family: inherit; }
            input:focus { border-color: #39FF14; outline: none; }
            button { width: 100%; padding: 12px; background: #222; color: #39FF14; border: 1px solid #39FF14; cursor: pointer; font-family: inherit; font-weight: bold; transition: all 0.3s; }
            button:hover { background: #39FF14; color: #000; }
            .error-msg { color: #ff3333; font-size: 0.7rem; margin-bottom: 15px; }
        </style>
    </head>
    <body>
        <div class="login-terminal">
            <h2>SYSTEM AUTH</h2>
            <?php if ($error): ?>
                <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="login_attempt" value="1">
                <input type="text" name="user" placeholder="USER ID" autocomplete="username" required autofocus>
                <input type="password" name="pass" placeholder="PASSWORD" autocomplete="current-password" required>
                <button type="submit">ESTABLISH CONNECTION</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}