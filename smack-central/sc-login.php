<?php
/**
 * SMACK CENTRAL - Login
 * Alpha v0.7.2
 */

require_once __DIR__ . '/sc-config.php';
require_once __DIR__ . '/sc-db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SC_SESSION_NAME);
    session_start();
}

// Already logged in — go to dashboard.
if (!empty($_SESSION['sc_admin_id'])) {
    header('Location: sc-dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = sc_db()->prepare("SELECT id, password_hash FROM sc_admin_users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['sc_admin_id']   = $user['id'];
            $_SESSION['sc_admin_name'] = $username;

            sc_db()->prepare("UPDATE sc_admin_users SET last_login_at = NOW() WHERE id = ?")
                   ->execute([$user['id']]);

            $next = $_GET['next'] ?? 'sc-dashboard.php';
            // Sanitise the redirect to prevent open redirect.
            if (!preg_match('/^sc-[a-z\-]+\.php/', ltrim(urldecode($next), '/'))) {
                $next = 'sc-dashboard.php';
            }
            header('Location: ' . $next);
            exit;
        }
    }
    $error = 'Invalid credentials.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SMACK CENTRAL — Login</title>
<link rel="stylesheet" href="assets/css/sc-geometry.css">
<link rel="stylesheet" href="assets/css/sc-admin.css">
</head>
<body class="sc-login-page">
<div class="sc-login-wrap">
  <div class="sc-login-box">
    <div class="sc-login-brand">SMACK CENTRAL</div>
    <div class="sc-login-sub">Hub Administration</div>
    <?php if ($error): ?>
    <div class="sc-alert sc-alert--error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="post" action="sc-login.php<?php echo isset($_GET['next']) ? '?next=' . urlencode($_GET['next']) : ''; ?>">
      <div class="sc-field">
        <label>USERNAME</label>
        <input type="text" name="username" autofocus autocomplete="username"
               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
      </div>
      <div class="sc-field">
        <label>PASSWORD</label>
        <input type="password" name="password" autocomplete="current-password">
      </div>
      <button type="submit" class="sc-btn sc-btn--full">LOG IN</button>
    </form>
  </div>
</div>
</body>
</html>
