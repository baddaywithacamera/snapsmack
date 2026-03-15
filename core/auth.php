<?php
/**
 * SNAPSMACK - Core Authentication Guard
 * Alpha v0.7.3a
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
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    define('BASE_URL', $protocol . "://" . $host . "/");
}

// --- SESSION INITIALIZATION ---
// Start the session if it hasn't been started yet. This is safe to call
// multiple times.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

// --- LOGOUT HANDLER ---
// If the user clicks logout, destroy the session and redirect to login.
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: " . BASE_URL . "login.php");
    exit;
}

// --- LOGIN GATE ---
// Block unauthenticated users from seeing admin pages by redirecting to login.
if (!isset($_SESSION['user_login'])) {
    header("Location: " . BASE_URL . "login.php");
    exit;
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
}
