<?php
/**
 * Regression guard (50 SHADES 1.6.0): the ASYMMETRIC (MOSAIC) wall layout.
 *
 * Checks the parts a browser test cannot see: that the skin offers the fourth
 * layout, that its controls exist and are gated to it, that the MOSAIC engine is
 * loaded only on that layout, and that the block-splitting rule is sound for
 * every archive size. The geometry itself is covered by
 * tests/fsog-mosaic-archive.test.js against the real engine.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
$root = dirname(__DIR__);
$skin = $root . '/skins/50-shades-of-noah-grey/';
$m    = json_decode(file_get_contents($skin . 'manifest.json'), true);
$al   = file_get_contents($skin . 'archive-layout.php');
$css  = file_get_contents($skin . 'style.css');
$sw   = file_get_contents($root . '/assets/js/ss-engine-archive-grid-switch.js');

$fail = function ($msg) { fwrite(STDERR, "FAIL: {$msg}\n"); exit(1); };

// --- 1. the fourth layout is offered --------------------------------------
$layouts = array_keys($m['options']['masonry_layout']['options'] ?? []);
if ($layouts !== ['rows', 'columns', 'square', 'mosaic']) {
    $fail('masonry_layout must offer rows/columns/square/mosaic, got: ' . implode(',', $layouts));
}

// --- 2. the controls SCROLL has, that this skin was missing ----------------
foreach (['masonry_square_cols' => '--ss-square-cols',
          'masonry_corner_radius' => '--masonry-corner-radius'] as $key => $prop) {
    if (empty($m['options'][$key])) $fail("manifest option {$key}");
    if (($m['options'][$key]['property'] ?? '') !== $prop) $fail("{$key} must drive {$prop}");
}
if (empty($m['options']['masonry_mosaic_emphasis'])) $fail('manifest option masonry_mosaic_emphasis');

// --- 3. layout-specific controls are gated to their layout ----------------
$gates = [
    'masonry_square_cols'     => 'square',
    'masonry_mosaic_emphasis' => 'mosaic',
];
foreach ($gates as $key => $layout) {
    $when = $m['options'][$key]['enable_when']['masonry_layout'] ?? null;
    if ($when !== $layout) $fail("{$key} must be enable_when masonry_layout={$layout}");
}

// --- 4. Square no longer borrows the Masonry tile-size variable -----------
if (strpos($css, 'repeat(var(--ss-square-cols') === false) {
    $fail('style.css: .ss-square-wall must use --ss-square-cols, not --ss-cols');
}

// --- 5. the MOSAIC engine loads on that layout and only that layout -------
if (strpos($al, 'ss-mosaic-wall') === false)          $fail('archive-layout: ss-mosaic-wall container class');
if (strpos($al, 'snap-mosaic') === false)             $fail('archive-layout: .snap-mosaic block markup');
if (strpos($al, 'data-mosaic=') === false)            $fail('archive-layout: data-mosaic payload');
if (strpos($al, 'ss-engine-mosaic.js') === false)     $fail('archive-layout: loads ss-engine-mosaic.js');
if (strpos($al, 'ss-engine-mosaic-feed.js') !== false) {
    $fail('archive-layout must NOT load the feed engine: an archive is a finite set');
}
if (!preg_match('/if \(\$_fsog_is_mosaic\):\s*\?>\s*<!--/s', $al)) {
    $fail('the mosaic stylesheet/script must sit behind the $_fsog_is_mosaic guard');
}

// --- 6. the T/M switch relayouts a quilt revealed from display:none -------
if (strpos($sw, 'SnapMosaic') === false || strpos($sw, 'renderMosaic') === false) {
    $fail('grid switch must re-render the mosaic when the M pane becomes visible');
}

// --- 7. the block-splitting rule, taken from the shipping file ------------
if (!preg_match('/function fsog_mosaic_block_sizes\(int \$total\): array \{.*?\n    \}/s', $al, $fn)) {
    $fail('could not find fsog_mosaic_block_sizes in archive-layout.php');
}
eval($fn[0]);

for ($n = 0; $n <= 400; $n++) {
    $sizes = fsog_mosaic_block_sizes($n);
    if (array_sum($sizes) !== $n) {
        $fail("n={$n}: blocks hold " . array_sum($sizes) . " photographs, not {$n}");
    }
    foreach ($sizes as $s) {
        if ($s < 1 || $s > 6) $fail("n={$n}: block of {$s} (must be 1..6)");
    }
    // The eatmeclaude rule exists to avoid a final block of a single photograph
    // whenever there is enough material to avoid it.
    if ($n > 6 && end($sizes) === 1) {
        $fail("n={$n}: last block is a single orphan photograph");
    }
}

echo "50 SHADES 1.6.0 MOSAIC archive regression checks passed (401 archive sizes).\n";
// ===== SNAPSMACK EOF =====
