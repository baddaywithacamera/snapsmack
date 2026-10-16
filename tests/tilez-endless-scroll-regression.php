<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
declare(strict_types=1);
define('SNAPSMACK_SKIN_RENDER', true);

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

$root = dirname(__DIR__);
$layout = (string) file_get_contents($root . '/skins/tilez/layout.php');
$manifest = json_decode((string) file_get_contents($root . '/skins/tilez/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

foreach (['scroll-wall ss-masonry', 'scroll-wall-sentinel', 'data-next=', 'data-has-more="1"'] as $hook) {
    if (!str_contains($layout, $hook)) throw new RuntimeException("TILEZ feed lost endless-scroll hook: {$hook}");
}
if (str_contains($layout, '<nav class="archive-nav"')) throw new RuntimeException('TILEZ still exposes the numbered feed pager.');
if (!in_array('smack-columns', $manifest['require_scripts'] ?? [], true)) throw new RuntimeException('TILEZ does not load SCROLL\'s shared columns/endless-scroll engine.');
if (in_array('smack-tag-infinite', $manifest['require_scripts'] ?? [], true)) throw new RuntimeException('TILEZ still loads the unrelated generic feed pager.');

$response = ['kind' => 'feed', 'status' => 200, 'page' => 1, 'total_pages' => 2, 'next_page' => 2, 'posts' => [[
    'title' => 'First post', 'url' => '/first-post', 'image_url' => '/media/first.jpg',
    'width' => 1600, 'height' => 1200, 'created_label' => 'Oct 16, 2026',
]]];
$site = ['site_name' => 'Example', 'base_url' => 'https://example.test/', 'language' => 'en', 'direction' => 'ltr', 'skin_slug' => 'tilez', 'skin_style_url' => '/skins/tilez/style.css'];
$view = snapsmack_build_skin_view($response, $site);
ob_start();
$ok = snapsmack_render_strict_skin_template($root . '/skins/tilez', 'layout.php', $view);
$html = (string) ob_get_clean();
if (!$ok || !str_contains($html, 'class="scroll-wall-sentinel"') || !str_contains($html, 'data-next="2"')) throw new RuntimeException('TILEZ first feed page did not render SCROLL\'s next-page sentinel.');
if (str_contains($html, '>1 / 2<')) throw new RuntimeException('TILEZ rendered the obsolete visible page counter.');

$response['page'] = 2;
$response['next_page'] = 0;
$view = snapsmack_build_skin_view($response, $site);
ob_start();
$ok = snapsmack_render_strict_skin_template($root . '/skins/tilez', 'layout.php', $view);
$html = (string) ob_get_clean();
if (!$ok || str_contains($html, 'class="scroll-wall-sentinel"')) throw new RuntimeException('TILEZ final feed page did not stop endless scrolling.');

echo "tilez endless-scroll regression: ok\n";
// ===== SNAPSMACK EOF =====
