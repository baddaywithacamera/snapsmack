<?php
/**
 * SnapSmack - Core Database Backplane
 * Version: 1.2.2 - Pure Pipe (The Pencil)
 * MASTER DIRECTIVE: Full file return. 
 * Logic: Removed UI signatures. Pure Connection Logic only.
 */

// 1. HARDWARE CREDENTIALS
$host    = 'localhost';
$db      = 'squir871_iswa';
$user    = 'squir871_iswaadmin';
$pass    = 'Pickle40!!#'; 
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
     // Admin Logic: Mask credentials from the public
     die("<div style='background:#200;color:#f99;padding:20px;border:1px solid red;font-family:monospace;'><h3>DATABASE_LINK_FAILURE</h3>The connection to the data vault was interrupted.</div>");
}

// 3. CLEAN EXIT
// No UI signatures or HTML strings allowed in the backplane.