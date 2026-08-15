<?php
/**
 * SNAPSMACK - Logout and session destruction
 *
 * Clears administrative session data and destroys session cookies.
 * Redirects user to login screen.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// SECAUDIT 047: ignore forged cross-site background logout requests (e.g. an
// <img src="logout.php"> on an attacker's page). Genuine logout is a same-origin
// click or a real navigation; only a cross-site no-cors fetch is dropped.
// Browsers that don't send Sec-Fetch-* are unaffected.
$_sfs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
$_sfm = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '';
if ($_sfs === 'cross-site' && $_sfm !== '' && $_sfm !== 'navigate') {
    header("Location: ./");
    exit;
}

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
// Send user back to login screen.
// auth-smack.php will bounce unauthenticated requests to the configured snap-in slug.
header("Location: smack-admin.php");
exit;
// ===== SNAPSMACK EOF =====
