<?php
declare(strict_types=1);

// Local-only browser fixture for presentation verification; never part of a release route.
if (PHP_SAPI !== 'cli-server' || !in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Local development server only');
}
$root = dirname(__DIR__, 2);
$requestPath = rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
$staticPath = $root . str_replace('/', DIRECTORY_SEPARATOR, $requestPath);
if ($requestPath !== '/' && is_file($staticPath) && strtolower(pathinfo($staticPath, PATHINFO_EXTENSION)) !== 'php') {
    return false;
}
$slug = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['skin'] ?? 'telegram'));
$skinDir = $root . '/skins/' . $slug;
$manifestPath = $skinDir . '/manifest.json';
if (!is_file($manifestPath)) {
    http_response_code(404);
    exit('Unknown skin');
}
$manifest = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
if (($manifest['schema_version'] ?? null) !== 2) {
    http_response_code(409);
    exit('Strict skins only');
}

define('SNAPSMACK_SKIN_RENDER', true);
define('BASE_URL', '/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/asset-registry.php';

$nav = [
    ['label' => 'Home', 'url' => '/'],
    ['label' => 'Archive', 'url' => '/archive'],
    ['label' => 'About', 'url' => '/page/about'],
];
$site = [
    'site_name' => 'SNAPSMACK VISUAL CHECK',
    'owner_name' => 'Example Photographer',
    'tagline' => 'The CMS decides and acts. The skin presents.',
    'base_url' => '/',
    'language' => 'en',
    'direction' => 'ltr',
    'skin_style_url' => '/skins/' . $slug . '/style.css',
    'registered_assets' => snapsmack_skin_declared_assets($manifest),
];

if (($manifest['cms_controller'] ?? '') === 'smacktalk') {
    $response = [
        'kind' => 'single',
        'status' => 200,
        'page_title' => 'A secure presentation boundary',
        'navigation' => $nav,
        'post' => ['title' => 'A secure presentation boundary', 'created_at' => '2026-09-29', 'featured_image_path' => ''],
        'rendered_content' => snapsmack_trusted_html('<p>This representative long-form story is rendered by the CMS and arranged by the selected skin.</p><p>No skin code selected this content or touched persistent state.</p>'),
        'colophon' => 'Local fixture — no database or network access.',
        'comments_enabled' => true,
        'comments' => [['comment_author' => 'Reader', 'comment_text' => 'The presentation remains distinct.']],
    ];
} else {
    $response = [
        'kind' => 'photo',
        'status' => 200,
        'page_title' => 'A secure presentation boundary',
        'navigation' => $nav,
        'item' => [
            'img_title' => 'A secure presentation boundary',
            'img_date' => '2026-09-29',
            'img_alt' => 'Neutral visual fixture placeholder',
            'img_description' => snapsmack_trusted_html('<p>This representative photograph caption is rendered by the CMS and arranged by the selected skin.</p>'),
        ],
        'comments' => [['comment_author' => 'Reader', 'comment_text' => 'The presentation remains distinct.']],
    ];
}

$view = snapsmack_build_skin_view($response, $site);
$template = (string)($manifest['templates'][$response['kind']] ?? $manifest['templates']['default'] ?? '');
if ($template === '' || !snapsmack_render_strict_skin_template($skinDir, $template, $view)) {
    http_response_code(500);
    echo 'Render failed';
}
