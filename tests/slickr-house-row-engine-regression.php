<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-presentation.php';
$manifest = json_decode((string)file_get_contents($root . '/skins/slickr/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$layout = (string)file_get_contents($root . '/skins/slickr/layout.php');
$controller = (string)file_get_contents($root . '/core/public-controller.php');
$engine = (string)file_get_contents($root . '/assets/js/ss-engine-rows.js');

if (!in_array('smack-rows', $manifest['require_scripts'] ?? [], true)) {
    throw new RuntimeException('SLICKR must load the CMS-owned row wall engine.');
}
foreach (['smack-justified-lib', 'smack-justified', 'smack-tag-infinite'] as $obsolete) {
    if (in_array($obsolete, $manifest['require_scripts'] ?? [], true)) {
        throw new RuntimeException("SLICKR still loads obsolete engine: {$obsolete}");
    }
}
foreach (['ss-scroll-wall', "snap_render_component('wall-tile'", 'scroll-wall-sentinel', "['next_page']"] as $hook) {
    if (!str_contains($layout, $hook)) throw new RuntimeException("SLICKR row wall missing {$hook}.");
}
foreach (['justified-row', 'justified-item-overlay', 'style="--justified-gap'] as $obsolete) {
    if (str_contains($layout, $obsolete)) throw new RuntimeException("SLICKR still renders obsolete row markup: {$obsolete}");
}
if (str_contains($controller, 'justifiedPhotographFeed()')) {
    throw new RuntimeException('SLICKR still requests its old unbounded FJGallery feed.');
}
if (!str_contains($engine, "cssNum(cs, '--ss-row-height', 0)")) {
    throw new RuntimeException('CMS row engine lost the skin-controlled row-height contract.');
}
if (!str_contains($engine, "setAttribute('data-ss-rows-ready', '1')")) {
    throw new RuntimeException('CMS row engine no longer marks the wall ready after geometry is applied.');
}
$style = (string)file_get_contents($root . '/skins/slickr/style.css');
if (!str_contains($style, 'visibility: hidden;') || !str_contains($style, '[data-ss-rows-ready="1"]')) {
    throw new RuntimeException('SLICKR can flash its unsized wall before the CMS engine runs.');
}
$titleOption = $manifest['options']['show_wall_titles'] ?? [];
if (($titleOption['options']['0']['css'] ?? '') !== 'display: none;') {
    throw new RuntimeException('SLICKR lost its hover-title visibility control.');
}
$hiddenTitles = snapsmack_declared_skin_style('slickr', ['show_wall_titles' => '0']);
if (!str_contains($hiddenTitles, '#justified-grid.ss-scroll-wall .ss-masonry-item::after{display: none;}')) {
    throw new RuntimeException('SLICKR hover-title admin choice does not compile into its skin-scoped CSS.');
}

echo "SLICKR uses the CMS-owned row wall with bounded endless loading.\n";
// ===== SNAPSMACK EOF =====
