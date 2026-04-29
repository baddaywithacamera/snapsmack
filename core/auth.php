<?php
/**
 * SNAPSMACK - Core Authentication Guard
 *
 * Manages user sessions, login gates, and theme preferences. On each page
 * load, this file ensures a user's preferred skin is pulled from the database
 * and stored in the session, so theme switching persists across the admin
 * interface.
 */

// --- ENVIRONMENT BOOTSTRAP ---
// Define BASE_URL if not already set by another file. This ensures all
// relative links work correctly regardless of subdirectory deployment.
if (!defined('BASE_URL')) {
    $is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
             || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $protocol = $is_https ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    define('BASE_URL', $protocol . "://" . $host . "/");
}

// --- SESSION INITIALIZATION ---
// Start the session if it hasn't been started yet. Keep the admin
// logged in for a full day (86 400 seconds) so sessions don't expire
// mid-workflow.
//
// Why not just ini_set? On shared cPanel hosting the host runs its own
// cron job that purges /tmp/sess_* files every ~24 minutes regardless of
// PHP's gc_maxlifetime. We work around this by storing sessions in a
// private directory inside the SnapSmack install that the host cron never
// touches. session_set_cookie_params() is also more reliable than
// ini_set('session.cookie_lifetime') on shared hosts.
if (session_status() === PHP_SESSION_NONE) {
    $ss_session_dir = dirname(__DIR__) . '/data/sessions';
    if (!is_dir($ss_session_dir)) {
        @mkdir($ss_session_dir, 0700, true);
        // Drop a deny-all .htaccess so session files aren't web-accessible
        @file_put_contents($ss_session_dir . '/.htaccess', "Order deny,allow\nDeny from all\n");
    }
    if (is_dir($ss_session_dir) && is_writable($ss_session_dir)) {
        session_save_path($ss_session_dir);
    }
    session_set_cookie_params([
        'lifetime' => 86400,
        'path'     => '/',
        'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                   || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', 86400);
    session_start();
}

require_once 'db.php';

// --- LOGOUT HANDLER ---
// If the user clicks logout, destroy the session and redirect to login.
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: " . BASE_URL . "snap-in.php");
    exit;
}

// --- LOGIN GATE ---
// Block unauthenticated users from seeing admin pages by redirecting to login.
// For XHR requests, return a 401 JSON response instead of the login page HTML
// so the client-side JS can detect the expired session and redirect cleanly.
if (!isset($_SESSION['user_login'])) {
    $is_xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($is_xhr) {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode(['status' => 'session_expired', 'msg' => 'Session expired. Please log in again.']);
        exit;
    }
    header("Location: " . BASE_URL . "snap-in.php");
    exit;
}

// --- FORCED PASSWORD CHANGE INTERCEPT ---
// Belt-and-suspenders: if the session flag was set (e.g. after a recovery code
// login) redirect to the change-password screen on every authenticated page load
// until the user completes the reset. Critical system pages are exempt so an
// in-progress update or other system operation is never interrupted mid-flight.
if (isset($_SESSION['force_password_change'])) {
    $current_script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $exempt = [
        'smack-change-password.php', // destination page — must not loop
        'smack-update.php',           // never interrupt a live update mid-extraction
    ];
    if (!in_array($current_script, $exempt, true)) {
        header("Location: " . BASE_URL . "smack-change-password.php");
        exit;
    }
}

// --- USER PREFERENCE SYNC ---
// Fetch the user's preferred skin from the database so theme switching
// persists. Store both user_id and preferred_skin in the session.
if (isset($_SESSION['user_login'])) {
    try {
        $stmt = $pdo->prepare("SELECT id, preferred_skin FROM snap_users WHERE username = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_login']]);
        $u_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($u_data) {
            // Store user ID for save operations like theme switching
            $_SESSION['user_id'] = $u_data['id'];

            // Only set the session skin if it hasn't been defined yet.
            // Default to midnight-lime if the database field is empty.
            if (!isset($_SESSION['user_preferred_skin'])) {
                $_SESSION['user_preferred_skin'] = (!empty($u_data['preferred_skin'])) ? $u_data['preferred_skin'] : 'midnight-lime';
            }
        }
    } catch (PDOException $e) {
        // Silent fail: a database hiccup should not lock the entire admin interface
    }

    // Refresh the session cookie expiry on every authenticated page load.
    // Without this the cookie (and therefore the session) ages from login
    // time. With this, it's always "24 hours from last activity".
    setcookie(
        session_name(),
        session_id(),
        [
            'expires'  => time() + 86400,
            'path'     => '/',
            'secure'   => snap_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
}
