<?php
/**
 * SNAPSMACK - Blog Feed (Latest Post View)
 * Alpha v0.7.7
 *
 * When homepage_mode is 'static_page' or 'skin_landing', the image feed
 * moves to a configurable slug (default: /blog). This file handles the
 * /blog.php route; the clean URL slug is caught by index.php directly.
 *
 * If homepage_mode is 'latest_post', blog IS the homepage — redirect there.
 */

require_once __DIR__ . '/core/db.php';

$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

if (!defined('BASE_URL')) {
    $db_url = $settings['site_url'] ?? '/';
    define('BASE_URL', rtrim($db_url, '/') . '/');
}

$homepage_mode = $settings['homepage_mode'] ?? 'latest_post';

if ($homepage_mode === 'latest_post') {
    header("Location: " . BASE_URL);
    exit;
}

// Force the image feed by overriding homepage_mode for this request
$_SERVER['SNAPSMACK_FORCE_BLOG'] = true;

include __DIR__ . '/index.php';
