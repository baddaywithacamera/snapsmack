<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/custom-code-policy.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);

if (html_entity_decode(snap_route_url('hashtag', ['slug' => 'cats', 'page' => 2]), ENT_QUOTES) !== '/?page=2&tag=cats') {
    throw new RuntimeException('Hashtag URL does not match the public ?tag= router.');
}

$compiledStyle = snapsmack_skin_custom_style(['custom_css_public' => ':root{--saved:#123456} </style><script>alert(1)</script>']);
$compiledHtml = snap_render_html($compiledStyle);
if (!str_contains($compiledHtml, 'id="snapsmack-dynamic-css"')
    || !str_contains($compiledHtml, '--saved:#123456')
    || str_contains($compiledHtml, '</style><script>')) {
    throw new RuntimeException('CMS-compiled skin CSS token is missing or can escape its style element.');
}
$staleGeneratedStyle = snap_render_html(snapsmack_skin_custom_style([
    'custom_css_public' => '/* SKIN_START */body{font-family:"Wrong"}/* SKIN_END */ .owner-rule{color:red}',
]));
if (str_contains($staleGeneratedStyle, 'Wrong') || !str_contains($staleGeneratedStyle, '.owner-rule{color:red}')) {
    throw new RuntimeException('Strict skin replayed a stale generated CSS block or lost owner CSS.');
}
$gridPresentation = snapsmack_skin_presentation([
    'tg_max_width' => '840', 'tg_gutter' => '72', 'tg_gap' => '10',
    'tg_font_body' => 'Figtree',
], 'the-grid');
$gridCss = (string)($gridPresentation['style'] ?? '');
foreach (['--grid-max-width:840px', '--grid-gutter:72px', '--grid-gap:10px', '--font-body:Figtree'] as $expected) {
    if (!str_contains($gridCss, $expected)) throw new RuntimeException("Declared THE GRID setting was not rendered: {$expected}");
}

if (snap_escape_html('<script>') !== '&lt;script&gt;') throw new RuntimeException('HTML escaping failed.');
if (snap_escape_attr('" onerror="x') !== '&quot; onerror=&quot;x') throw new RuntimeException('Attribute escaping failed.');
foreach (['javascript:alert(1)', 'data:text/html,x', '//evil.example/x'] as $url) {
    if (snap_escape_url($url) !== '') throw new RuntimeException("Active URL scheme accepted: {$url}");
}
if (snap_render_html('<b>plain</b>') !== '&lt;b&gt;plain&lt;/b&gt;') throw new RuntimeException('Ordinary string gained HTML trust.');
$component = snap_render_component('navigation', ['items' => [['url' => 'javascript:x', 'label' => '<Admin>']]]);
$rendered = snap_render_html($component);
if (str_contains($rendered, 'javascript:') || !str_contains($rendered, '&lt;Admin&gt;')) {
    throw new RuntimeException('Shared component failed context escaping.');
}
if (snap_render_html(snap_render_component('unknown', [])) !== '') throw new RuntimeException('Unknown component did not fail closed.');
$smacktalkView = snapsmack_build_skin_view([
    'kind' => 'single', 'navigation' => [],
    'post' => ['title' => '<Story>', 'created_at' => '2026-09-28', 'featured_image_path' => ''],
    'rendered_content' => snapsmack_trusted_html('<p>CMS HTML</p>'),
    'comments_enabled' => true,
    'comments' => [['comment_author' => '<Reader>', 'comment_text' => '<script>no</script>']],
], ['site_name' => '<Site>', 'tagline' => '<Tag>', 'language' => 'en', 'direction' => 'ltr', 'skin_style_url' => '/skin.css']);
ob_start();
$smacktalkRendered = snapsmack_render_strict_skin_template(dirname(__DIR__) . '/skins/alfred', 'layout.php', $smacktalkView);
$page = (string)ob_get_clean();
if (!$smacktalkRendered) throw new RuntimeException('SMACKTALK strict layout did not render.');
if (!str_contains($page, '<p>CMS HTML</p>') || str_contains($page, '<script>') || !str_contains($page, '&lt;Story&gt;')) {
    throw new RuntimeException('SMACKTALK layout crossed its trust boundary.');
}
$publicView = snapsmack_build_skin_view(
    ['kind' => 'photo', 'navigation' => [], 'item' => ['img_title' => '<Photo>', 'img_description' => snapsmack_trusted_html('<p>CMS caption</p>')], 'comments' => []],
    ['site_name' => 'Site', 'language' => 'en', 'direction' => 'ltr', 'skin_style_url' => '/skin.css',
        'skin_presentation' => snapsmack_skin_presentation([], 'slickr')]
);
ob_start();
$publicRendered = snapsmack_render_strict_skin_template(dirname(__DIR__) . '/skins/slickr', 'layout.php', $publicView);
$public = (string)ob_get_clean();
if (!$publicRendered || !str_contains($public, '<p>CMS caption</p>') || !str_contains($public, '&lt;Photo&gt;')) throw new RuntimeException('Public skin trust boundary failed.');
$presentationHooks = ['class="sl-masthead"', 'class="sl-cover"', 'class="sl-profile-inner"', 'class="sl-profile-tabs"', 'class="sl-single-flow h-entry"', 'id="sl-photobox"', 'class="sl-sidebar"', 'id="system-footer"'];
foreach ($presentationHooks as $hook) {
    if (!str_contains($public, $hook)) throw new RuntimeException("Public layout lost presentation hook: {$hook}");
}
$instantPresentation = snapsmack_skin_presentation([
    'ic_scrim' => '85', 'ic_panel_color' => '#abcdef', 'ic_panel_opacity' => '65',
    'ic_format' => 'polaroid', 'mayhem_initial_count' => '90', 'mayhem_max_width' => '180',
    'mayhem_overlap_max' => '70', 'mayhem_drift' => '0', 'mayhem_warp' => '1',
    'ic_navline_shadow_color' => '#102030', 'ic_navline_shadow_size' => '2', 'ic_navline_shadow_opacity' => '80',
], 'instant-camera');
$searchDock = snapsmack_gram_search_dock_presentation([
    'search_enabled' => '1', 'search_placeholder' => 'Find a fauxlaroid',
    'gsd_disc_color' => '#abcdef', 'gsd_disc_opacity' => '85', 'gsd_glass_color' => '#102030',
]);
$instantView = snapsmack_build_skin_view([
    'kind' => 'landing', 'photo_count' => 1, 'navigation' => [['label'=>'Home','url'=>'/'],['label'=>'More','url'=>'','children'=>[['label'=>'About','url'=>'/page.php?slug=about']]]], 'items' => [[
        'img_slug' => 'sample', 'img_title' => '<Photo>', 'img_alt' => 'Alt',
        'img_file' => 'img_uploads/photo.jpg', 'img_thumb_aspect' => 'img_uploads/thumbs/a_photo.jpg',
    ]],
], [
    'site_name' => 'Camera', 'tagline' => 'Tag', 'site_description' => 'Bio', 'avatar_url' => '/avatar.jpg',
    'language' => 'en', 'direction' => 'ltr', 'skin_slug' => 'instant-camera', 'skin_style_url' => '/skins/instant-camera/style.css',
    'skin_custom_style' => $compiledStyle, 'skin_presentation' => $instantPresentation, 'search_dock' => $searchDock,
    'registered_assets' => ['scripts' => ['/assets/js/ss-engine-organized-mayhem.js', '/assets/js/ss-engine-nav-dropdown.js']],
]);
ob_start();
$renderedInstant = snapsmack_render_strict_skin_template(dirname(__DIR__) . '/skins/instant-camera', 'layout.php', $instantView);
$instant = (string)ob_get_clean();
if (!$renderedInstant) throw new RuntimeException('INSTANT CAMERA strict layout did not render.');
foreach (['id="snapsmack-dynamic-css"', '--saved:#123456', 'id="snapsmack-skin-presentation"', '--ic-scrim:0.85', '--panel-bg:rgba(171,205,239,0.65)', '--ic-tile-aspect:823 / 1000', '--ic-navline-shadow:0 2px 2px -2px rgba(16,32,48,0.80),inset 0 2px 2px -2px rgba(16,32,48,0.80)', 'class="nav-has-children"', 'class="nav-submenu"', '/page.php?slug=about', 'ss-engine-nav-dropdown.js', 'class="ic-bg ic-bg-mayhem"', 'data-initial-count="90"', 'data-max-width="180"', 'data-overlap-max="0.70"', 'data-drift="0"', 'data-warp="1"', 'class="ic-scrim"', 'class="ic-panel"', 'class="tg-content-wrap landing-feed"', 'class="tg-profile-avatar"', 'class="tg-profile-username"', 'class="tg-sticky-nav-links"', 'id="browse-grid" class="tg-grid', 'class="tg-tile"', 'img_uploads/thumbs/a_photo.jpg', '&lt;Photo&gt;', 'data-mayhem', 'class="gram-search-dock"', 'Find a fauxlaroid', '--gsd-disc-bg:rgba(171,205,239,0.85)', '--gsd-glass-color:#102030'] as $hook) {
    if (!str_contains($instant, $hook)) throw new RuntimeException("INSTANT CAMERA CMS renderer lost hook: {$hook}");
}
$instantCss = file_get_contents(dirname(__DIR__) . '/skins/instant-camera/style.css');
if (!str_contains((string)$instantCss, '.tg-sticky-nav.profile-hidden  { background: transparent !important; }')) {
    throw new RuntimeException('INSTANT CAMERA navbar no longer shares the translucent content plane.');
}
if (snap_route_url('unknown') !== '') throw new RuntimeException('Unknown route did not fail closed.');
if (snap_asset_url('../evil') !== '') throw new RuntimeException('Unknown/traversal asset handle was accepted.');

$source = (string)file_get_contents(dirname(__DIR__) . '/core/skin-render-helpers.php');
foreach (['$pdo', '$_GET', '$_POST', '$_REQUEST', '$_SERVER', 'file_put_contents', 'curl_', 'header('] as $forbidden) {
    if (str_contains($source, $forbidden)) throw new RuntimeException("Render helper owns forbidden authority: {$forbidden}");
}
$policy = (string)file_get_contents(dirname(__DIR__) . '/core/skin-security-policy.php');
foreach (['snap_escape_html', 'snap_escape_attr', 'snap_escape_url', 'snap_render_html', 'snap_render_component', 'snap_asset_url', 'snap_route_url'] as $helper) {
    if (!str_contains($policy, "'{$helper}'")) throw new RuntimeException("Template grammar omits helper: {$helper}");
}
echo "Approved skin render-helper regression passed.\n";
