<?php
/**
 * SMACK CENTRAL - Session guard
 *
 * Require this at the top of every SMACK CENTRAL page.
 * Loads config, starts the session, and redirects to login if not authenticated.
 *
 * Session lifetime is 8 hours rolling. We use a dedicated session save path
 * inside smack-central so the system-level PHP GC (which runs from any PHP
 * process and uses whatever gc_maxlifetime is in php.ini — often 24 minutes)
 * cannot delete our session files before the cookie expires.
 */

define('SC_SESSION_LIFETIME', 28800); // 8 hours

require_once __DIR__ . '/sc-config.php';
require_once __DIR__ . '/sc-db.php';

if (session_status() === PHP_SESSION_NONE) {
    // Dedicated session directory — isolated from the system session pool so
    // server-level GC running with a shorter gc_maxlifetime can't nuke our sessions.
    $_sc_sess_dir = __DIR__ . '/.sc-sessions';
    if (!is_dir($_sc_sess_dir)) {
        @mkdir($_sc_sess_dir, 0700, true);
    }
    session_save_path($_sc_sess_dir);
    ini_set('session.gc_maxlifetime', SC_SESSION_LIFETIME);

    session_name(SC_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SC_SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (empty($_SESSION['sc_admin_id'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: sc-login.php' . ($redirect ? '?next=' . $redirect : ''));
    exit;
}

// Rolling expiry check — belt-and-suspenders on top of the dedicated save path.
if (isset($_SESSION['sc_expires_at']) && time() > $_SESSION['sc_expires_at']) {
    session_destroy();
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: sc-login.php' . ($redirect ? '?next=' . $redirect : ''));
    exit;
}
$_SESSION['sc_expires_at'] = time() + SC_SESSION_LIFETIME;
// ===== SNAPSMACK EOF =====
