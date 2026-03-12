<?php
/**
 * SNAPSMACK - Logout and session destruction
 * Alpha v0.7.3
 *
 * Clears administrative session data and destroys session cookies.
 * Redirects user to login screen.
 */

// --- SESSION INITIALIZATION ---
// Start session to access and destroy it
session_start();

// --- SESSION CLEANUP ---
// Wipe all session variables from the superglobal
$_SESSION = array();

// --- COOKIE INVALIDATION ---
// If session uses cookies, expire the session cookie manually for clean exit
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// --- SESSION DESTRUCTION ---
// Destroy the session on the server
session_destroy();

// --- REDIRECT ---
// Send user back to login screen
header("Location: login.php");
exit;
