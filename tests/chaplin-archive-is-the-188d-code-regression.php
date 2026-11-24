<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// CHAPLIN'S ARCHIVE IS THE v0.7.188D CODE, MOVED, NOT REWRITTEN.
//
// SS-1569. Sean's correction to the strict-skin migration, 24 November 2026:
// the migration was always meant to MOVE a skin's code into the CMS and have
// the skin point at it. It was never meant to REPLACE that code with new
// markup. For CHAPLIN's archive it was replaced, and two things were lost.
//
// 1. THE JUSTIFIED ROW PACKER. 188D packed images into rows by aspect ratio
//    against a reference width, marked the final short row, and published
//    --last-row-ar-sum so the stylesheet could size it. The replacement
//    emitted a FLAT list of anchors with no rows at all. The component's own
//    older comment records the result: "an unstyled 27,824px wall of
//    full-width images".
//
// 2. THE SELECTORS. .chap-archive-grid, .chap-archive-item, .chap-thumb and
//    .chap-archive-title became .fsog-*, so 188D's stylesheet matched nothing.
//    That stylesheet is where the double-rule frame lives -- a 3px border plus
//    two box-shadow rings on .chap-thumb -- which is why the frame vanished.
//    Codex is restoring that CSS verbatim and asked for these four names.
//
// This file holds the MOVE. It does not prove the page looks right; that is a
// side-by-side against Sean's 188D screenshot at 1920 and 1024, and it is
// Codex's measurement, not a PHP test's.
//
// Run:  php tests/chaplin-archive-is-the-188d-code-regression.php

define('SNAPSMACK_SKIN_RENDER', true);
$root = dirname(__DIR__);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';
require_once $root . '/core/public-controller.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

/** One photograph, with the dimensions the packer reads. */
$photo = static function (int $id, int $w, int $h, string $title): array {
    return [
        'id' => $id, 'img_slug' => 'p' . $id, 'img_title' => $title,
        'img_alt' => $title, 'img_file' => '/media/p' . $id . '.jpg',
        'img_thumb_aspect' => '/media/p' . $id . '-a.jpg',
        'img_thumb_square' => '/media/p' . $id . '-s.jpg',
        'img_width' => $w, 'img_height' => $h,
    ];
};

/** Render CHAPLIN's archive and hand back just that section. */
$render = static function (array $items) use ($root): string {
    $response = ['status' => 200, 'kind' => 'archive', 'items' => $items,
                 'navigation' => [], 'archive_controls' => '',
                 'next_page' => '/archive?page=2'];
    $view = snapsmack_build_skin_view($response, [
        'site_name' => 'Example', 'tagline' => 'T', 'language' => 'en',
        'direction' => 'ltr', 'skin_slug' => 'chaplin',
        'skin_style_url' => '/skins/chaplin/style.css',
        'skin_presentation' => snapsmack_skin_presentation([], 'chaplin'),
    ]);
    ob_start();
    $ok = snapsmack_render_strict_skin_template($root . '/skins/chaplin', 'layout.php', $view);
    $html = (string)ob_get_clean();
    if (!$ok) { throw new RuntimeException('CHAPLIN did not render.'); }
    $start = strpos($html, 'id="browse-grid"');
    if ($start === false) { return ''; }
    $end = strpos($html, 'scroll-wall-sentinel');
    return substr($html, $start, ($end !== false ? $end : strlen($html)) - $start);
};

$ITEMS = [
    $photo(1, 1200, 800, 'Landscape'),
    $photo(2, 800, 1200, 'Portrait'),
    $photo(3, 1000, 1000, 'Square'),
    $photo(4, 1600, 900, 'Wide'),
    $photo(5, 900, 1600, 'Tall'),
    $photo(6, 1400, 1050, 'Four Three'),
];
$html = $render($ITEMS);

echo "CHAPLIN archive: the 188D code, moved into core\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "THE FOUR SELECTORS 188D'S STYLESHEET NEEDS\n";
// ─────────────────────────────────────────────────────────────────────────────
// Codex restores that CSS verbatim, so these names are a contract between us.
// A rename here silently unstyles the whole archive, which is exactly what
// happened the first time.

$check('the grid is .chap-archive-grid', str_contains($html, 'class="chap-archive-grid'));
$check('each item is an anchor with .chap-archive-item',
       substr_count($html, 'class="chap-archive-item"') === 6);
$check('the thumb box is .chap-thumb', substr_count($html, 'class="chap-thumb') === 6);
$check('the caption is .chap-archive-title',
       substr_count($html, 'class="chap-archive-title"') === 6);

// AND IT IS A <div>, WHICH COSTS TWO HEADINGS AND I AM NOT HIDING IT.
//
// 188D's caption is <div class="chap-archive-title">. The replacement made it
// <h2 class="fsog-archive-title">, so restoring 188D verbatim DROPS two <h2>
// elements from the archive, and the render snapshot caught exactly that
// (chaplin/archive headings 2 -> 0).
//
// Restoring the div is what the PM's rule allows: an <h2> is not one of the
// changes (a) to (c), so keeping it would be the kind of improvised
// improvement that caused this ticket. It is also arguably more correct --
// 188D's caption is opacity:0 until hover, and a heading nobody can see until
// they hover is a strange heading.
//
// But it IS a loss for anyone navigating by heading, so it is pinned here as a
// deliberate decision rather than left as a number in a fixture. If the PM
// wants the heading back, this assertion is what changes, and it should be a
// stated choice about CHAPLIN's semantics rather than a leftover.
$check('  as a div, exactly as 188D had it, and not an h2',
       !str_contains($html, '<h2 class="chap-archive-title"')
       && !str_contains($html, 'fsog-archive-title'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAND THE REPLACEMENT'S MARKUP IS GONE FROM THE ARCHIVE\n";
// ─────────────────────────────────────────────────────────────────────────────
// .chap-img-frame and .chap-frame-lines are real CHAPLIN selectors on the
// SOLO page and stay there. They were never in 188D's archive, and emitting
// divs 188D's stylesheet does not mention would leave unstyled boxes inside
// every thumbnail.

$check('no .fsog-* classes remain in the archive', !str_contains($html, 'fsog-'));
$check('no .chap-img-frame inside the archive', !str_contains($html, 'chap-img-frame'));
$check('no .chap-frame-lines inside the archive', !str_contains($html, 'chap-frame-lines'));
$check('the flat .ss-scroll-wall list is gone', !str_contains($html, 'ss-scroll-wall'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE ROW PACKER IS BACK, WHICH IS THE PART THAT WAS REPLACED\n";
// ─────────────────────────────────────────────────────────────────────────────
// A flat list passes every check that only counts anchors. These count ROWS.

$check('the masonry grid is built of .justified-row wrappers',
       substr_count($html, 'class="justified-row') >= 1);
$check('  the final short row is marked .justified-row-last',
       substr_count($html, 'justified-row-last') === 1);
$check('  and the last row is the LAST one, not an arbitrary one',
       strpos($html, 'justified-row-last') > strrpos($html, 'class="justified-row"'));
$check('every photograph is in a row', substr_count($html, 'class="justified-item"') === 6);

// The three CSS custom properties 188D published for the stylesheet.
$check('--justified-gap is published, at 188D\'s 4px',
       str_contains($html, '--justified-gap: 4px'));
$check('--justified-row-height is published, at 188D\'s 280px default',
       str_contains($html, '--justified-row-height: 280px'));
$check('--last-row-ar-sum is published at all', str_contains($html, '--last-row-ar-sum:'));

// AND IT IS THE RIGHT NUMBER. Without this the property could be a constant.
// It is the aspect sum of the last FULL row, which is what lets the stylesheet
// size a short final row to match the rows above it.
if (preg_match('/--last-row-ar-sum: ([0-9.]+)/', $html, $m)) {
    $expected = 0.0;
    foreach ([1200 / 800, 800 / 1200, 1000 / 1000, 1600 / 900, 900 / 1600] as $ar) {
        $expected += $ar;
    }
    $check(sprintf('  and it equals the last full row\'s aspect sum (%.4f)', $expected),
           abs((float)$m[1] - round($expected, 4)) < 0.0005);
} else {
    $check('  and it equals the last full row\'s aspect sum', false);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n188D CLAMPED THE THUMBNAILS AND LEFT THE MASONRY ALONE\n";
// ─────────────────────────────────────────────────────────────────────────────
// Two different rules in one template, and getting them the same way round is
// the difference between a tidy grid and a 27,824px wall. The thumbnail grid
// clamps to 2:3..3:2 so no cell can be absurdly tall; the justified row needs
// the TRUE ratio or the row arithmetic is wrong.

// THE TWO GRIDS ARE SLICED APART FIRST, AND THAT IS THE WHOLE POINT.
//
// FOUND BY MUTATION. These checks used to count ratios across the WHOLE
// archive, so removing the thumbnail clamp SURVIVED: the justified grid emits
// the unclamped 0.6667 for the same photograph, and the count stayed up while
// the thumbnails had gone unclamped. A check that cannot tell the two grids
// apart cannot test a rule that applies to only one of them.
$cut = strpos($html, 'id="justified-grid"');
$thumbs = $cut !== false ? substr($html, 0, $cut) : $html;
$masonry = $cut !== false ? substr($html, $cut) : '';
$check('the two grids are separable, or the checks below mean nothing',
       $thumbs !== '' && $masonry !== '' && !str_contains($thumbs, 'justified-item'));

$check('a 3:2 photograph clamps to 1.5 in the THUMBNAIL grid',
       str_contains($thumbs, 'aspect-ratio: 1.5;'));
$check('a 2:3 photograph clamps to 0.6667 there, not lower',
       str_contains($thumbs, 'aspect-ratio: 0.6667;'));
$check('  and a 9:16 photograph is ALSO clamped to 0.6667 in the thumbnails',
       substr_count($thumbs, 'aspect-ratio: 0.6667;') === 2);
$check('  so NO thumbnail is steeper than 2:3',
       !str_contains($thumbs, 'aspect-ratio: 0.5625;'));
$check('  and none is wider than 3:2',
       !str_contains($thumbs, 'aspect-ratio: 1.7778;'));
$check('a portrait thumb carries .chap-thumb-portrait',
       substr_count($html, 'chap-thumb chap-thumb-portrait') === 2);
$check('but the masonry keeps the TRUE 16:9 ratio, unclamped',
       str_contains($html, 'aspect-ratio: 1.7778;'));
$check('  and the true 9:16 ratio, unclamped',
       str_contains($html, 'aspect-ratio: 0.5625;'));
$check('the masonry item grows by its aspect, 188D\'s flex-grow',
       str_contains($html, 'flex-grow: 178;') && str_contains($html, 'flex-basis: 0;'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE EMPTY ARCHIVE STILL SAYS WHAT 188D SAID\n";
// ─────────────────────────────────────────────────────────────────────────────
// 188D printed this line with an inline style. The words are kept; the style
// moved to a class, which is change (c) and the one the agreed-rules gate
// requires.

$empty = $render([]);
$check('an empty archive renders the projector line',
       str_contains($empty, 'The projector is dark. No frames to show.'));
$check('  twice, once per grid, as 188D did',
       substr_count($empty, 'The projector is dark') === 2);
$check('  through a class rather than 188D\'s inline grid-column style',
       str_contains($empty, 'class="chap-archive-empty"')
       && !str_contains($empty, 'grid-column: 1 / -1'));
$check('  and no rows are invented for an empty archive',
       !str_contains($empty, 'class="justified-row'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nENDLESS SCROLL AND THE CONTROLS SURVIVED THE MOVE\n";
// ─────────────────────────────────────────────────────────────────────────────
// Change (b): the page is bounded where 188D's loop was not, so the sentinel
// has to still be there or the archive stops at page one -- which this
// project treats as a defect in itself.

$full = $render($ITEMS);
$check('the component still emits both grids',
       str_contains($full, 'id="browse-grid"') && str_contains($full, 'id="justified-grid"'));
$check('and the toggle script is NOT inlined back into the page',
       !str_contains($full, 'smackarchive:layoutchange'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — the 188D archive markup and row packer are in the core component.\n";
echo "NOT PROVED HERE: that it LOOKS like 188D. That is a side-by-side against\n";
echo "Sean's 0.7.188D screenshot at 1920 and 1024, with the restored CSS, and\n";
echo "it is Codex's measurement. This file proves the code moved, not the look.\n";
exit(0);

// ===== SNAPSMACK EOF =====
