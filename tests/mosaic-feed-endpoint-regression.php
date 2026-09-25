<?php
/**
 * Regression guard: no skin fetches MOSAIC blocks from eatmeclaude.php.
 *
 * eatmeclaude.php is a test page and is expected to be deleted. SCROLL's
 * Asymmetric wall used to stream its second and later blocks from it, so
 * deleting the page would have left that wall showing six photographs and then
 * stopping — silently, because the failure is a fetch nobody watches.
 *
 * A skin that serves MOSAIC blocks must now serve them from its own endpoint and
 * say so with data-endpoint on the feed container. The engine's default is still
 * eatmeclaude.php so that page keeps working on its own until it goes.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
$root = dirname(__DIR__);
$fail = function ($msg) { fwrite(STDERR, "FAIL: {$msg}\n"); exit(1); };

// --- 1. the feed engine must not hardcode the endpoint --------------------
$feed = file_get_contents($root . '/assets/js/ss-engine-mosaic-feed.js');
if (strpos($feed, "data-endpoint") === false) {
    $fail('ss-engine-mosaic-feed.js must read data-endpoint');
}
if (preg_match("/var url = 'eatmeclaude\.php/", $feed)) {
    $fail('ss-engine-mosaic-feed.js still builds a hardcoded eatmeclaude.php URL');
}

// --- 2. every skin that renders a mosaic feed declares its own endpoint ----
foreach (glob($root . '/skins/*/*.php') as $file) {
    $src = file_get_contents($file);
    if (strpos($src, 'ss-engine-mosaic-feed.js') === false) continue;   // no feed here
    $rel = 'skins/' . basename(dirname($file)) . '/' . basename($file);

    if (strpos($src, 'data-endpoint=') === false) {
        $fail("{$rel} loads the mosaic feed but sets no data-endpoint, so it would "
            . "fall back to eatmeclaude.php");
    }
    if (preg_match('/data-endpoint="[^"]*eatmeclaude/', $src)) {
        $fail("{$rel} points data-endpoint at eatmeclaude.php");
    }
}

// --- 3. SCROLL serves the block payload from its own JSON path ------------
$wall = file_get_contents($root . '/skins/scroll/wall.php');
if (strpos($wall, 'data-endpoint="?pg=wall&amp;format=json"') === false) {
    $fail('skins/scroll/wall.php must point the feed at its own ?pg=wall&format=json');
}
if (!preg_match('/if \(\$is_json\) \{.*?if \(\$_is_mosaic\) \{/s', $wall)) {
    $fail('skins/scroll/wall.php JSON path must answer mosaic block requests');
}
foreach (["'images'", "'has_more'", "'next'"] as $key) {
    if (strpos($wall, $key . '   =>') === false && strpos($wall, $key . ' =>') === false) {
        $fail("skins/scroll/wall.php mosaic JSON must return {$key}");
    }
}
// The helpers have to be defined before the JSON path reaches them.
$helper_at = strpos($wall, 'function eatmeclaude_block_sizes');
$json_at   = strpos($wall, 'if ($is_json) {');
if ($helper_at === false || $json_at === false || $helper_at > $json_at) {
    $fail('the mosaic block helpers must be defined before the JSON path uses them');
}
// Each conditional function declaration must close immediately after the
// function. Otherwise the wall query and render path become conditional on the
// helper not already existing, which only shows up when this file is reused.
if (!preg_match('/function eatmeclaude_images\(.*?return \$out;\s*}\s*}\s*\$grid_stmt/s', $wall)) {
    $fail('eatmeclaude_images function_exists guard must close before the wall query');
}
if (!preg_match('/if \(\$_is_mosaic\) \{\s*\$emc_block_sizes.*?\$emc_emphasis = \$_ss_wall_emph;\s*}/s', $wall)) {
    $fail('SCROLL mosaic setup must be scoped inside the mosaic layout condition');
}

// --- 4. 50 SHADES renders a finite archive and must not load the feed -----
$fsog = file_get_contents($root . '/skins/50-shades-of-noah-grey/archive-layout.php');
if (strpos($fsog, 'ss-engine-mosaic-feed.js') !== false) {
    $fail('50 SHADES must not load the feed engine: an archive is a finite set');
}

echo "MOSAIC feed endpoint regression checks passed.\n";
// ===== SNAPSMACK EOF =====
