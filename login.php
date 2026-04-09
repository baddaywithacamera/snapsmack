<?php
/**
 * SNAPSMACK - Admin login portal
 * Alpha v0.7.9
 *
 * Authenticates users and initializes administrative sessions.
 * Supports password login, one-time recovery codes, and links to
 * email-based password reset.
 */

require_once 'core/db.php';
require_once 'core/auth-recovery.php';

// --- SESSION INITIALIZATION ---
// Must use the same session save path as auth.php so the session file
// created at login is found on subsequent authenticated requests.
if (session_status() === PHP_SESSION_NONE) {
    $ss_session_dir = __DIR__ . '/data/sessions';
    if (!is_dir($ss_session_dir)) {
        @mkdir($ss_session_dir, 0700, true);
        @file_put_contents($ss_session_dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
    }
    if (is_dir($ss_session_dir) && is_writable($ss_session_dir)) {
        session_save_path($ss_session_dir);
    }
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', 86400);
    session_start();
}

// --- REDIRECT FOR EXISTING SESSIONS ---
// If user is already authenticated, send them to the dashboard
if (isset($_SESSION['user_login'])) {
    header("Location: smack-admin.php");
    exit;
}

// --- ADMIN THEME RESOLUTION ---
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

$error       = '';
$active_tab  = 'password'; // 'password' or 'recovery'

// --- AUTHENTICATION HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_type = $_POST['login_type'] ?? 'password';

    // ── Password login ───────────────────────────────────────────────────────
    if ($login_type === 'password') {
        $active_tab = 'password';
        $user_input = trim($_POST['username'] ?? '');
        $pass_input = $_POST['password'] ?? '';

        $stmt = $pdo->prepare("SELECT id, username, password_hash, user_role, preferred_skin, force_password_change FROM snap_users WHERE username = ?");
        $stmt->execute([$user_input]);
        $user = $stmt->fetch();

        if ($user && password_verify($pass_input, $user['password_hash'])) {
            $_SESSION['user_login']          = $user['username'];
            $_SESSION['user_role']           = $user['user_role'] ?: 'editor';
            $_SESSION['user_preferred_skin'] = $user['preferred_skin'] ?: null;
            $_SESSION['user_id']             = $user['id'];

            // Respect force_password_change flag set by previous recovery login
            if (!empty($user['force_password_change'])) {
                $_SESSION['force_password_change'] = true;
                header("Location: smack-change-password.php");
                exit;
            }

            header("Location: smack-admin.php");
            exit;
        } else {
            $error = "ACCESS DENIED: Invalid credentials.";
        }

    // ── Recovery code login ──────────────────────────────────────────────────
    } elseif ($login_type === 'recovery') {
        $active_tab  = 'recovery';
        $rec_user    = trim($_POST['rec_username'] ?? '');
        $rec_code    = strtoupper(trim($_POST['rec_code'] ?? ''));

        $user = snapsmack_validate_recovery_code($pdo, $rec_user, $rec_code);

        if ($user) {
            snapsmack_consume_recovery_code($pdo, $user['id']);

            $_SESSION['user_login']            = $user['username'];
            $_SESSION['user_role']             = $user['user_role'] ?: 'editor';
            $_SESSION['user_preferred_skin']   = $user['preferred_skin'] ?: null;
            $_SESSION['user_id']               = $user['id'];
            $_SESSION['force_password_change'] = true;

            header("Location: smack-change-password.php");
            exit;
        } else {
            $error = "ACCESS DENIED: Invalid username or recovery code.";
        }
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
    <style>
        .login-tabs { display: flex; gap: 0; margin-bottom: 24px; border-bottom: 1px solid var(--border, #2e2e2e); }
        .login-tab  { flex: 1; padding: 9px 0; font-size: 0.68rem; letter-spacing: 1.5px;
                      text-transform: uppercase; background: none; border: none; cursor: pointer;
                      color: var(--text-muted, #666); border-bottom: 2px solid transparent;
                      margin-bottom: -1px; transition: color 0.15s; }
        .login-tab.active { color: var(--text-primary, #eee); border-bottom-color: var(--accent, #aaa); }
        .login-panel { display: none; }
        .login-panel.active { display: block; }
        .forgot-link { display: block; margin-top: 14px; font-size: 0.72rem; color: var(--text-muted, #555);
                       text-decoration: none; text-align: center; }
        .forgot-link:hover { color: var(--text-secondary, #aaa); }
        .rec-hint { font-size: 0.72rem; color: var(--text-muted, #666); margin-bottom: 16px; }
    </style>
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-box">
            <h1>SNAPSMACK</h1>

            <?php if ($error): ?>
                <div class="alert alert-error">&gt; <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="login-tabs">
                <button type="button" class="login-tab <?php echo $active_tab === 'password' ? 'active' : ''; ?>"
                        onclick="switchTab('password')">Password</button>
                <button type="button" class="login-tab <?php echo $active_tab === 'recovery' ? 'active' : ''; ?>"
                        onclick="switchTab('recovery')">Recovery Code</button>
            </div>

            <!-- PASSWORD LOGIN -->
            <div id="panel-password" class="login-panel <?php echo $active_tab === 'password' ? 'active' : ''; ?>">
                <form method="POST">
                    <input type="hidden" name="login_type" value="password">
                    <div class="control-group">
                        <label>IDENTIFIER</label>
                        <input type="text" name="username" required
                               <?php echo $active_tab === 'password' ? 'autofocus' : ''; ?>
                               autocomplete="off"
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                    <div class="control-group">
                        <label>PASSCODE</label>
                        <input type="password" name="password" required>
                    </div>
                    <button type="submit" class="master-update-btn">AUTHORIZE ACCESS</button>
                </form>
                <a href="password-reset.php" class="forgot-link">Forgot password?</a>
            </div>

            <!-- RECOVERY CODE LOGIN -->
            <div id="panel-recovery" class="login-panel <?php echo $active_tab === 'recovery' ? 'active' : ''; ?>">
                <form method="POST">
                    <input type="hidden" name="login_type" value="recovery">
                    <p class="rec-hint">Enter your username and the one-time code provided by your administrator.</p>
                    <div class="control-group">
                        <label>USERNAME</label>
                        <input type="text" name="rec_username" required
                               <?php echo $active_tab === 'recovery' ? 'autofocus' : ''; ?>
                               autocomplete="off"
                               value="<?php echo htmlspecialchars($_POST['rec_username'] ?? ''); ?>">
                    </div>
                    <div class="control-group">
                        <label>RECOVERY CODE</label>
                        <input type="text" name="rec_code" required autocomplete="off"
                               placeholder="SNAP-XXXX-XXXX-XXXX"
                               value="<?php echo htmlspecialchars($_POST['rec_code'] ?? ''); ?>">
                    </div>
                    <button type="submit" class="master-update-btn">USE RECOVERY CODE</button>
                </form>
            </div>

            <a href="index.php" class="back-link">&larr; RETURN TO SITE</a>
        </div>
    </div>
    <script>
    function switchTab(tab) {
        document.querySelectorAll('.login-tab').forEach(function(t) { t.classList.remove('active'); });
        document.querySelectorAll('.login-panel').forEach(function(p) { p.classList.remove('active'); });
        document.querySelector('.login-tab:nth-child(' + (tab === 'password' ? 1 : 2) + ')').classList.add('active');
        document.getElementById('panel-' + tab).classList.add('active');
    }
    </script>
</body>
</html>
