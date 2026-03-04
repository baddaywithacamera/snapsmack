<?php
/**
 * SNAPSMACK - Admin Dashboard Header
 * Alpha v0.7
 *
 * Outputs the HTML <head> and opening <body> tags for all admin pages.
 * Resolves the active theme from the user's session preference, loads the
 * appropriate theme CSS, and detects cron/PHP-CLI availability for scheduled
 * tasks.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- LOAD SETTINGS ---
// Fetch site settings from the database if not already in scope
if (!isset($settings)) {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// --- CRON CAPABILITY DETECTION ---
// Check if the server supports cron and PHP CLI for scheduled task runner
$cron_supported  = false;
$php_cli_path    = '';
if (function_exists('exec')) {
    exec('crontab -l 2>&1', $ct_out, $ct_code);
    $cron_supported = ($ct_code === 0);
    $php_cli_path   = trim(exec('which php 2>&1'));
    if (strpos($php_cli_path, '/') !== 0) $php_cli_path = '';
}

// --- THEME RESOLUTION ---
// Use the user's session preference, fall back to the global setting,
// then default to midnight-lime
$active_theme = $_SESSION['user_preferred_skin'] ?? $settings['active_theme'] ?? 'midnight-lime';
$theme_base = "assets/adminthemes/{$active_theme}/";
$manifest_path = $theme_base . "{$active_theme}-manifest.php";

$colour_css_file = "admin-theme-colours-{$active_theme}.css";

// --- MANIFEST-DRIVEN CSS FILE ---
// Allow theme manifests to override the default CSS filename
if (file_exists($manifest_path)) {
    $m_data = include $manifest_path;
    if (isset($m_data['css_file'])) {
        $colour_css_file = $m_data['css_file'];
    }
}

$active_skin_path = $theme_base . $colour_css_file;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Admin'; ?> | SnapSmack</title>

    <link rel="stylesheet" href="assets/css/admin-theme-geometry-master.css">
    <link rel="stylesheet" href="<?php echo $active_skin_path; ?>">
</head>
<body class="admin-body">
<button class="sidebar-toggle" onclick="document.querySelector('.sidebar').classList.toggle('open'); document.querySelector('.sidebar-overlay').classList.toggle('open');">&#9776;</button>
<div class="sidebar-overlay" onclick="document.querySelector('.sidebar').classList.remove('open'); this.classList.remove('open');"></div>
