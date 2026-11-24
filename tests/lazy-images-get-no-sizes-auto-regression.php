<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// sizes="auto" MAKES THE BROWSER IGNORE THE PHOTOGRAPH.
//
// Chromium applies SIZE CONTAINMENT -- contain:size, with a default
// contain-intrinsic-size of 300px by 150px -- to any <img loading="lazy">
// that carries sizes="auto". contain:size sizes an element AS IF IT HAD NO
// CONTENTS, so the decoded image is ignored and the 300x150 placeholder
// becomes the box. Every grid that takes its row height from the image locks
// at 2:1 and never recovers.
//
// MEASURED ON THE LIVE foundtextures.ca ARCHIVE at 1920, TRUE GRIT, on
// 24 November 2026: all 24 tiles 300x150 while the images were 300x225 and
// 300x200, all of them loaded. contain:size and contain-intrinsic-size were
// on every img and NO CSS RULE SET THEM. Sean had already updated to
// 0.7.1002D and TRUE GRIT 1.3.30 and the archive was still flat.
//
// NOTHING OUTSIDE THIS FILE CAN FIX IT. Each of these was driven on that live
// page and changed nothing at all: width/height attributes on the img;
// img{height:auto}; height:auto !important on the image, every wrapper and
// the grid item; contain:none !important; and a CSS aspect-ratio per image
// built from the dimensions core already emits as data-w/data-h. A stylesheet
// cannot override the browser's own containment. Removing sizes="auto" fixed
// it on the spot: 300x150 became 371x278 and 371x248, the real 4:3 and 3:2.
//
// So this is a CORE guard, and it is here because the value is attractive:
// 'auto' genuinely did cut the fixture's transfer by about 12%, it is written
// up in core/image-srcset.php as measured work, and the next person to read
// that note will want it back. They may well find a way -- a per-skin tile
// hint, or grids that fix their own tile ratio so the image never sizes the
// row. What they must not do is restore 'auto' on a lazily-loaded grid image
// and expect CSS to hold the line, because it cannot.

$root = dirname(__DIR__);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/image-srcset.php';
require_once $root . '/core/skin-render-helpers.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

echo "A lazily-loaded grid image never carries sizes=auto\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "THE VALUE ITSELF\n";
// ─────────────────────────────────────────────────────────────────────────────

$check('the grid sizes constant is defined', defined('SNAPSMACK_GRID_IMAGE_SIZES'));
$sizes = defined('SNAPSMACK_GRID_IMAGE_SIZES') ? SNAPSMACK_GRID_IMAGE_SIZES : '';
$check('and it does NOT use the auto keyword',
       !preg_match('/(^|[\s,])auto([\s,]|$)/i', (string)$sizes));
$check('  it is still a usable sizes value, not empty', trim((string)$sizes) !== '');

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAND THE TAG THE COMPONENT ACTUALLY RENDERS\n";
// ─────────────────────────────────────────────────────────────────────────────
// Driven, not grepped. The constant could be right and the component could
// still hardcode something else, which is the half that reaches the browser.

snap_image_srcset_prepare([
    'https://example.test/a_photo.jpg' =>
        'https://example.test/m_photo.jpg 600w, https://example.test/a_photo.jpg 1920w',
]);
$html = (string) snap_render_component('image', [
    'url' => 'https://example.test/a_photo.jpg',
    'alt' => 'A photograph',
    'attributes' => ['data-w' => 900, 'data-h' => 675],
]);

$check('the component renders an img', str_contains($html, '<img'));
$check('  it is lazily loaded, which is what makes auto dangerous',
       str_contains($html, 'loading="lazy"'));
$check('  it still offers a srcset, so the saving can be won back later',
       str_contains($html, 'srcset='));
$check('  it emits a sizes attribute', str_contains($html, 'sizes='));

if (preg_match('/sizes="([^"]*)"/', $html, $m)) {
    $check('  AND THAT SIZES VALUE CONTAINS NO auto',
           !preg_match('/(^|[\s,])auto([\s,]|$)/i', $m[1]));
    printf("        rendered sizes: %s\n", $m[1]);
} else {
    $check('  the sizes attribute is readable', false);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PHOTOGRAPH WITH NO DERIVATIVE IS UNCHANGED\n";
// ─────────────────────────────────────────────────────────────────────────────
// No m_ means no srcset, which means no sizes at all. That was true before
// and must stay true, or every un-derived photograph gains an attribute for
// no reason.

snap_image_srcset_prepare([]);
$bare = (string) snap_render_component('image', [
    'url' => 'https://example.test/a_lonely.jpg', 'alt' => 'No derivative',
]);
$check('no srcset when there is no m_ candidate', !str_contains($bare, 'srcset='));
$check('  and therefore no sizes attribute either', !str_contains($bare, 'sizes='));
$check('  but it is still lazily loaded', str_contains($bare, 'loading="lazy"'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nBOTH PLACES THAT DEFINE IT AGREE\n";
// ─────────────────────────────────────────────────────────────────────────────
// The constant is declared twice on purpose: core/constants.php is a
// protected path an update does not overwrite, so a value defined only there
// never reaches an updated site, and a value defined only in image-srcset.php
// never reaches a site that has not updated. Both copies have to be clean or
// half the fleet keeps the bug.

foreach (['core/constants.php', 'core/image-srcset.php'] as $rel) {
    $src = (string) file_get_contents($root . '/' . $rel);
    // Comments stripped: this file and that one both DISCUSS 'auto, 100vw' at
    // length, and a check that matched the prose would pass forever.
    $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $src);
    if (preg_match("~SNAPSMACK_GRID_IMAGE_SIZES'\s*,\s*'([^']*)'~", $code, $m)) {
        $check("$rel defines it without auto",
               !preg_match('/(^|[\s,])auto([\s,]|$)/i', $m[1]));
    } else {
        $check("$rel still defines the constant", false);
    }
}

echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — no lazily-loaded grid image can carry sizes=auto.\n";
echo "NOT PROVED HERE: the pixels, and the bytes. The geometry was measured in\n";
echo "a browser on the live archive before and after, and the transfer cost of\n";
echo "dropping 'auto' needs a fresh page load per candidate, which a PHP test\n";
echo "cannot do. Both belong to the rendered gate.\n";
exit(0);

// ===== SNAPSMACK EOF =====
