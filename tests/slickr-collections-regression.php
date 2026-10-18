<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The collections page rendered by the SKIN, not by legacy collections.php.
 *
 * foreverphotograph.ing served the legacy document: one stylesheet, no
 * doctype, and a menu built from the legacy branch that invented an
 * "ARCHIVE VIEW" link the site's real menu does not have. Every class the
 * page uses is styled in assets/css/page-collection.css, which only the
 * skin-rendered pages load, so the page arrived naked.
 *
 * This pins the two halves of the repair: the skin draws the sort toggle the
 * legacy page had, and the navigation stops pointing at collections.php.
 */

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');

$response = [
    'status' => 200,
    'kind' => 'collections',
    'navigation' => [],
    'sort' => 'newest',
    'sort_options' => ['manual' => 'Manual', 'alphabetical' => 'A to Z',
                       'newest' => 'Newest', 'oldest' => 'Oldest'],
    'items' => [[
        'id' => 1, 'title' => 'Most Viewed', 'slug' => 'most-viewed',
        'photograph_count' => 20, 'cover_path' => '/media/cover.jpg',
        'presentation_count' => '20 images', 'presentation_posted' => 'Posted 28 June 2026',
    ]],
];

$view = snapsmack_build_skin_view($response, [
    'site_name' => 'foreverphotograph.ing', 'language' => 'en', 'direction' => 'ltr',
    'base_url' => '/', 'skin_style_url' => '/skins/slickr/style.css',
    'skin_presentation' => [], 'footer' => [], 'registered_assets' => [],
]);

ob_start();
include dirname(__DIR__) . '/skins/slickr/layout.php';
$html = (string)ob_get_clean();

foreach (['collections-canvas', 'collections-series', 'collections-sort-toggle'] as $needle) {
    if (!str_contains($html, $needle)) {
        throw new RuntimeException('SLICKR collections lost: ' . $needle);
    }
}

// The toggle must offer every sort and mark the one in force.
foreach (['manual', 'alphabetical', 'newest', 'oldest'] as $key) {
    if (!str_contains($html, 'data-sort="' . $key . '"')) {
        throw new RuntimeException('SLICKR collections sort toggle is missing: ' . $key);
    }
}
if (!str_contains($html, 'alt-btn alt-btn--active" data-sort="newest"')) {
    throw new RuntimeException('SLICKR collections toggle does not mark the sort in force.');
}
if (substr_count($html, 'alt-btn--active') !== 1) {
    throw new RuntimeException('SLICKR collections toggle marks more than one sort active.');
}

// The whole point: no link back into the legacy document.
if (str_contains($html, 'collections.php')) {
    throw new RuntimeException('SLICKR collections still links to legacy collections.php.');
}

echo "PASS: SLICKR renders collections with its sort toggle and no legacy link.\n";
// ===== SNAPSMACK EOF =====
