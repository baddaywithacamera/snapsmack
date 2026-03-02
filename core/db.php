<?php
/**
 * SnapSmack — Core Database Backplane
 * Version: 1.3.0 — Timezone Bootstrap
 * 
 * WHAT CHANGED (1.3.0):
 *   Added timezone resolution immediately after PDO connection.
 *   Reads timezone from snap_settings, falls back to America/Edmonton.
 *   Every file that includes db.php now inherits the correct timezone
 *   before any call to date(), ensuring timestamps on post forms,
 *   archive queries, and fallback values all match local time.
 *
 * MASTER DIRECTIVE: Pure connection and environment logic only.
 *   No UI signatures. No HTML output except fatal error.
 */

// 1. HARDWARE CREDENTIALS
$host    = 'localhost';
$db      = 'squir871_iswa';
$user    = 'squir871_iswaadmin';
$pass    = 'XXXXXXXX'; 
$charset = 'utf8mb4';

// 2. CONNECTION STRING & SECURITY FLAGS
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("<div style='background:#200;color:#f99;padding:20px;border:1px solid red;font-family:monospace;'><h3>DATABASE_LINK_FAILURE</h3>The connection to the data vault was interrupted.</div>");
}

// 3. TIMEZONE BOOTSTRAP
//    Must execute before ANY call to date() anywhere in the application.
//    Prevents UTC timestamps from being stored or displayed instead of local time.
//    This single point of control replaces per-file workarounds in archive.php,
//    index.php, smack-post.php, smack-edit.php, etc.
try {
    $tz_stmt = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'timezone' LIMIT 1");
    $tz_val  = $tz_stmt->fetchColumn();
    date_default_timezone_set($tz_val ?: 'America/Edmonton');
} catch (\PDOException $e) {
    // If settings table is unreachable, fall back to the site's known timezone.
    // This keeps the app functional during schema migrations or fresh installs.
    date_default_timezone_set('America/Edmonton');
}
