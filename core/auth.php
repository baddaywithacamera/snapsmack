<?php
/**
 * SnapSmack - Core Authentication Guard
 * Version: 1.8 - User Preference Sync & Session Priming
 * -------------------------------------------------------------------------
 * This file ensures that a user's specific theme preference (Peace Treaty)
 * is pulled from the DB and stored in the session on every page load
 * where the user is authenticated.
 * -------------------------------------------------------------------------
 */

// 1. BOOTSTRAP ENVIRONMENT
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    define('BASE_URL', $protocol . "://" . $host . "/");
}

// 2. SESSION HANDSHAKE
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db.php';

// 3. HANDLE LOGOUT
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: " . BASE_URL . "login.php");
    exit;
}

// 4. ACCESS CONTROL GATEWAY
if (!isset($_SESSION['user_login'])) {
    header("Location: " . BASE_URL . "login.php");
    exit;
}

/**
 * 5. SYNC USER PREFERENCES
 * This populates $_SESSION['user_preferred_skin'] so admin-header.php 
 * can resolve the correct CSS path without toggling for everyone.
 */
if (isset($_SESSION['user_login'])) {
    try {
        // We fetch the ID and the specific skin preference for the logged-in user
        $stmt = $pdo->prepare("SELECT id, preferred_skin FROM snap_users WHERE username = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_login']]);
        $u_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($u_data) {
            // Store the ID for use in save operations (like skin switching)
            $_SESSION['user_id'] = $u_data['id'];
            
            // Only set the session skin if it hasn't been defined yet
            if (!isset($_SESSION['user_preferred_skin'])) {
                // Fallback to midnight-lime if the user field is empty in the database
                $_SESSION['user_preferred_skin'] = (!empty($u_data['preferred_skin'])) ? $u_data['preferred_skin'] : 'midnight-lime';
            }
        }
    } catch (PDOException $e) {
        // Silent fail prevents a DB hiccup from locking the entire admin interface
    }
}