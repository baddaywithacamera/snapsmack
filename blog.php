<?php
/**
 * SNAPSMACK - Blog Feed (Latest Post View)
 * Alpha v0.7.1
 *
 * When homepage_mode is set to 'static_page', the image feed moves here.
 * This file is a thin redirect wrapper: if homepage_mode is 'latest_post'
 * (default), visitors hitting /blog.php are sent back to the homepage.
 * Otherwise it loads index.php with a flag that forces the image feed
 * regardless of the homepage_mode setting.
 */

require_once __DIR__ . '/core/db.php';

// Check homepage mode — if latest_post, blog IS the homepage, redirect there.
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

if (!defined('BASE_URL')) {
    $db_url = $settings['site_url'] ?? '/';
    define('BASE_URL', rtrim($db_url, '/') . '/');
}

$homepage_mode = $settings['homepage_mode'] ?? 'latest_post';

if ($homepage_mode === 'latest_post') {
    // Blog is already the homepage — redirect to avoid duplicate content
    header("Location: " . BASE_URL);
    exit;
}

// Force the image feed by overriding homepage_mode for this request
$_SERVER['SNAPSMACK_FORCE_BLOG'] = true;

// Include index.php which will see the force flag and skip the static page branch
include __DIR__ . '/index.php';
