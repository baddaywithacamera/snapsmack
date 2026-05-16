<?php
/**
 * SMACK CENTRAL - Session guard
 *
 * Require this at the top of every SMACK CENTRAL page.
 * Loads config, starts the session, and redirects to login if not authenticated.
 *
 * Session lifetime is 8 hours rolling. ini_set alone is unreliable — PHP's
 * server-level GC (often 1200s default) can destroy session files before the
 * cookie expires. We store sc_expires_at in the session itself and extend it
 * on every authenticated request so the check is independent of server GC.
 */

define('SC_SESSION_LIFETIME', 28800); // 8 hours

require_once __DIR__ . '/sc-config.php';
require_once __DIR__ . '/sc-db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SC_SESSION_NAME);
    ini_set('session.gc_maxlifetime', SC_SESSION_LIFETIME);
    session_set_cookie_params(SC_SESSION_LIFETIME);
    session_start();
}

if (empty($_SESSION['sc_admin_id'])) {
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: sc-login.php' . ($redirect ? '?next=' . $redirect : ''));
    exit;
}

// Manual expiry: extend rolling window on every authenticated request.
// Works even if PHP GC deletes the session file prematurely — as long as
// the session is active, the expiry keeps rolling forward.
if (isset($_SESSION['sc_expires_at']) && time() > $_SESSION['sc_expires_at']) {
    session_destroy();
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
    header('Location: sc-login.php' . ($redirect ? '?next=' . $redirect : ''));
    exit;
}
$_SESSION['sc_expires_at'] = time() + SC_SESSION_LIFETIME;
// ===== SNAPSMACK EOF =====
