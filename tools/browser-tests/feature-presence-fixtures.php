<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Render a real solo entry for every strict skin.
 *
 * Geometry fixtures deliberately carry no interactive payload. These pages
 * do: previous/next targets, a CMS-trusted community block, and the exact
 * public assets declared by each manifest. The browser matrix can therefore
 * measure the feature that reaches a visitor instead of searching source text.
 *
 * Usage: php tools/browser-tests/feature-presence-fixtures.php <out-dir>
 */

$root = dirname(__DIR__, 2);
$out = $argv[1] ?? ($root . '/.feature-presence-fixtures');
$onlySkin = trim((string)($argv[2] ?? ''));

if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';
require_once $root . '/core/skin-manifest.php';
require_once $root . '/core/asset-registry.php';
require_once $root . '/core/public-runtime.php';
require_once $root . '/core/stats-logger.php';
require_once $root . '/tools/_build/fixture-head.php';

if (!is_dir($out) && !@mkdir($out, 0777, true) && !is_dir($out)) {
    fwrite(STDERR, "Cannot create {$out}\n");
    exit(1);
}

function fp_photo(string $out, int $width, int $height, string $name): string
{
    $file = $name . '-' . $width . 'x' . $height . '.svg';
    if (!is_file($out . '/' . $file)) {
        file_put_contents($out . '/' . $file,
            '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '">'
            . '<rect width="100%" height="100%" fill="#68727e"/>'
            . '<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#fff" font-size="42">'
            . htmlspecialchars($name, ENT_QUOTES) . '</text></svg>');
    }
    return $file;
}

function fp_asset_path(string $root, string $url): string
{
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: $url);
    $path = ltrim((string)preg_replace('#^.*?(assets/|skins/)#', '$1', $path), '/');
    return is_file($root . '/' . $path) ? $path : '';
}

function fp_inline_assets(string $html, string $root, array $assets, string $skin): string
{
    $styles = ['assets/css/public-base.css', 'skins/' . $skin . '/style.css'];
    foreach (($assets['styles'] ?? []) as $url) {
        $path = fp_asset_path($root, (string)$url);
        if ($path !== '') $styles[] = $path;
    }
    $css = '';
    foreach (array_values(array_unique($styles)) as $path) {
        if (is_file($root . '/' . $path)) {
            $css .= "\n/* ===== {$path} ===== */\n" . (string)file_get_contents($root . '/' . $path);
        }
    }
    $html = snapsmack_fixture_inline_styles($html, $css);
    $html = preg_replace_callback('#<script\s+src="([^"]+)"\s+defer></script>#i',
        static function (array $match) use ($root): string {
            $path = fp_asset_path($root, html_entity_decode($match[1], ENT_QUOTES));
            if ($path === '') return '';
            return '<script data-fixture-asset="' . htmlspecialchars($path, ENT_QUOTES) . '">'
                . (string)file_get_contents($root . '/' . $path) . '</script>';
        }, $html) ?? $html;
    return $html;
}

$hero = fp_photo($out, 1200, 800, 'current');
$previous = fp_photo($out, 1200, 800, 'previous');
$next = fp_photo($out, 1200, 800, 'next');
$community = SnapTrustedHtml::__snapsmackCmsOnly(
    '<section class="ss-community" data-image-id="42" aria-label="Community">'
    . '<div class="ss-community-bar"><button type="button" class="ss-like-btn" aria-label="Like this photograph">Like</button></div>'
    . '<ol class="ss-comment-list"><li class="ss-comment">Fixture comment</li></ol>'
    . '<form class="ss-comment-form"><label>Comment<textarea name="comment"></textarea></label><button type="submit">Post comment</button></form>'
    . '</section>'
);
$nav = [
    ['label' => 'Home', 'url' => '/'],
    ['label' => 'Archive', 'url' => '/archive'],
    ['label' => 'Blogroll', 'url' => '/blogroll'],
    ['label' => 'About', 'url' => '/about'],
];
$item = [
    'id' => 42, 'img_slug' => 'current', 'url' => '/current',
    'img_title' => 'Current photograph', 'title' => 'Current photograph',
    'img_alt' => 'Current photograph', 'img_file' => $hero,
    'featured_image_path' => $hero, 'presentation_image' => $hero,
    'img_width' => 1200, 'img_height' => 800, 'presentation_aspect' => '1.5',
    'content' => snapsmack_trusted_html('<p>A complete solo-entry fixture.</p>'),
    'description' => snapsmack_trusted_html('<p>A complete solo-entry fixture.</p>'),
    'date_display' => '10 November 2026', 'img_date' => '2026-11-10',
    'download_link' => ['url' => '/download/current', 'label' => 'Download'],
];
$post = [
    'id' => 42, 'slug' => 'current', 'url' => '/current',
    'title' => 'Current story', 'created_at' => '2026-11-10',
    'featured_image_path' => $hero,
];
$destinations = [
    'previous' => ['id' => 41, 'img_slug' => 'previous', 'url' => '/previous', 'img_file' => $previous],
    'next' => ['id' => 43, 'img_slug' => 'next', 'url' => '/next', 'img_file' => $next],
    'first' => ['id' => 1, 'img_slug' => 'first', 'url' => '/first'],
    'last' => ['id' => 99, 'img_slug' => 'last', 'url' => '/last'],
];
$archiveItems = [];
foreach (range(1, 12) as $number) {
    $archiveFile = [$hero, $previous, $next][$number % 3];
    $archiveItems[] = [
        'id' => $number, 'img_slug' => 'archive-' . $number,
        'url' => '/archive-' . $number, 'img_title' => 'Archive photograph ' . $number,
        'title' => 'Archive photograph ' . $number, 'presentation_title' => 'Archive photograph ' . $number,
        'img_alt' => 'Archive photograph ' . $number, 'img_file' => $archiveFile,
        'img_thumb_aspect' => $archiveFile, 'img_thumb_square' => $archiveFile,
        'featured_image_path' => $archiveFile, 'presentation_image' => $archiveFile,
        'img_width' => 1200, 'img_height' => 800,
    ];
}

$index = [];
foreach (glob($root . '/skins/*/layout.php') ?: [] as $layout) {
    $skin = basename(dirname($layout));
    if ($onlySkin !== '' && $skin !== $onlySkin) continue;
    $manifestFile = $root . '/skins/' . $skin . '/manifest.json';
    if (!is_file($manifestFile)) continue;
    $manifest = json_decode((string)file_get_contents($manifestFile), true) ?: [];
    $modes = array_map('strtolower', array_values(array_filter((array)($manifest['modes'] ?? []), 'is_string')));
    $kind = in_array('smacktalk', $modes, true) ? 'single' : 'photo';
    $siteMode = in_array('carousel', $modes, true) ? 'carousel' : ($kind === 'single' ? 'smacktalk' : 'photoblog');
    $assets = snapsmack_merge_registered_assets(
        snapsmack_skin_declared_assets($manifest),
        snapsmack_public_engine_assets()
    );
    $GLOBALS['snapsmack_consent'] = ['show' => true, 'privacy_url' => '/privacy'];
    $GLOBALS['settings'] = ['site_mode' => $siteMode];
    $GLOBALS['snapsmack_hit'] = ['id' => 4201, 'page_type' => $kind === 'single' ? 'page' : 'image'];
    $response = array_merge([
        'kind' => $kind, 'page_title' => 'Current entry', 'item' => $item, 'post' => $post,
        'rendered_content' => snapsmack_trusted_html('<p>A complete solo-entry fixture.</p>'),
        'comments' => [['author' => 'Fixture visitor', 'comment' => 'Fixture comment']],
        'comments_enabled' => true, 'community' => $community, 'navigation' => $nav,
        'photographs' => [$item],
    ], $destinations);
    $view = snapsmack_build_skin_view($response, [
        'site_name' => 'Feature Matrix', 'tagline' => 'Rendered capability proof',
        'site_url' => 'https://example.test/', 'language' => 'en', 'direction' => 'ltr',
        'owner_name' => 'Fixture Owner', 'skin_slug' => $skin,
        'skin_style_url' => '', 'base_style_url' => '', 'skin_variant_url' => '',
        'registered_assets' => $assets, 'owner_custom_code' => '',
        'search_dock' => ['enabled' => true, 'action' => '/search', 'placeholder' => 'Search'],
        'skin_presentation' => snapsmack_skin_presentation([], $skin),
        'footer' => ['site_name' => 'Feature Matrix', 'site_url' => 'https://example.test/', 'slots' => []],
        'crop_circles' => ['navigation' => $nav, 'identity' => 'Feature Matrix', 'home_url' => '/'],
    ]);
    ob_start();
    @snapsmack_render_strict_skin_template($root . '/skins/' . $skin, 'layout.php', $view);
    $html = (string)ob_get_clean();
    if (trim($html) === '') continue;
    $html = str_replace(['src="/current-', 'src="/previous-', 'src="/next-'], ['src="current-', 'src="previous-', 'src="next-'], $html);
    $html = str_replace('</head>', '<script>window.SNAP_SITE_MODE=' . json_encode($siteMode) . ';</script></head>', $html);
    $html = fp_inline_assets($html, $root, $assets, $skin);
    $target = $out . '/' . $skin . '--solo.html';
    file_put_contents($target, $html);

    $skinArchiveItems = $archiveItems;
    $carouselItems = $siteMode === 'carousel'
        ? array_map(
            static function (array $archiveItem) use ($skin): array {
                $archiveItem['url'] = '/modal/' . rawurlencode($skin) . '/' . rawurlencode((string)$archiveItem['img_slug']);
                return $archiveItem;
            },
            $archiveItems
        )
        : $archiveItems;
    $archiveControls = snapsmack_archive_controls(
        ['archive_layout' => 'thumbs', 'archive_calendar_enabled' => '1'],
        $manifest
    );
    $GLOBALS['snapsmack_hit'] = ['id' => 4202, 'page_type' => 'archive'];
    $archiveResponse = [
        'kind' => 'archive', 'page_title' => 'Archive', 'items' => $skinArchiveItems,
        'item' => [], 'next_page' => 2, 'next_url' => '/archive?p=2', 'has_more' => true,
        'total_count' => 60, 'total_pages' => 5, 'navigation' => $nav,
        'archive_layouts' => snapsmack_archive_layout_links(
            $archiveControls['layouts'], 'https://example.test/', ['archive' => 'archive'], $siteMode),
        'archive_calendar' => $archiveControls['calendar'],
        'archive_layout_active' => $archiveControls['active'],
    ];
    $archiveAssets = snapsmack_merge_registered_assets($assets, [
        'scripts' => ['smack-archive-toggle', 'smack-archive-cascade'],
        'styles' => [],
    ]);
    $archiveView = snapsmack_build_skin_view($archiveResponse, [
        'site_name' => 'Feature Matrix', 'tagline' => 'Rendered capability proof',
        'site_url' => 'https://example.test/', 'language' => 'en', 'direction' => 'ltr',
        'owner_name' => 'Fixture Owner', 'skin_slug' => $skin,
        'skin_style_url' => '', 'base_style_url' => '', 'skin_variant_url' => '',
        'registered_assets' => $archiveAssets, 'owner_custom_code' => '',
        'skin_canvas_width_option' => (string)($manifest['features']['canvas_width_option'] ?? ''),
        'skin_presentation' => snapsmack_skin_presentation([], $skin),
        'footer' => ['site_name' => 'Feature Matrix', 'site_url' => 'https://example.test/', 'slots' => []],
        'crop_circles' => [
            'filters' => [[
                'label' => 'Albums', 'type' => 'album',
                'items' => [['id' => 'fixture-album', 'label' => 'Fixture album']],
            ]],
            'search' => ['placeholder' => 'SEARCH OR #TAG', 'name' => 'q'],
        ],
    ]);
    ob_start();
    @snapsmack_render_strict_skin_template($root . '/skins/' . $skin, 'layout.php', $archiveView);
    $archiveHtml = (string)ob_get_clean();
    $archiveHtml = str_replace(
        ['src="/current-', 'src="/previous-', 'src="/next-'],
        ['src="current-', 'src="previous-', 'src="next-'],
        $archiveHtml
    );
    $archiveHtml = str_replace(
        '</head>', '<script>window.SNAP_SITE_MODE=' . json_encode($siteMode) . ';</script></head>', $archiveHtml);
    $archiveHtml = fp_inline_assets($archiveHtml, $root, $archiveAssets, $skin);
    $archiveTarget = $out . '/' . $skin . '--archive.html';
    file_put_contents($archiveTarget, $archiveHtml);

    $landingFile = '';
    $modalFile = '';
    if ($siteMode === 'carousel') {
        $GLOBALS['snapsmack_hit'] = ['id' => 4203, 'page_type' => 'landing'];
        $landingResponse = [
            'kind' => 'landing', 'page_title' => 'Landing', 'items' => $carouselItems,
            'photographs' => $carouselItems, 'navigation' => $nav,
        ];
        $landingView = snapsmack_build_skin_view($landingResponse, [
            'site_name' => 'Feature Matrix', 'tagline' => 'Rendered capability proof',
            'site_url' => 'https://example.test/', 'language' => 'en', 'direction' => 'ltr',
            'owner_name' => 'Fixture Owner', 'skin_slug' => $skin,
            'skin_style_url' => '', 'base_style_url' => '', 'skin_variant_url' => '',
            'registered_assets' => $assets, 'owner_custom_code' => '',
            'search_dock' => ['enabled' => true, 'action' => '/search', 'placeholder' => 'Search'],
            'skin_presentation' => snapsmack_skin_presentation([], $skin),
            'footer' => ['site_name' => 'Feature Matrix', 'site_url' => 'https://example.test/', 'slots' => []],
            'crop_circles' => ['navigation' => $nav, 'identity' => 'Feature Matrix', 'home_url' => '/'],
        ]);
        ob_start();
        @snapsmack_render_strict_skin_template($root . '/skins/' . $skin, 'layout.php', $landingView);
        $landingHtml = (string)ob_get_clean();
        $landingHtml = str_replace(
            ['src="/current-', 'src="/previous-', 'src="/next-'],
            ['src="current-', 'src="previous-', 'src="next-'],
            $landingHtml
        );
        $landingHtml = str_replace(
            '</head>', '<script>window.SNAP_SITE_MODE=' . json_encode($siteMode) . ';</script></head>', $landingHtml);
        $landingHtml = fp_inline_assets($landingHtml, $root, $assets, $skin);
        $landingTarget = $out . '/' . $skin . '--landing.html';
        file_put_contents($landingTarget, $landingHtml);
        $landingFile = basename($landingTarget);

        $modalResponse = array_merge($response, [
            'kind' => 'photo', 'fragment' => true,
            'item' => $item, 'photographs' => [$item, array_merge($item, [
                'id' => 43, 'img_file' => $next, 'img_title' => 'Next photograph',
                'img_alt' => 'Next photograph',
            ])],
        ]);
        $modalView = snapsmack_build_skin_view($modalResponse, [
            'site_name' => 'Feature Matrix', 'tagline' => 'Rendered capability proof',
            'site_url' => 'https://example.test/', 'language' => 'en', 'direction' => 'ltr',
            'owner_name' => 'Fixture Owner', 'skin_slug' => $skin,
            'skin_style_url' => '', 'base_style_url' => '', 'skin_variant_url' => '',
            'registered_assets' => $assets, 'owner_custom_code' => '',
            'search_dock' => ['enabled' => true, 'action' => '/search', 'placeholder' => 'Search'],
            'skin_presentation' => snapsmack_skin_presentation([], $skin),
            'footer' => ['site_name' => 'Feature Matrix', 'site_url' => 'https://example.test/', 'slots' => []],
            'crop_circles' => ['navigation' => $nav, 'identity' => 'Feature Matrix', 'home_url' => '/'],
        ]);
        ob_start();
        @snapsmack_render_strict_skin_template($root . '/skins/' . $skin, 'layout.php', $modalView);
        $modalHtml = (string)ob_get_clean();
        $modalHtml = str_replace(
            ['src="/current-', 'src="/previous-', 'src="/next-'],
            ['src="current-', 'src="previous-', 'src="next-'],
            $modalHtml
        );
        $modalTarget = $out . '/' . $skin . '--modal.html';
        file_put_contents($modalTarget, $modalHtml);
        $modalFile = basename($modalTarget);
    }

    $index[] = [
        'skin' => $skin, 'file' => basename($target), 'kind' => $kind,
        'archive_file' => basename($archiveTarget),
        'landing_file' => $landingFile,
        'modal_file' => $modalFile,
        'site_mode' => $siteMode,
        'version' => (string)($manifest['version'] ?? ''), 'modes' => $modes,
        'features' => is_array($manifest['features'] ?? null) ? $manifest['features'] : [],
        'capabilities' => array_values(array_filter((array)($manifest['capabilities'] ?? []), 'is_string')),
        'require_scripts' => array_values(array_filter((array)($manifest['require_scripts'] ?? []), 'is_string')),
        'community_comments' => (string)($manifest['community_comments'] ?? ''),
        'community_likes' => (string)($manifest['community_likes'] ?? ''),
    ];
}

file_put_contents($out . '/index.json', json_encode(['pages' => $index], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
printf("%d solo pages written to %s\n", count($index), $out);

// ===== SNAPSMACK EOF =====
