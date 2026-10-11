<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

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
require_once $root . '/core/skin-presentation.php';
require_once $root . '/core/public-controller.php';

$nav = [
    ['label' => 'Home', 'url' => '/'],
    ['label' => 'Archive', 'url' => '/archive'],
    ['label' => 'About', 'url' => '/page/about'],
];
$requestedKind = preg_replace('/[^a-z_]/', '', strtolower((string)($_GET['kind'] ?? '')));
$fixtureImage = '/skins/' . $slug . '/screenshot-landing.png';
$fixtureItems = [];
for ($i = 1; $i <= 9; $i++) {
    $fixtureItems[] = [
        'img_title' => 'Fixture photograph ' . $i,
        'img_slug' => 'fixture-' . $i,
        'img_alt' => 'Deterministic visual fixture photograph ' . $i,
        'img_file' => $fixtureImage,
        'img_thumb_aspect' => $fixtureImage,
        'img_thumb_square' => $fixtureImage,
        'url' => '/fixture-' . $i,
        'width' => 1600,
        'height' => 1000,
        'image_count' => $i % 3 === 0 ? 3 : 1,
    ];
}
$fixtureSettings = ['active_skin' => $slug];
foreach (($manifest['options'] ?? []) as $key => $option) {
    if (is_string($key) && is_array($option) && array_key_exists('default', $option)) {
        $fixtureSettings[$key] = $option['default'];
    }
}
$fixtureItems = snapsmack_grid_frame_items($fixtureItems, $fixtureSettings, $slug);
$site = [
    'site_name' => 'SNAPSMACK VISUAL CHECK',
    'owner_name' => 'Example Photographer',
    'tagline' => 'The CMS decides and acts. The skin presents.',
    'site_description' => 'A deterministic local fixture for skin parity review.',
    'base_url' => '/',
    'language' => 'en',
    'direction' => 'ltr',
    'skin_style_url' => '/skins/' . $slug . '/style.css',
    'skin_slug' => $slug,
    'skin_presentation' => snapsmack_skin_presentation($fixtureSettings, $slug),
    'skin_custom_style' => snapsmack_trusted_html(''),
    'search_dock' => ['enabled' => false],
    'registered_assets' => snapsmack_skin_declared_assets($manifest),
    'footer' => [],
    'crop_circles' => [
        'identity' => 'SNAPSMACK VISUAL CHECK', 'home_url' => '/',
        'icon_sprite_url' => '/assets/icons/crop-circles.svg', 'navigation' => $nav,
        'search' => ['action'=>'/','placeholder'=>'Search or #tag…'],
        'filters' => [['label'=>'categories','type'=>'cat','items'=>[['id'=>1,'label'=>'Fixture']]]],
        'social' => [
            ['url'=>'https://example.invalid/vero','label'=>'Vero','icon'=>'vero'],
            ['url'=>'https://example.invalid/bluesky','label'=>'Bluesky','icon'=>'bluesky'],
            ['url'=>'https://example.invalid/links','label'=>'Linktree','icon'=>'linktree'],
            ['url'=>'https://example.invalid/','label'=>'Website','icon'=>'website'],
        ],
        'appearance' => ['icon'=>'#1a1a1a','background'=>'rgba(255,255,255,.7)','background_hover'=>'rgba(255,255,255,.95)','border'=>'rgba(26,26,26,.3)','border_hover'=>'rgba(26,26,26,.7)','opacity'=>.5],
    ],
];

if (($manifest['cms_controller'] ?? '') === 'smacktalk') {
    $kind = in_array($requestedKind, ['single', 'feed', 'archive', 'page', 'taxonomy', 'blogroll', 'not_found'], true)
        ? $requestedKind : 'single';
    $response = [
        'kind' => $kind,
        'status' => $kind === 'not_found' ? 404 : 200,
        'page_title' => 'A secure presentation boundary',
        'navigation' => $nav,
        'post' => ['title' => 'A secure presentation boundary', 'created_at' => '2026-09-29', 'featured_image_path' => ''],
        'posts' => array_map(static fn(array $item): array => [
            'title' => $item['img_title'], 'url' => $item['url'], 'image_url' => $item['img_thumb_aspect'],
            'created_label' => 'OCT 5, 2026', 'width' => 3, 'height' => 2,
        ], $fixtureItems),
        'tiles' => array_map(static fn(array $item): array => [
            'title' => $item['img_title'], 'thumb' => $item['img_thumb_aspect'],
            'full' => $item['img_file'], 'width' => 1600, 'height' => 1000,
        ], $fixtureItems),
        'item' => ['title' => 'About the fixture'],
        'rendered_content' => snapsmack_trusted_html('<p>This representative long-form story is rendered by the CMS and arranged by the selected skin.</p><p>No skin code selected this content or touched persistent state.</p>'),
        'colophon' => 'Local fixture — no database or network access.',
        'photo_count' => count($fixtureItems),
        'word_count' => 18,
        'groups' => [['label' => 'Fixture collection', 'description' => 'A bounded taxonomy fixture.', 'url' => '/collection/fixture', 'cover_url' => $fixtureImage, 'photograph_count' => count($fixtureItems)]],
        'blogroll_groups' => [['label' => 'FRIENDS', 'items' => [['name' => 'Example Photographer', 'url' => 'https://example.invalid/', 'description' => 'A bounded blogroll fixture.']]]],
        'comments_enabled' => true,
        'comments' => [['comment_author' => 'Reader', 'comment_text' => 'The presentation remains distinct.']],
    ];
} else {
    $kind = in_array($requestedKind, ['landing', 'archive', 'hashtag', 'photo', 'post', 'page', 'blogroll', 'search', 'albums', 'collections', 'collection', 'not_found'], true)
        ? $requestedKind : 'photo';
    $response = [
        'kind' => $kind,
        'status' => $kind === 'not_found' ? 404 : 200,
        'fragment' => $kind === 'photo' && isset($_GET['modal']),
        'page_title' => 'A secure presentation boundary',
        'navigation' => $nav,
        'items' => $fixtureItems,
        'posts' => $fixtureItems,
        'photographs' => $fixtureItems,
        'photo_count' => count($fixtureItems),
        'autoopen' => $kind === 'photo',
        'item' => [
            'id' => 1,
            'title' => 'A secure presentation boundary',
            'img_title' => 'A secure presentation boundary',
            'img_slug' => 'fixture-1',
            'img_date' => '2026-09-29',
            'img_alt' => 'Neutral visual fixture placeholder',
            'img_file' => $fixtureImage,
            'img_thumb_aspect' => $fixtureImage,
            'width' => 1600,
            'height' => 1000,
            'content' => snapsmack_trusted_html('<p>This representative post is rendered by the CMS and arranged by the selected skin.</p>'),
            'img_description' => snapsmack_trusted_html('<p>This representative photograph caption is rendered by the CMS and arranged by the selected skin.</p>'),
        ],
        'query' => 'fixture',
        'results' => ['photographs' => $fixtureItems, 'posts' => []],
        'groups' => [['label' => 'Fixture collection', 'description' => 'A bounded taxonomy fixture.', 'url' => '/collection/fixture', 'cover_url' => $fixtureImage, 'photograph_count' => count($fixtureItems)]],
        'blogroll_groups' => [['label' => 'FRIENDS', 'items' => [['name' => 'Example Photographer', 'url' => 'https://example.invalid/', 'description' => 'A bounded blogroll fixture.']]]],
        'page' => 1,
        'total_pages' => 1,
        'comments' => [['comment_author' => 'Reader', 'comment_text' => 'The presentation remains distinct.']],
    ];
}

$view = snapsmack_build_skin_view($response, $site);
$template = (string)($manifest['templates'][$response['kind']] ?? $manifest['templates']['default'] ?? '');
if ($template === '' || !snapsmack_render_strict_skin_template($skinDir, $template, $view)) {
    http_response_code(500);
    echo 'Render failed';
}

// ===== SNAPSMACK EOF =====
