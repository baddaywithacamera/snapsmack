<?php
/**
 * Regression guard (50 SHADES 1.5.0): the M archive layout is a select over the
 * SCROLL wall engine's three tile layouts, driven by manifest controls, and the
 * T/M switch must not force display:block (Square is a CSS grid).
 */
$root = dirname(__DIR__);
$skin = $root . '/skins/50-shades-of-noah-grey/';
$m    = json_decode(file_get_contents($skin . 'manifest.json'), true);
$al   = file_get_contents($skin . 'archive-layout.php');
$css  = file_get_contents($skin . 'style.css');
$sw   = file_get_contents($root . '/assets/js/ss-engine-archive-grid-switch.js');

$fail = function ($msg) { fwrite(STDERR, "Missing: {$msg}\n"); exit(1); };
foreach (['masonry_layout', 'masonry_cols', 'masonry_gap', 'masonry_width_pct'] as $k) {
    if (empty($m['options'][$k])) $fail("manifest option {$k}");
}
if (($m['options']['masonry_cols']['property'] ?? '') !== '--ss-cols') $fail('masonry_cols → --ss-cols');
if (($m['options']['masonry_gap']['property']  ?? '') !== '--ss-gap')  $fail('masonry_gap → --ss-gap');
if (array_keys($m['options']['masonry_layout']['options']) !== ['rows', 'columns', 'square']) $fail('layout options rows/columns/square');
if (!in_array('smack-columns', $m['require_scripts'] ?? [], true)) $fail('smack-columns required');
foreach (["'ss-masonry'", "'ss-square-wall'", "'ss-scroll-wall'", 'masonry_layout'] as $n) {
    if (strpos($al, $n) === false) $fail("archive-layout {$n}");
}
if (strpos($al, '--ss-gap:') !== false) $fail('no inline --ss-gap (would override the control)');
foreach (['#justified-grid.ss-square-wall', '#justified-grid.ss-masonry', 'var(--masonry-width'] as $n) {
    if (strpos($css, $n) === false) $fail("style.css {$n}");
}
if (strpos($sw, "justifiedGrid.style.display = 'block'") !== false) $fail("grid switch must not force block");
if (strpos($sw, 'SSRows.relayout') === false || strpos($sw, 'SSColumns.relayout') === false) $fail('grid switch relayouts engines');
echo "50 SHADES masonry layout select regression checks passed.\n";
// ===== SNAPSMACK EOF =====
