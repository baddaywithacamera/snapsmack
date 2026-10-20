<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Render a skin's page to a standalone HTML file, with its real stylesheet,
 * so a browser can MEASURE it before anybody ships.
 *
 * Sean's rule: verify a layout in an offline harness with the real engine and
 * the real CSS, measured, rather than reasoning about it. A regression test
 * proves the markup is emitted; it cannot tell you the album title came out
 * at 12px, or that the wall still sits against the header. Only a browser
 * with the stylesheet attached can say that.
 *
 *   php tools/_build/skin-harness.php slickr album   > out/album.html
 *   php tools/_build/skin-harness.php slickr collections > out/collections.html
 *   php tools/_build/skin-harness.php rational-geo archive > out/rg.html
 *
 * The output references the stylesheet by absolute file path, so open it from
 * disk and the CSS loads with it. Nothing here touches a database or a site.
 */

$root = dirname(__DIR__, 1);
$root = dirname($root);           // repo root

$skin = $argv[1] ?? '';
$page = $argv[2] ?? '';
if ($skin === '' || $page === '') {
    fwrite(STDERR, "usage: skin-harness.php <skin> <album|collections|archive>\n");
    exit(2);
}
$layout = $root . '/skins/' . $skin . '/layout.php';
if (!is_file($layout)) {
    fwrite(STDERR, "No such skin layout: $layout\n");
    exit(2);
}

require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');

/** A handful of photographs, enough for a wall to have a shape. */
$items = [];
for ($i = 1; $i <= 12; $i++) {
    $items[] = [
        'id' => $i,
        'img_title' => 'Photograph ' . $i,
        'img_slug' => 'photograph-' . $i,
        'img_alt' => 'A photograph',
        'img_orientation' => $i % 3 === 0 ? 1 : 0,
        'img_date' => '2026-10-18',
        'img_file' => 'https://placehold.co/1200x800/333/fff?text=' . $i,
        'img_thumb_aspect' => 'https://placehold.co/600x400/333/fff?text=' . $i,
        'img_thumb_square' => 'https://placehold.co/400x400/333/fff?text=' . $i,
    ];
}

$responses = [
    'album' => [
        'status' => 200, 'kind' => 'archive', 'navigation' => [], 'items' => $items,
        'next_page' => 2,
        'album' => [
            'name' => 'Rodeo 2026', 'photo_count' => 248, 'view_count' => 1946316,
            'photo_count_display' => '248 photos',
            'view_count_display' => '1,946,316 views',
        ],
    ],
    'archive' => [
        'status' => 200, 'kind' => 'archive', 'navigation' => [], 'items' => $items,
        'next_page' => 2,
    ],
    'photo' => [
        'status' => 200, 'kind' => 'photo', 'comments' => [],
        // A real masthead, so the harness can show how the nav reads.
        'navigation' => [
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Archive View', 'url' => '/archive'],
            ['label' => 'Blogroll', 'url' => '/blogroll'],
            ['label' => 'The Photographer', 'url' => '/photographer'],
        ],
        'comments_enabled' => true,
        'item' => ['id' => 2, 'img_title' => 'Middle photograph', 'img_slug' => 'middle',
                   'img_file' => 'https://placehold.co/1600x1067/444/fff?text=MIDDLE',
                   'img_thumb_aspect' => 'https://placehold.co/800x533/444/fff?text=MIDDLE',
                   'img_alt' => 'The photograph on screen', 'img_date' => '2026-10-19',
                   'url' => 'middle', 'exif' => []],
        'previous' => ['url' => 'older', 'img_title' => 'Older', 'img_slug' => 'older'],
        'next'     => ['url' => 'newer', 'img_title' => 'Newer', 'img_slug' => 'newer'],
        'first'    => ['url' => 'first', 'img_title' => 'First', 'img_slug' => 'first'],
        'last'     => ['url' => 'last',  'img_title' => 'Last',  'img_slug' => 'last'],
        'filmstrip' => (getenv('HARNESS_NO_FILMSTRIP') ? [] : array_map(static fn(int $n): array => [
            'id' => $n, 'img_slug' => 'photo-' . $n, 'img_title' => 'Photograph ' . $n,
            'img_thumb_square' => 'https://placehold.co/200x200/555/fff?text=' . $n,
            'img_file' => 'https://placehold.co/200x200/555/fff?text=' . $n,
        ], range(1, 12))),
    ],
    'collections' => [
        'status' => 200, 'kind' => 'collections', 'navigation' => [],
        'sort' => 'newest',
        'sort_options' => ['manual' => 'Manual', 'alphabetical' => 'A to Z',
                           'newest' => 'Newest', 'oldest' => 'Oldest'],
        'items' => [
            ['id' => 1, 'title' => 'Most Viewed', 'slug' => 'most-viewed',
             'photograph_count' => 20, 'cover_path' => 'https://placehold.co/600x400',
             'presentation_count' => '20 images', 'presentation_posted' => 'Posted 28 June 2026'],
            ['id' => 2, 'title' => 'Most Liked', 'slug' => 'most-liked',
             'photograph_count' => 20, 'cover_path' => 'https://placehold.co/600x400',
             'presentation_count' => '20 images', 'presentation_posted' => 'Posted 28 June 2026'],
        ],
    ],
];
if (!isset($responses[$page])) {
    fwrite(STDERR, "Unknown page: $page\n");
    exit(2);
}

// Relative, not absolute: snapsmack_asset_url() rejects anything with a scheme
// or a leading slash, and an empty href is how that refusal shows up. Copy
// style.css and base.css next to the output and serve the folder.
$styleUrl = 'style.css';
$baseUrl  = 'base.css';

$view = snapsmack_build_skin_view($responses[$page], [
    'site_name' => 'harness.test', 'language' => 'en', 'direction' => 'ltr',
    'base_url' => '/', 'skin_style_url' => $styleUrl, 'base_style_url' => $baseUrl,
    'skin_presentation' => [], 'footer' => [], 'registered_assets' => [],
    'archive_layout' => 'masonry', 'archive_thumb_style' => 'cropped',
]);

ob_start();
include $layout;
echo (string)ob_get_clean();
// ===== SNAPSMACK EOF =====
