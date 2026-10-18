<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * An album archive must say which album it is.
 *
 * Sean, 17 October 2026, looking at ?album=258&view=archive: the page went
 * from the menu straight into the grid with nothing naming the album. Title
 * large on the left, photographs and views small on the right.
 *
 * The header is drawn only when an album is in play -- a plain archive, a
 * hashtag page or a category view must not grow an empty bar.
 */

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');

$presentation = [
    'site_name' => 'foreverphotograph.ing', 'language' => 'en', 'direction' => 'ltr',
    'base_url' => '/', 'skin_style_url' => '/skins/slickr/style.css',
    'skin_presentation' => [], 'footer' => [], 'registered_assets' => [],
];

$render = static function (array $response) use ($presentation): string {
    $view = snapsmack_build_skin_view($response, $presentation);
    ob_start();
    include dirname(__DIR__) . '/skins/slickr/layout.php';
    return (string)ob_get_clean();
};

$base = ['status' => 200, 'kind' => 'archive', 'navigation' => [], 'items' => []];

// ── With an album: the header, the title, and both counts.
$withAlbum = $render($base + ['album' => [
    'name' => 'Rodeo 2026',
    'photo_count' => 248, 'view_count' => 1946316,
    'photo_count_display' => '248 photos', 'view_count_display' => '1,946,316 views',
]]);

foreach (['sl-album-head', 'sl-album-title', 'sl-album-meta',
          'Rodeo 2026', '248 photos', '1,946,316 views'] as $needle) {
    if (!str_contains($withAlbum, $needle)) {
        throw new RuntimeException('SLICKR album header is missing: ' . $needle);
    }
}

// The title must come before the counts: left, then right.
if (strpos($withAlbum, 'sl-album-title') > strpos($withAlbum, 'sl-album-meta')) {
    throw new RuntimeException('SLICKR album header puts the counts before the title.');
}

// ── Without one: no empty bar on an ordinary archive.
$noAlbum = $render($base);
if (str_contains($noAlbum, 'sl-album-head')) {
    throw new RuntimeException('SLICKR draws an album header on an archive with no album.');
}

// ── A row that came back without a name is not an album worth announcing.
$blank = $render($base + ['album' => ['name' => '', 'photo_count_display' => '0 photos']]);
if (str_contains($blank, 'sl-album-head')) {
    throw new RuntimeException('SLICKR draws an album header for a nameless album.');
}

echo "PASS: SLICKR names the album, with its counts, and only when there is one.\n";
// ===== SNAPSMACK EOF =====
