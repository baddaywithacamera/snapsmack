<?php
/**
 * SnapSmack - Core Authentication Guard
 * Version: 1.6 - Fixed BASE_URL Detection
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
