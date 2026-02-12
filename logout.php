<?php
/**
 * SNAPSMACK Logout Engine
 * Version: 1.0 - Clean Exit
 * MASTER DIRECTIVE: Full file return.
 */

session_start();

// Unset all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie
// Note: This forces the browser to expire the session ID locally
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Final destruction of the session
session_destroy();

// Redirect back to the login gateway
header("Location: login.php");
exit;