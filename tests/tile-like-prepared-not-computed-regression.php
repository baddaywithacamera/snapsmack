<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * THE HEART IS PREPARED ONCE, AND A SKIN ONLY PRINTS IT.
 *
 * GLIDE is a strict skin: its layout may print prepared view data and call
 * components, but it may not call a helper or run a query. SS-1323 shipped
 * snap_tile_like_enabled/state/html() as helpers and nothing that prepared
 * per-item state, so placing the control would have meant a strict layout
 * calling PHP -- which the boundary and the security ratchet both forbid.
 * Codex found that before touching GLIDE and stopped.
 *
 * What these checks pin:
 *   1. the 'tile-like' component COMPUTES NOTHING -- it prints the prepared
 *      fragment and renders nothing when there is none, so a skin that never
 *      asked for hearts gets no empty button;
 *   2. the preparation is OPT-IN BY MANIFEST, through require_scripts, so a
 *      skin pays neither the query nor the markup unless it asked;
 *   3. it is ONE query for the page, not one per photograph, which is the
 *      whole reason it cannot live in the tile.
 */

require_once __DIR__ . '/../core/skin-render-helpers.php';
require_once __DIR__ . '/../core/tile-like-control.php';

$failures = [];

// --- 1. the component is a printer, not a decider -------------------------
$withFragment = snap_render_component('tile-like', [
    'item' => ['id' => 7, 'tile_like' => '<button data-tile-like="7"></button>'],
]);
if (strpos((string)$withFragment, 'data-tile-like="7"') === false) {
    $failures[] = 'the component did not print the prepared fragment';
}

$withoutFragment = snap_render_component('tile-like', ['item' => ['id' => 7]]);
if (trim((string)$withoutFragment) !== '') {
    $failures[] = 'an item with no prepared fragment rendered something anyway';
}

$noItem = snap_render_component('tile-like', []);
if (trim((string)$noItem) !== '') {
    $failures[] = 'the component rendered something with no item at all';
}

// The component must not reach for the helpers. If it ever starts deciding,
// the strict boundary is broken again and a conveyor pays per tile.
$helpers = (string)@file_get_contents(dirname(__DIR__) . '/core/skin-render-helpers.php');
$start = strpos($helpers, "\$name === 'tile-like'");
$end = $start === false ? false : strpos($helpers, "} elseif (", $start + 10);
$body = ($start !== false && $end !== false) ? substr($helpers, $start, $end - $start) : '';
if ($body === '') {
    $failures[] = 'could not isolate the tile-like component body to check it';
} else {
    foreach (['snap_tile_like_state', 'snap_tile_like_html', 'snap_tile_like_enabled', '->prepare(', '->query('] as $forbidden) {
        if (strpos($body, $forbidden) !== false) {
            $failures[] = "the tile-like component calls {$forbidden} -- it must only print";
        }
    }
}

// --- 2 and 3. the preparation is opt-in, and asks once --------------------
$runtime = (string)@file_get_contents(dirname(__DIR__) . '/core/public-runtime.php');
if (strpos($runtime, "in_array('smack-tile-like', \$_tileRequires, true)") === false) {
    $failures[] = 'the preparation is not gated on the manifest declaring smack-tile-like';
}
if (strpos($runtime, 'snap_tile_like_enabled($settings)') === false) {
    $failures[] = 'the preparation does not check the site setting';
}
if (substr_count($runtime, 'snap_tile_like_state(') !== 1) {
    $failures[] = 'the liked state is asked for more than once per request';
}
if (strpos($runtime, 'snap_tile_like_assets()') === false) {
    $failures[] = 'the engine and stylesheet are not merged into required_assets';
}

if ($failures) {
    throw new RuntimeException("Tile-like preparation contract broken:\n  - "
        . implode("\n  - ", $failures));
}

echo "Tile heart: prepared once, printed by the skin, opt-in by manifest: passed.\n";
// ===== SNAPSMACK EOF =====
