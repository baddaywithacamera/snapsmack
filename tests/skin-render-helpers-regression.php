<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/custom-code-policy.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';

$compiledStyle = snapsmack_skin_custom_style(['custom_css_public' => ':root{--saved:#123456} </style><script>alert(1)</script>']);
$compiledHtml = snap_render_html($compiledStyle);
if (!str_contains($compiledHtml, 'id="snapsmack-dynamic-css"')
    || !str_contains($compiledHtml, '--saved:#123456')
    || str_contains($compiledHtml, '</style><script>')) {
    throw new RuntimeException('CMS-compiled skin CSS token is missing or can escape its style element.');
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
$page = snap_render_html(snap_render_component('smacktalk-page', [
    'site' => ['site_name' => '<Site>', 'tagline' => '<Tag>'],
    'response' => [
        'kind' => 'single', 'navigation' => [],
        'post' => ['title' => '<Story>', 'created_at' => '2026-09-28', 'featured_image_path' => ''],
        'rendered_content' => snapsmack_trusted_html('<p>CMS HTML</p>'),
        'comments_enabled' => true,
        'comments' => [['comment_author' => '<Reader>', 'comment_text' => '<script>no</script>']],
    ],
]));
if (!str_contains($page, '<p>CMS HTML</p>') || str_contains($page, '<script>') || !str_contains($page, '&lt;Story&gt;')) {
    throw new RuntimeException('Shared SMACKTALK page crossed its trust boundary.');
}
$public = snap_render_html(snap_render_component('public-page', [
    'site' => ['site_name' => 'Site'],
    'response' => ['kind' => 'photo', 'navigation' => [], 'item' => ['img_title' => '<Photo>', 'img_description' => snapsmack_trusted_html('<p>CMS caption</p>')], 'comments' => []],
]));
if (!str_contains($public, '<p>CMS caption</p>') || !str_contains($public, '&lt;Photo&gt;')) throw new RuntimeException('Public component trust boundary failed.');
$presentationHooks = ['id="header"', 'class="site-title-text"', 'class="nav-menu"', 'id="scroll-stage"', 'id="photobox"', 'id="infobox"', 'id="system-footer"', 'id="sig-text"'];
foreach ($presentationHooks as $hook) {
    if (!str_contains($public, $hook)) throw new RuntimeException("Public component lost presentation hook: {$hook}");
}
$feed = snap_render_html(snap_render_component('public-page', [
    'site' => ['site_name' => 'Site'],
    'response' => ['kind' => 'landing', 'navigation' => [], 'items' => []],
]));
if (!str_contains($feed, 'id="browse-grid"')) throw new RuntimeException('Public feed lost its CMS-owned grid hook.');
$mayhem = snap_render_html(snap_render_component('public-page', [
    'site' => ['site_name' => 'Site', 'registered_assets' => ['scripts' => ['/assets/js/ss-engine-organized-mayhem.js']]],
    'response' => ['kind' => 'landing', 'navigation' => [], 'items' => []],
]));
if (!str_contains($mayhem, 'data-mayhem') || !str_contains($mayhem, 'data-api-url="?ajax=mayhem"')) throw new RuntimeException('CMS did not mount the declared Organized Mayhem engine.');
$instantPresentation = snapsmack_instant_camera_presentation([
    'ic_scrim' => '85', 'ic_panel_color' => '#abcdef', 'ic_panel_opacity' => '65',
    'ic_format' => 'polaroid', 'mayhem_initial_count' => '90', 'mayhem_max_width' => '180',
    'mayhem_overlap_max' => '70', 'mayhem_drift' => '0', 'mayhem_warp' => '1',
    'ic_navline_shadow_color' => '#102030', 'ic_navline_shadow_size' => '2', 'ic_navline_shadow_opacity' => '80',
]);
$instant = snap_render_html(snap_render_component('public-page', [
    'site' => ['site_name' => 'Camera', 'tagline' => 'Tag', 'site_description' => 'Bio', 'avatar_url' => '/avatar.jpg', 'skin_slug' => 'instant-camera', 'skin_custom_style' => $compiledStyle, 'skin_presentation' => $instantPresentation, 'registered_assets' => ['scripts' => ['/assets/js/ss-engine-organized-mayhem.js']]],
    'response' => ['kind' => 'landing', 'navigation' => [], 'items' => [[
        'img_slug' => 'sample', 'img_title' => '<Photo>', 'img_alt' => 'Alt',
        'img_file' => 'img_uploads/photo.jpg', 'img_thumb_aspect' => 'img_uploads/thumbs/a_photo.jpg',
    ]]],
]));
foreach (['id="snapsmack-dynamic-css"', '--saved:#123456', 'id="snapsmack-skin-presentation"', '--ic-scrim:0.85', '--panel-bg:rgba(171,205,239,0.65)', '--ic-tile-aspect:823 / 1000', '--ic-navline-shadow:0 2px 2px -2px rgba(16,32,48,0.80),inset 0 2px 2px -2px rgba(16,32,48,0.80)', 'class="ic-bg ic-bg-mayhem"', 'data-initial-count="90"', 'data-max-width="180"', 'data-overlap-max="0.70"', 'data-drift="0"', 'data-warp="1"', 'class="ic-scrim"', 'class="ic-panel"', 'class="tg-content-wrap landing-feed"', 'class="tg-profile-avatar"', 'class="tg-profile-username"', 'class="tg-sticky-nav-links"', 'id="browse-grid" class="tg-grid', 'class="tg-tile"', 'img_uploads/thumbs/a_photo.jpg', '&lt;Photo&gt;', 'data-mayhem'] as $hook) {
    if (!str_contains($instant, $hook)) throw new RuntimeException("INSTANT CAMERA CMS renderer lost hook: {$hook}");
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
