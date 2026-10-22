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
foreach (['smack-columns', 'smack-rows', 'smack-mosaic', 'asset:public:ss-engine-mosaic-feed'] as $handle) {
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
$classes = ['columns' => 'ss-masonry', 'rows' => 'ss-scroll-wall', 'square' => 'ss-square-wall'];
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
$site = $baseSite;
$site['skin_presentation']['options']['scroll_wall_layout'] = 'mosaic';
$site['skin_presentation']['options']['scroll_mosaic_gap'] = '0';
$view = snapsmack_build_skin_view($response, $site);
ob_start();
$ok = snapsmack_render_strict_skin_template($skin, 'layout.php', $view);
$html = (string) ob_get_clean();
foreach (['eatmeclaude-feed', 'snap-mosaic eatmeclaude-block', 'data-mosaic=', 'eatmeclaude-sentinel'] as $hook) {
    if (!$ok || !str_contains($html, $hook)) throw new RuntimeException("SCROLL lost the September MOSAIC presentation hook {$hook}.");
}

$engine = (string) file_get_contents($root . '/assets/js/ss-engine-mosaic-feed.js');
foreach (['data-next-url', 'DOMParser', "querySelectorAll('#eatmeclaude-feed .snap-mosaic[data-mosaic]')", 'prepare(block)'] as $hook) {
    if (!str_contains($engine, $hook)) throw new RuntimeException("MOSAIC feed engine lost {$hook}.");
}

echo "SCROLL September MOSAIC presentation and CMS feed contract: ok\n";
// ===== SNAPSMACK EOF =====
