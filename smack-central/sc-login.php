<?php
/**
 * SMACK CENTRAL - Login
 */

require_once __DIR__ . '/sc-config.php';
require_once __DIR__ . '/sc-db.php';
if (file_exists(__DIR__ . '/sc-version.php')) require_once __DIR__ . '/sc-version.php';

if (session_status() === PHP_SESSION_NONE) {
    $_sc_sess_dir = __DIR__ . '/.sc-sessions';
    if (!is_dir($_sc_sess_dir)) {
        @mkdir($_sc_sess_dir, 0700, true);
    }
    session_save_path($_sc_sess_dir);
    ini_set('session.gc_maxlifetime', 28800);
    session_name(SC_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 28800,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Already logged in — go to dashboard.
if (!empty($_SESSION['sc_admin_id'])) {
    header('Location: sc-dashboard.php');
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// SECAUDIT 047 — BRUTE-FORCE LOCKOUT FOR THE HUB LOGIN
// The hub (Smack Central) controls the whole fleet, so an unthrottled
// password-guessing endpoint here is a fleet-wide compromise risk — exactly the
// protection the CMS login already has, applied to the higher-value target.
// Self-contained on the hub DB, keyed by REMOTE_ADDR (the hub is reached
// directly, so X-Forwarded-For is not trusted — matches sc-network-api.php).
// 5 failures / 15-minute window → 15-minute lockout.
// ─────────────────────────────────────────────────────────────────────────────
function sc_login_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}
function sc_login_ensure_table(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS sc_login_attempts (
                ip VARCHAR(45) NOT NULL PRIMARY KEY,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                window_start DATETIME NOT NULL,
                locked_until DATETIME NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (\Throwable $e) { /* best-effort — never block login on a DDL hiccup */ }
}
function sc_login_is_locked(PDO $db, string $ip): bool {
    sc_login_ensure_table($db);
    try {
        $s = $db->prepare("SELECT locked_until FROM sc_login_attempts WHERE ip = ? LIMIT 1");
        $s->execute([$ip]);
        $lu = $s->fetchColumn();
        return $lu && strtotime((string)$lu) > time();
    } catch (\Throwable $e) { return false; }
}
function sc_login_record_failure(PDO $db, string $ip): void {
    sc_login_ensure_table($db);
    try {
        $db->prepare(
            "INSERT INTO sc_login_attempts (ip, attempts, window_start)
             VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE
               attempts     = IF(window_start < DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, attempts + 1),
               window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL 15 MINUTE), NOW(), window_start)"
        )->execute([$ip]);
        $db->prepare(
            "UPDATE sc_login_attempts
                SET locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
              WHERE ip = ? AND attempts >= 5"
        )->execute([$ip]);
    } catch (\Throwable $e) { /* best-effort */ }
}
function sc_login_clear(PDO $db, string $ip): void {
    try { $db->prepare("DELETE FROM sc_login_attempts WHERE ip = ?")->execute([$ip]); }
    catch (\Throwable $e) { /* best-effort */ }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_sc_ip = sc_login_ip();
    if (sc_login_is_locked(sc_db(), $_sc_ip)) {
        $error = 'Too many failed attempts. Please wait a few minutes and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $ok = false;
        if ($username && $password) {
            $stmt = sc_db()->prepare("SELECT id, password_hash FROM sc_admin_users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                $ok = true;
                sc_login_clear(sc_db(), $_sc_ip);
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
            } else {
                // Constant-ish time even when the username is unknown, so the hub
                // login doesn't leak which usernames exist.
                if (!$user) { password_verify($password, '$2y$10$usesomesillystringforsalt0000000000000000000000000000000e'); }
            }
        }
        if (!$ok) {
            sc_login_record_failure(sc_db(), $_sc_ip);
            $error = 'Invalid credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SMACK CENTRAL â Login</title>
<?php $sc_v = defined('SC_VERSION') ? SC_VERSION : '0'; ?>
<link rel="stylesheet" href="assets/css/sc-geometry.css?v=<?php echo $sc_v; ?>">
<link rel="stylesheet" href="assets/css/sc-colours.css?v=<?php echo $sc_v; ?>">
<link rel="stylesheet" href="assets/css/sc-admin.css?v=<?php echo $sc_v; ?>">
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
<?php // ===== SNAPSMACK EOF =====
