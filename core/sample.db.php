<?php
/**
 * SNAPSMACK - Core Database Connection
 * Alpha v0.6
 *
 * Establishes PDO connection to the MySQL database with proper error handling
 * and security settings. Loads constants first to ensure availability across
 * the application.
 *
 * SETUP: Copy this file to db.php and fill in your real credentials.
 */

require_once __DIR__ . '/constants.php';

// --- DATABASE CREDENTIALS ---
// Replace these with your actual database details.
$host    = 'localhost';
$db      = 'your_database_name';
$user    = 'your_database_user';
$pass    = 'your_database_password';
$charset = 'utf8mb4';

// --- CONNECTION SETUP ---
// Configure PDO to throw exceptions on errors, use associative arrays,
// and use prepared statements for security
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// --- ESTABLISH CONNECTION ---
// Fatal error handling: if the database is unreachable, display a safe
// error message without exposing credentials to the user
try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     die("<div style='background:#200;color:#f99;padding:20px;border:1px solid red;font-family:monospace;'><h3>DATABASE_LINK_FAILURE</h3>The connection to the data vault was interrupted.</div>");
}
