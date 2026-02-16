<?php
/**
 * SnapSmack Core Admin Header
 * Version: 1.7 - Clean Trinity Loader
 */

// 1. BOOTSTRAP SETTINGS
if (!isset($settings)) {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// 2. THEME DISCOVERY (Data Only - No UI logic)
$active_theme = $settings['active_theme'] ?? 'midnight-lime';
$theme_base = "assets/adminthemes/{$active_theme}/";
$manifest_path = $theme_base . "{$active_theme}-manifest.php";

$colour_css_file = "admin-theme-colours-{$active_theme}.css"; // Default naming convention

if (file_exists($manifest_path)) {
    $m_data = include $manifest_path;
    if (isset($m_data['css_file'])) {
        $colour_css_file = $m_data['css_file'];
    }
}

// Construct the full path to the active skin's color file
// Per your file structure, colors live in the /css/ subfolder of the theme
$active_skin_path = $theme_base . "css/" . $colour_css_file;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Admin'; ?> | SnapSmack</title>

    <link rel="stylesheet" href="assets/css/admin-theme-structures-core.css">
    
    <link rel="stylesheet" href="assets/css/admin-theme-controls-core.css">
    
    <link rel="stylesheet" href="<?php echo $active_skin_path; ?>">

    <link rel="stylesheet" href="assets/css/hotkey-engine.css">
</head>
<body class="admin-body">