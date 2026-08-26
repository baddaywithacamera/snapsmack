<?php
/**
 * SNAPSMACK — the discovery feed as RSS.
 *
 * The feed page ([photoblogs_feed] grid) and this endpoint render the SAME
 * snap_feed_items cache — one from each side of the shared engine. Whichever
 * source mode filled the cache ('blogs' or 'hashtag'), the RSS is identical in
 * shape: each item links to the post, thumbnail embedded.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once __DIR__ . '/core/constants.php';
require_once __DIR__ . '/core/db.php';   // $pdo
require_once __DIR__ . '/core/photoblogs-feed.php';

$settings = [];
foreach ($pdo->query("SELECT setting_key, setting_val FROM snap_settings") as $r) {
    $settings[$r['setting_key']] = $r['setting_val'];
}
$site = rtrim((string)($settings['site_url'] ?? ''), '/');

header('Content-Type: application/rss+xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

echo pbfeed_rss_xml($pdo, [
    'title'       => ($settings['site_name'] ?? 'Feed') . ' — the feed',
    'link'        => $site . '/feed',
    'description' => (string)($settings['site_description'] ?? 'The discovery feed.'),
    'self'        => $site . '/feed-rss.php',
]);
// ===== SNAPSMACK EOF =====
