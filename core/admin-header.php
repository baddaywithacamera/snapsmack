<?php
/**
 * SnapSmack Core Admin Header
 * Version: 7.0 - Geometry Master Integration
 * -------------------------------------------------------------------------
 * - REMOVED: Legacy cruft toggle and split structural pointers.
 * - FIXED: Single-point geometry master integration.
 * - SYNCED: Theme discovery logic preserved.
 * -------------------------------------------------------------------------
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($settings)) {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

$active_theme = $_SESSION['user_theme'] ?? $settings['active_theme'] ?? 'midnight-lime';
$theme_base = "assets/adminthemes/{$active_theme}/";
$manifest_path = $theme_base . "{$active_theme}-manifest.php";

$colour_css_file = "admin-theme-colours-{$active_theme}.css"; 

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
    <link rel="stylesheet" href="assets/css/hotkey-engine.css">
</head>
<body class="admin-body">