<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A photo wall must keep loading as you scroll.
 *
 * The archive is endless scroll, not pages. Two separate pieces were missing
 * and either one alone is enough to stop it dead:
 *
 * 1. THE SENTINEL. ss-engine-rows.js watches for a .scroll-wall-sentinel
 *    carrying data-next and data-has-more. SCROLL emitted one; 50 Shades
 *    never did, so the engine's initInfiniteScroll() returned immediately
 *    and the wall showed its first batch for ever.
 *
 * 2. THE FEED. When the sentinel does fire, the engine fetches
 *    "?pg=wall&format=json&c=N" and appends data.html. That request was
 *    answered only by a skin's landing.php -- and the strict conversion
 *    deleted every landing.php in the repository. Measured against the live
 *    site: https://photowalk.ing/?pg=wall&format=json&c=2 returned a whole
 *    HTML document, 7908 bytes of text/html, which the engine cannot parse.
 *    So endless scroll was dead for every wall skin, not just this one.
 *
 * The tiles are rendered by the shared wall-tile component, the same one the
 * page uses, so a photograph scrolled into view is identical to one that
 * arrived with the page.
 */

if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.0.0D');

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$item = [
    'img_title' => 'A robin & friends', 'img_slug' => 'robin',
    'img_thumb_aspect' => 'https://example.test/t.jpg',
    'img_width' => 1234, 'img_height' => 1850,
];

// --- the tile ------------------------------------------------------------
$tile = (string)snap_render_component('wall-tile', ['item' => $item]);
$check(str_starts_with($tile, '<a class="ss-masonry-item"'), 'a tile is an ss-masonry-item anchor');
$check(str_contains($tile, 'data-w="1234"') && str_contains($tile, 'data-h="1850"'),
    'the tile must carry the photograph dimensions the engine lays out with');
$check(str_contains($tile, 'A robin &amp; friends'), 'the title must be escaped');
$check(str_contains($tile, 'loading="lazy"'), 'tiles stay lazy');

$unsafe = (string)snap_render_component('wall-tile', ['item' => [
    'img_title' => '"><script>x</script>', 'url' => 'javascript:alert(1)',
    'img_thumb_aspect' => 'javascript:alert(2)']]);
$check(!str_contains($unsafe, '<script>x'), 'a tile title must be escaped');
$check(!str_contains($unsafe, 'href="javascript:'), 'a tile link must never carry a script scheme');

// --- the page and the feed must build tiles the SAME way -----------------
$renderer = (string)file_get_contents($root . '/core/50-shades-page-component.php');
$index = (string)file_get_contents($root . '/index.php');
$check(str_contains($renderer, "snap_render_component('wall-tile'"),
    'the page renderer must build its wall from the shared tile');
$check(str_contains($index, "snap_render_component('wall-tile'"),
    'the endless-scroll feed must build its tiles from the same component');
$check(!preg_match('/<a class="ss-masonry-item"/', $renderer),
    'the renderer must not hand-roll tile markup beside the component');

// --- the sentinel, only when there is more -------------------------------
$render = static function (?int $next) use ($root): string {
    $view = [
        'site' => ['language' => 'en', 'direction' => 'ltr', 'site_name' => 'x',
                   'skin_style_url' => 'https://example.test/s.css', 'archive_layout' => 'masonry'],
        'response' => ['kind' => 'archive', 'navigation' => [], 'next_page' => $next,
                       'items' => [['img_title' => 'a', 'img_slug' => 'a',
                                    'img_file' => 'https://example.test/a.jpg',
                                    'img_width' => 3000, 'img_height' => 2000]]],
    ];
    ob_start();
    include $root . '/core/50-shades-page-component.php';
    return (string)ob_get_clean();
};
$more = $render(2);
$check(str_contains($more, 'class="scroll-wall-sentinel"'), 'more pages must emit a sentinel');
$check(str_contains($more, 'data-next="2"'), 'the sentinel must carry the next cursor');
$check(str_contains($more, 'data-has-more="1"'), 'the sentinel must say there is more');
$check(!str_contains($render(null), 'scroll-wall-sentinel'),
    'the last page must NOT emit a sentinel, or the engine chases nothing');

// --- the feed's contract -------------------------------------------------
$check(str_contains($index, "(\$_GET['pg'] ?? '') === 'wall'"),
    'index.php must answer the wall feed request');
foreach (["'html'", "'next'", "'has_more'"] as $key) {
    $check(str_contains($index, $key . ' =>'),
        'the feed must return ' . $key . '; the engine stops without it');
}
$check(str_contains($index, "header('Content-Type: application/json"),
    'the feed must answer as json, not as a page');
// The cursor is read FIRST, before page/p, because only the wall engines
// send it -- and the historical ?p=N normalisation the justified-feed
// contract requires stays intact behind it.
$check(str_contains($index, "\$_GET['c'] ?? (\$_GET['page'] ?? (\$_GET['p'] ?? 1))"),
    'the ?c=N cursor must be read as the page, without disturbing ?p=N');

// --- the gap between the control row and the photographs ----------------
$css = (string)file_get_contents($root . '/assets/css/page-archive.css');
$check(preg_match('/\.archive-filter-row ~ #justified-grid/', $css) === 1,
    'the wall must be spaced away from the control row');
$check(str_contains($css, '--archive-grid-gap'),
    'that spacing must be a control a skin can change, not a hard-coded number');

if ($failures) {
    fwrite(STDERR, "Wall endless-scroll regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Wall endless-scroll regression passed.\n";

// ===== SNAPSMACK EOF =====
