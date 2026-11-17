<?php
declare(strict_types=1);
define('SNAPSMACK_SKIN_RENDER', true);

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root . '/skins/true-grit/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (!in_array('smack-tag-infinite', $manifest['require_scripts'] ?? [], true)) {
    throw new RuntimeException('TRUE GRIT does not load the shared endless-scroll engine.');
}

// SS-987D: TRUE GRIT now pages through the shared core feed-pager component
// (prefix 'truegrit', grid #browse-grid) instead of inline tg-sentinel markup.
// Core prepares next_url for the archive on the strict path; the layout renders
// the pager only when next_url is set, so the test supplies it like the runtime.
$response = ['kind' => 'archive', 'status' => 200, 'page' => 1, 'total_pages' => 2,
    'next_page' => 2, 'next_url' => '/archive?page=2', 'items' => [[
    'img_title' => 'Texture', 'url' => '/texture', 'img_thumb_square' => '/media/texture.jpg',
]]];
$site = ['site_name' => 'Found Textures', 'base_url' => 'https://example.test/', 'language' => 'en', 'direction' => 'ltr', 'skin_slug' => 'true-grit', 'skin_style_url' => '/skins/true-grit/style.css'];
$view = snapsmack_build_skin_view($response, $site);
ob_start();
$ok = snapsmack_render_strict_skin_template($root . '/skins/true-grit', 'layout.php', $view);
$html = (string) ob_get_clean();
foreach (['tg-grid', 'tg-tile', 'id="truegrit-sentinel"', 'ss-feed-sentinel', 'data-feed',
          'data-next="2"', 'data-next-url', 'data-grid-selector="#browse-grid"'] as $hook) {
    if (!$ok || !str_contains($html, $hook)) throw new RuntimeException("TRUE GRIT archive lost endless-scroll hook: {$hook}");
}

// The last page carries no next_url, so no pager/sentinel is emitted and scrolling stops.
$response['page'] = 2;
$response['next_page'] = 0;
$response['next_url'] = '';
$view = snapsmack_build_skin_view($response, $site);
ob_start();
$ok = snapsmack_render_strict_skin_template($root . '/skins/true-grit', 'layout.php', $view);
$html = (string) ob_get_clean();
if (!$ok || str_contains($html, 'id="truegrit-sentinel"')) throw new RuntimeException('TRUE GRIT final archive page did not stop endless scrolling.');

echo "true grit endless-scroll regression: ok\n";
