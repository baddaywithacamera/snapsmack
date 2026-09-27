<?php
/**
 * A desktop-tool write clears the page cache, as admin publishing does.
 *
 * SYBU posted 13 grams through api.php?route=threeacross/...; the front page
 * stayed a copy saved mid-batch (7 of 13 missing) because no API route called
 * page_cache_purge_all(). theschoolofhardnocks.ca, 2026-09-27.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$src = file_get_contents(dirname(__DIR__) . '/api.php');
$failures = [];
if (strpos($src, 'register_shutdown_function') === false || strpos($src, 'page_cache_purge_all()') === false) {
    $failures[] = 'api.php no longer clears the page cache after a write';
}
foreach (['threeacross', 'ohsnap', 'smackpress', 'gyss', 'flkrfckr', 'smackthemup', 'tyswy', 'multisite/posts'] as $route) {
    if (strpos($src, "'$route'") === false) $failures[] = "route $route does not clear the page cache";
}
if (preg_match("/'multisite'\s*[,\]]/", $src)) {
    $failures[] = 'all multisite traffic (heartbeats) would clear the cache — only multisite/posts should';
}
$head = substr($src, 0, strpos($src, '// --- MULTISITE ROUTES ---'));
if (strpos($head, 'page_cache_purge_all') === false) {
    $failures[] = 'the cache hook must be registered before any route runs and exits';
}
if ($failures) { fwrite(STDERR, "FAIL:\n - " . implode("\n - ", $failures) . "\n"); exit(1); }
echo "PASS: desktop-tool API writes clear the page cache\n";

// ===== SNAPSMACK EOF =====
