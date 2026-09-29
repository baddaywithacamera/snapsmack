<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';

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
