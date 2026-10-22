<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
require_once __DIR__ . '/strict-skin-feature-helper.inc';
snapsmack_assert_strict_skin_feature('scroll');
define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

$root = dirname(__DIR__);
$skin = $root . '/skins/scroll';
$manifest = json_decode((string) file_get_contents($skin . '/manifest.json'), true);
foreach (['smack-columns', 'smack-rows', 'smack-masonry'] as $handle) {
    if (!in_array($handle, $manifest['require_scripts'] ?? [], true)) {
        throw new RuntimeException("SCROLL no longer loads {$handle}.");
    }
}

$baseSite = [
    'site_name' => 'Used Car Parts', 'owner_name' => 'Photographer',
    'language' => 'en', 'direction' => 'ltr',
    'skin_style_url' => '/skins/scroll/style.css',
    'skin_presentation' => ['style' => snapsmack_trusted_html(''), 'options' => []],
];
$response = [
    'status' => 200, 'kind' => 'landing', 'next_page' => 2, 'navigation' => [],
    'items' => [[
        'img_title' => 'Landscape', 'img_slug' => 'landscape',
        'img_thumb_aspect' => '/media/landscape.jpg',
        'presentation_width' => 1800, 'presentation_height' => 1200,
    ]],
];
$classes = ['columns' => 'ss-masonry', 'rows' => 'ss-scroll-wall', 'square' => 'ss-square-wall', 'mosaic' => 'ss-asymmetric-wall'];
foreach ($classes as $choice => $expected) {
    $site = $baseSite;
    $site['skin_presentation']['options']['scroll_wall_layout'] = $choice;
    $view = snapsmack_build_skin_view($response, $site);
    ob_start();
    $ok = snapsmack_render_strict_skin_template($skin, 'layout.php', $view);
    $html = (string) ob_get_clean();
    if (!$ok || !str_contains($html, 'scroll-wall ' . $expected) || !str_contains($html, 'data-wall-layout="' . $choice . '"')) {
        throw new RuntimeException("SCROLL {$choice} selection does not reach {$expected}.");
    }
}

$engine = (string) file_get_contents($root . '/assets/js/ss-engine-masonry.js');
foreach (['.ss-asymmetric-wall', 'grid.__ssMasonryRun', 'scroll-wall-sentinel', "url.searchParams.set('page'", 'relayout(grid)'] as $hook) {
    if (!str_contains($engine, $hook)) throw new RuntimeException("Asymmetric engine lost {$hook}.");
}
$css = (string) file_get_contents($skin . '/style.css');
foreach (['.ss-asymmetric-wall {', 'grid-auto-flow: dense', 'grid-column: span var(--ss-cols, 1)', 'grid-row: span var(--ss-rows, 1)'] as $rule) {
    if (!str_contains($css, $rule)) throw new RuntimeException("SCROLL asymmetric CSS lost {$rule}.");
}
if (!str_contains($css, 'var(--ss-cols, 3)')) {
    throw new RuntimeException('SCROLL asymmetric sizing is disconnected from the Tile Size control.');
}
$manifestRaw = (string) file_get_contents($skin . '/manifest.json');
if (substr_count($manifestRaw, '.ss-masonry, .ss-scroll-wall, .ss-asymmetric-wall') < 2) {
    throw new RuntimeException('SCROLL Tile Size and Gap controls do not both target the asymmetric wall.');
}

echo "SCROLL asymmetric masonry and endless append contract: ok\n";
// ===== SNAPSMACK EOF =====
