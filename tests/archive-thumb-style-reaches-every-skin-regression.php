<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * THUMB STYLE must reach a skin that is not CHAPLIN and not IMPACT PRINTER.
 *
 * Sean, on a deployed build: "cropped natural still not being passed
 * through." He set THUMB STYLE to the natural-aspect option on TRUE GRIT and
 * the archive stayed square. SS-1043.
 *
 * The setting was reaching two skins out of thirty. Twenty layouts draw their
 * own thumbs grid and not one of them read archive_thumb_style -- TRUE GRIT's
 * layout hardcodes BOTH halves of the answer, class="square-grid" and
 * img_thumb_square first, so no choice the owner made could win. The two that
 * honoured it did so because they render through core page components, where
 * executable code is allowed.
 *
 * WHY THAT IS A CORE FAULT AND NOT TWENTY SKIN FAULTS. A strict skin may hold
 * no executable code: the security ratchet refuses a function call, BASE_URL
 * or a cast inside a layout, and it is right to. Choosing between two thumb
 * derivatives is a conditional. So a strict layout CANNOT honour this setting
 * on its own, however willing it is. Core never prepared the answer, so each
 * layout hardcoded one. The hardcoding is the symptom. The missing prepared
 * field was the fault, and that is what this test pins.
 *
 * WHAT THIS TEST DOES NOT CLAIM. It does not claim any skin's archive now
 * renders natural-aspect photographs. The prepared fields exist and are
 * correct; printing them is a one-line change per layout in Codex's lane, and
 * until he makes it TRUE GRIT still hardcodes square. This test proves the
 * CMS side of SS-1043 and nothing beyond it. Measured by assertion, not by
 * reading a comment: every claim below reads a value the code produced.
 */

$root = dirname(__DIR__);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-view-model.php';

$pass = 0;
$fail = 0;

function ts_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

/** Two photographs with every derivative present, so a wrong pick is visible. */
function ts_items(): array {
    return [
        [
            'img_slug' => 'a-portrait', 'img_title' => 'A portrait',
            'img_thumb_square' => 'img_uploads/a-square.jpg',
            'img_thumb_aspect' => 'img_uploads/a-aspect.jpg',
            'img_file' => 'img_uploads/a-full.jpg',
            'img_width' => 800, 'img_height' => 1200, 'img_orientation' => 1,
        ],
        [
            'img_slug' => 'a-landscape', 'img_title' => 'A landscape',
            'img_thumb_square' => 'img_uploads/b-square.jpg',
            'img_thumb_aspect' => 'img_uploads/b-aspect.jpg',
            'img_file' => 'img_uploads/b-full.jpg',
            'img_width' => 1600, 'img_height' => 900, 'img_orientation' => 0,
        ],
    ];
}

function ts_view(string $style, array $items = null): array {
    return snapsmack_build_skin_view(
        ['status' => 200, 'kind' => 'archive', 'items' => $items ?? ts_items()],
        // TRUE GRIT on purpose: the skin Sean reported, and one of the twenty
        // that render from their own layout rather than a core component.
        ['skin_slug' => 'true-grit', 'archive_thumb_style' => $style]
    );
}

echo "The prepared thumb flips with the setting, on a skin with no core component\n";
$cropped = ts_view('cropped');
$square  = ts_view('square');

ts_test(($cropped['response']['items'][0]['thumb_url'] ?? '') === 'img_uploads/a-aspect.jpg',
    'cropped gives the natural-aspect derivative');
ts_test(($square['response']['items'][0]['thumb_url'] ?? '') === 'img_uploads/a-square.jpg',
    'square gives the 1:1 derivative');
ts_test(($cropped['response']['items'][1]['thumb_url'] ?? '')
        !== ($square['response']['items'][1]['thumb_url'] ?? ''),
    'the second photograph flips too, so this is per item and not one lucky index');

echo "\nThe prepared fields survive the view contract's allowlist\n";
// The allowlist strips unknown TOP-LEVEL response keys, which is how
// archive_layouts was silently removed from 50 SHADES before it was added to
// it. Item sub-keys are a different matter and this proves which.
ts_test(array_key_exists('thumb_url', $cropped['response']['items'][0] ?? []),
    'thumb_url reaches the skin rather than being filtered out');
ts_test(($cropped['response']['items'][0]['thumb_style'] ?? '') === 'cropped',
    'the item states which style produced it, so a layout need not ask twice');
ts_test(($cropped['response']['items'][0]['thumb_orientation'] ?? '') === 'orient-portrait',
    'a portrait photograph carries orient-portrait, computed in core');
ts_test(($cropped['response']['items'][1]['thumb_orientation'] ?? '') === 'orient-landscape',
    'a landscape photograph carries orient-landscape');

echo "\nA photograph with only one derivative still shows a photograph\n";
// A site whose square thumbs were never generated must not get empty tiles.
$onlyAspect = ts_view('square', [[
    'img_slug' => 'c', 'img_thumb_aspect' => 'img_uploads/c-aspect.jpg',
    'img_file' => 'img_uploads/c-full.jpg', 'img_width' => 100, 'img_height' => 100,
]]);
ts_test(($onlyAspect['response']['items'][0]['thumb_url'] ?? '') === 'img_uploads/c-aspect.jpg',
    'square falls back to the aspect derivative before the full-size file');
$onlyFull = ts_view('cropped', [[
    'img_slug' => 'd', 'img_file' => 'img_uploads/d-full.jpg',
    'img_width' => 100, 'img_height' => 100,
]]);
ts_test(($onlyFull['response']['items'][0]['thumb_url'] ?? '') === 'img_uploads/d-full.jpg',
    'with no derivative at all the full-size file is used rather than nothing');

echo "\nAn unsaveable value cannot reach the page\n";
// Both save handlers validate against square|cropped and fall back to
// cropped. A row holding anything else predates them or was written directly.
$junk = ts_view('oblong');
ts_test(($junk['response']['items'][0]['thumb_url'] ?? '') === 'img_uploads/a-aspect.jpg',
    'an invalid stored value falls back to cropped, matching both save handlers');
$absent = snapsmack_build_skin_view(
    ['status' => 200, 'kind' => 'archive', 'items' => ts_items()],
    ['skin_slug' => 'true-grit']);
ts_test(($absent['response']['items'][0]['thumb_url'] ?? '') === 'img_uploads/a-aspect.jpg',
    'no stored value at all defaults to cropped, as every other reader does');

echo "\nIt is the archive's business and nowhere else's\n";
$photo = snapsmack_build_skin_view(
    ['status' => 200, 'kind' => 'photo', 'items' => ts_items()],
    ['skin_slug' => 'true-grit', 'archive_thumb_style' => 'square']);
ts_test(!array_key_exists('thumb_url', $photo['response']['items'][0] ?? []),
    'a photo page gets no prepared archive thumb: nothing is added where nothing asked');
$hashtag = snapsmack_build_skin_view(
    ['status' => 200, 'kind' => 'hashtag', 'items' => ts_items()],
    ['skin_slug' => 'true-grit', 'archive_thumb_style' => 'square']);
ts_test(($hashtag['response']['items'][0]['thumb_url'] ?? '') === 'img_uploads/a-square.jpg',
    'a hashtag archive is an archive and gets the same preparation');

echo "\nThe grid class is computed in core, so a layout need not hardcode one\n";
$mCropped = snapsmack_archive_grid_metrics(['browse_cols' => 4, 'thumb_size' => 'm'], 'cropped');
$mSquare  = snapsmack_archive_grid_metrics(['browse_cols' => 4, 'thumb_size' => 'm'], 'square');
ts_test(str_contains($mCropped['class'] ?? '', 'cropped-grid'),
    'the cropped style produces cropped-grid');
ts_test(str_contains($mSquare['class'] ?? '', 'square-grid'),
    'the square style produces square-grid');
ts_test(!str_contains($mCropped['class'] ?? '', 'square-grid'),
    'and the cropped class does not also carry square-grid, which would make the setting moot');
ts_test(str_contains($mCropped['class'] ?? '', 'archive-grid')
        && str_contains($mCropped['class'] ?? '', 'archive-cols-4')
        && str_contains($mCropped['class'] ?? '', 'archive-thumbs-m'),
    'the class carries the shared hooks, the column count and the size step');
$mCols = snapsmack_archive_grid_metrics(['browse_cols' => 7, 'thumb_size' => 'l'], 'square');
ts_test(str_contains($mCols['class'] ?? '', 'archive-cols-7')
        && str_contains($mCols['class'] ?? '', 'archive-thumbs-l'),
    'the owner\'s column count and size reach the class, not a fixed pair');

echo "\nThe class reaches the skin, which is where the last one died\n";
$withGrid = snapsmack_build_skin_view(
    ['status' => 200, 'kind' => 'archive', 'items' => ts_items()],
    ['skin_slug' => 'true-grit', 'archive_thumb_style' => 'square',
     'archive_grid' => $mSquare]);
ts_test(str_contains((string)($withGrid['site']['archive_grid']['class'] ?? ''), 'square-grid'),
    'archive_grid.class survives the presentation allowlist');

echo "\nThe shared archive-thumb component honours the setting\n";
// It took img_thumb_aspect unconditionally, so ONYX ignored THUMB STYLE as
// well: a third affected skin, and that one was core's own doing.
$items = ts_items();
$cSquare = (string)snap_render_component('archive-thumb',
    ['item' => $items[0], 'prefix' => 'pfd', 'thumb_style' => 'square']);
$cCropped = (string)snap_render_component('archive-thumb',
    ['item' => $items[0], 'prefix' => 'pfd', 'thumb_style' => 'cropped']);
ts_test(str_contains($cSquare, 'a-square.jpg'), 'square renders the 1:1 derivative');
ts_test(str_contains($cCropped, 'a-aspect.jpg'), 'cropped renders the natural-aspect derivative');
ts_test(str_contains($cSquare, 'aspect-ratio:1'),
    'a square tile is given a 1:1 box, so the setting is visible and not just a different file');
ts_test(!str_contains($cCropped, 'aspect-ratio:1"') && str_contains($cCropped, 'aspect-ratio:0.6667'),
    'a cropped tile keeps the photograph\'s own clamped ratio');
$cDefault = (string)snap_render_component('archive-thumb', ['item' => $items[0], 'prefix' => 'pfd']);
ts_test(str_contains($cDefault, 'a-aspect.jpg'),
    'with no style given the component behaves exactly as it did before, so ONYX is unchanged');
$cPrepared = (string)snap_render_component('archive-thumb',
    ['item' => $cropped['response']['items'][0], 'prefix' => 'pfd']);
ts_test(str_contains($cPrepared, 'a-aspect.jpg'),
    'an item that already carries thumb_url is trusted, so the choice is made in one place');

echo "\nThe CMS side is complete; the skins are not, and that is stated\n";
// Named plainly so nobody reads a green run as "TRUE GRIT is fixed".
$tg = (string) file_get_contents($root . '/skins/true-grit/layout.php');
$stillHardcoded = str_contains($tg, 'square-grid archive-grid');
echo $stillHardcoded
    ? "  note  skins/true-grit/layout.php still hardcodes square-grid: core is ready, the layout is not (Codex's lane)\n"
    : "  note  skins/true-grit/layout.php no longer hardcodes square-grid\n";

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Archive thumb style reach: FAIL ({$fail}).\n";
    exit(1);
}
echo "Archive thumb style reach: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
