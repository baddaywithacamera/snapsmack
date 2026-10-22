<?php
/**
 * Clean public route for the visual Feed page.
 *
 * Render through the normal controller without bouncing through page.php:
 * page.php canonicalizes the CMS page back to /feed, so redirecting there
 * creates a /feed <-> /page.php?slug=feed loop. Machine-readable RSS remains
 * available at /rss and /rss.php.
 */
$_GET['name'] = 'feed';
require __DIR__ . '/index.php';
// ===== SNAPSMACK EOF =====
