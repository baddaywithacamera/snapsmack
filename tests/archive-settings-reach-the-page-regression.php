<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A setting the owner picks must change the page. Flip it and look.
 *
 * Every check in this suite asked whether the markup was well formed or
 * whether a rule existed. None asked the question the owner actually asks:
 * I chose CROPPED (NATURAL ASPECT) -- did anything change?
 *
 * For CHAPLIN the answer was no, twice over. First because
 * core/chaplin-page-component.php never read archive_thumb_style at all and
 * asked for img_thumb_square unconditionally. Then, after that was fixed,
 * because the fix tested the NORMALISED archive_layout, which folds any
 * unrecognised value to 'square' -- and DEFAULT LAYOUT writes 'thumbs'. So
 * 0.7.956D shipped emitting archive-layout-square on a site set to cropped,
 * and the control was still dead. Measured live on acolourlesslife.ca.
 *
 * Two settings, independent:
 *   archive_layout       thumbs | masonry   (DEFAULT LAYOUT)
 *   archive_thumb_style  square | cropped   (THUMB STYLE)
 * with 'square' and 'cropped' also accepted in archive_layout for older sites.
 *
 * This renders each component twice and requires the output to differ in the
 * way the setting promises. A component that ignores the setting fails here
 * instead of on the owner's screen.
 */

$root = dirname(__DIR__);
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';

$fail = 0;
function st_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

/** Render one skin's archive with the given site settings. */
function st_render(string $root, string $skin, array $siteOverrides): string {
    $item = [
        'id' => 1, 'img_slug' => 'a-photo', 'img_title' => 'A Photo', 'img_alt' => 'A Photo',
        'img_file' => '/media/photo.jpg', 'img_thumb_aspect' => '/media/aspect.jpg',
        'img_thumb_square' => '/media/square.jpg', 'img_width' => 1200, 'img_height' => 800,
        'url' => '/a-photo',
    ];
    $response = ['status' => 200, 'kind' => 'archive', 'items' => [$item, $item], 'navigation' => []];
    $site = array_merge([
        'site_name' => 'Example', 'language' => 'en', 'direction' => 'ltr',
        'skin_style_url' => "/skins/{$skin}/style.css",
        'skin_presentation' => snapsmack_skin_presentation([], $skin),
    ], $siteOverrides);
    $view = snapsmack_build_skin_view($response, $site);
    ob_start();
    snapsmack_render_strict_skin_template($root . '/skins/' . $skin, 'layout.php', $view);
    return (string)ob_get_clean();
}

// Components that declare they honour THUMB STYLE. CHAPLIN is here because it
// is what broke; IMPACT PRINTER because it is where the working code was
// copied from, and a silent regression there would take CHAPLIN with it.
foreach (['chaplin', 'impact-printer'] as $skin) {
    // The owner's real combination: DEFAULT LAYOUT thumbs, THUMB STYLE cropped.
    $cropped = st_render($root, $skin, ['archive_layout' => 'thumbs', 'archive_thumb_style' => 'cropped']);
    $square  = st_render($root, $skin, ['archive_layout' => 'thumbs', 'archive_thumb_style' => 'square']);

    st_test(str_contains($cropped, 'cropped-grid') || str_contains($cropped, 'archive-layout-cropped'),
        "{$skin}: THUMB STYLE cropped reaches the markup when DEFAULT LAYOUT is thumbs");
    st_test(str_contains($square, 'square-grid') || str_contains($square, 'archive-layout-square'),
        "{$skin}: THUMB STYLE square reaches the markup when DEFAULT LAYOUT is thumbs");
    st_test($cropped !== $square,
        "{$skin}: flipping THUMB STYLE changes the page at all");

    // And the thumbnail that is actually requested follows the choice --
    // the class alone is not the setting doing its job.
    st_test(str_contains($square, '/media/square.jpg'),
        "{$skin}: square asks for the square thumbnail");
    st_test(str_contains($cropped, '/media/aspect.jpg'),
        "{$skin}: cropped asks for the natural-aspect thumbnail");

    // COLUMNS is a number the owner moves on a slider. It has to appear.
    $four = st_render($root, $skin, ['archive_layout' => 'thumbs', 'archive_grid' => ['cols' => 4, 'step' => 'm']]);
    $two  = st_render($root, $skin, ['archive_layout' => 'thumbs', 'archive_grid' => ['cols' => 2, 'step' => 'm']]);
    st_test(str_contains($four, 'archive-cols-4') && str_contains($two, 'archive-cols-2'),
        "{$skin}: COLUMNS reaches the markup and follows the setting");
}

if ($fail > 0) {
    fwrite(STDERR, "Archive settings do not reach the page. {$fail} check(s) failed.\n");
    exit(1);
}

echo "Archive settings reach the page: PASS.\n";

// ===== SNAPSMACK EOF =====
