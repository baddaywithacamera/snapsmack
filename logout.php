<?php
/**
 * SNAPSMACK - Logout and session destruction.
 * Terminating active administrative sessions and clearing client-side cookies.
 * Redirects the user to the login screen upon completion.
 * Git Version Official Alpha 0.5
 */

// Initialize the session to access and destroy it.
session_start();

// Wipe all session variables from the superglobal.
$_SESSION = array();

// --- COOKIE INVALIDATION ---
// If the session uses cookies, expire the session cookie manually to ensure a clean exit.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session on the server.
session_destroy();

// --- EXIT REDIRECT ---
header("Location: login.php");
exit;