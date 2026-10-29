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
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-view-model.php';
// Without this, snapsmack_skin_presentation() is missing and the per-skin
// render below hands every layout an empty options array. Five skins then
// rendered no readable thumbnail and were reported NOT CHECKED, which read
// as a property of those skins and was really a gap in this harness.
require_once $root . '/core/skin-presentation.php';

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

function ts_view(string $style, ?array $items = null): array {
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
ts_test(($cropped['response']['items'][0]['orientation_class'] ?? '') === 'orient-portrait',
    'a portrait photograph carries orient-portrait, computed in core');
ts_test(($cropped['response']['items'][1]['orientation_class'] ?? '') === 'orient-landscape',
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

echo "\nBoth admin pages label the one setting with the one vocabulary\n";
// The option read "CROPPED (NATURAL ASPECT)" -- a label that contradicts
// itself, because the stored value named 'cropped' IS the natural-aspect
// option. And a SECOND page wrote the same key with its own words:
// smack-settings.php said "CROPPED (DEFAULT)" / "SQUARE". An owner who met
// both screens had no way to know they were one control. Sean approved new
// display text on 29 October; the stored values were deliberately NOT
// renamed, so nothing saved on 25 sites had to migrate. SS-1043.
$thumbSelects = [];
foreach (['smack-appearance-archive.php', 'smack-settings.php'] as $_page) {
    $_src = (string) file_get_contents($root . '/' . $_page);
    // Scope to this one select: smack-settings.php holds dozens of others.
    $_at = strpos($_src, "settings[archive_thumb_style]");
    $_end = $_at === false ? false : strpos($_src, '</select>', $_at);
    $_chunk = ($_at === false || $_end === false) ? '' : substr($_src, $_at, $_end - $_at);
    // [^<>]+ on the label, not [^<]+: the option tag carries an inline PHP
    // block, and that block's own closing bracket would otherwise be taken
    // for the option tag's, so the label came back with a stray leading
    // bracket. (Writing that two-character sequence in this comment ended
    // PHP mode and broke the file, which is its own small lesson.)
    preg_match_all('/<option value="(square|cropped)"[\s\S]*?>([^<>]+)<\/option>/', $_chunk, $_m, PREG_SET_ORDER);
    $labels = [];
    foreach ($_m as $_o) { $labels[$_o[1]] = trim($_o[2]); }
    $thumbSelects[$_page] = $labels;
    ts_test(count($labels) === 2, "{$_page} offers exactly the two stored values and no third");
}

$appearance = $thumbSelects['smack-appearance-archive.php'] ?? [];
$settings_p = $thumbSelects['smack-settings.php'] ?? [];

ts_test(($appearance['cropped'] ?? '') === 'NATURAL ASPECT (DEFAULT)',
    'Archive Appearance labels the cropped value NATURAL ASPECT (DEFAULT)');
ts_test(($appearance['square'] ?? '') === 'SQUARE CROP (1:1)',
    'Archive Appearance labels the square value SQUARE CROP (1:1)');
ts_test($appearance === $settings_p,
    'BOTH pages show the identical strings, so one control cannot read as two');
ts_test(array_keys($appearance) === array_keys($settings_p)
        && array_keys($appearance) !== [],
    'and both write the same two stored values');

// The point of relabelling was to leave the data alone. If a label change
// ever reaches the value attribute, every saved setting on the fleet breaks.
ts_test(isset($appearance['cropped'], $appearance['square']),
    "the stored values are still 'cropped' and 'square', so no site has to migrate");
foreach (array_merge($appearance, $settings_p) as $_lbl) {
    ts_test(stripos($_lbl, 'CROPPED (NATURAL ASPECT)') === false,
        'the self-contradicting label is gone: ' . $_lbl);
}

// Both save handlers must still accept exactly the two values. A relabel that
// quietly widened or narrowed validation would be worse than the bad label.
foreach (['smack-appearance-archive.php', 'smack-settings.php'] as $_page) {
    $_src = (string) file_get_contents($root . '/' . $_page);
    ts_test(preg_match("/archive_thumb_style'\], \['square', 'cropped'\], true\)/", $_src) === 1,
        "{$_page} still validates the save against exactly square|cropped");
}

echo "\nMEASURED ON RENDERED OUTPUT, not on a substring in a layout file\n";
// Codex asked for this and he was right. The note here used to grep
// skins/true-grit/layout.php for the hardcoded class, which reports what the
// FILE says, not what the PAGE does -- the same mistake as trusting a code
// comment. So render each skin both ways and compare the output.
//
// A skin is only reported, never asserted, EXCEPT where the archive is drawn
// by a core page component: that is core's own lane, so it must flip, and a
// failure there is mine. For a skin that draws its own grid in its layout,
// the fix is one line in Codex's lane and this prints what it finds.
function ts_render(string $root, string $skin, string $style): string {
    $item = [
        'id' => 1, 'img_slug' => 'a-photo', 'img_title' => 'A Photo', 'img_alt' => 'A Photo',
        'img_file' => '/media/photo.jpg', 'img_thumb_aspect' => '/media/aspect.jpg',
        'img_thumb_square' => '/media/square.jpg', 'img_width' => 1200, 'img_height' => 800,
        'url' => '/a-photo',
    ];
    $response = ['status' => 200, 'kind' => 'archive', 'items' => [$item, $item], 'navigation' => []];
    $site = [
        'site_name' => 'Example', 'language' => 'en', 'direction' => 'ltr',
        'skin_slug' => $skin,
        'skin_style_url' => "/skins/{$skin}/style.css",
        'skin_presentation' => function_exists('snapsmack_skin_presentation')
            ? snapsmack_skin_presentation([], $skin) : [],
        'archive_layout' => 'thumbs',
        'archive_thumb_style' => $style,
        'archive_grid' => snapsmack_archive_grid_metrics(['browse_cols' => 4, 'thumb_size' => 'm'], $style),
    ];
    $view = snapsmack_build_skin_view($response, $site);
    ob_start();
    snapsmack_render_strict_skin_template($root . '/skins/' . $skin, 'layout.php', $view);
    return (string) ob_get_clean();
}

// Which thumbnail file the rendered page actually asks for. That is the fact
// Sean reported: "every thumbnail in that grid is the t_ prefixed file".
function ts_thumbs(string $html): array {
    preg_match_all('/src="([^"]*(?:aspect|square|photo)[^"]*)"/', $html, $m);
    return array_values(array_unique($m[1] ?? []));
}

// CORE'S OWN LANE: 50 SHADES draws its thumbs pane inside
// core/50-shades-page-component.php, so nothing in a skin file can excuse it.
// Muse's count correction is what turned this one up.
$fsogCropped = ts_render($root, '50-shades-of-noah-grey', 'cropped');
$fsogSquare  = ts_render($root, '50-shades-of-noah-grey', 'square');
$fsogC = ts_thumbs($fsogCropped);
$fsogS = ts_thumbs($fsogSquare);
ts_test($fsogC !== [] && $fsogS !== [],
    '50 SHADES renders thumbnails at all, so the comparison below means something');
ts_test($fsogC !== $fsogS,
    '50 SHADES asks for a DIFFERENT file when the setting flips (its grid is in core)');
ts_test(in_array('/media/square.jpg', $fsogS, true),
    'and in square mode it asks for the square derivative by name');
ts_test(in_array('/media/aspect.jpg', $fsogC, true),
    'and in natural-aspect mode it asks for the aspect derivative by name');
ts_test(str_contains($fsogSquare, 'aspect-ratio:1'),
    'a 50 SHADES square tile is given a 1:1 box, so the setting is visible');

// EVERY SKIN THAT DRAWS ITS OWN THUMBS GRID: reported, not asserted.
// PASS / FAIL as AGENTS.md requires, with the reason stated.
echo "\n  Per-skin, measured from rendered output (reported, not asserted --\n";
echo "  these layouts are Codex's lane and the prepared fields are new):\n";
$ownGrid = ['52-card-pickup', 'alfred', 'aurora', 'game-on', 'glide', 'heuristic',
    'hip-to-be-square', 'instant-camera', 'jive-turkey', 'new-horizon', 'parade',
    'show-n-tell', 'slickr', 'sliders', 'stanley', 'sudden-impact', 'the-grid',
    'tilez', 'true-grit', 'writing-with-impact'];
$honours = 0;
$ignores = [];
$unrendered = [];
$warned = [];
$warnSkin = '';
// A layout that reads a missing option key without a guard is a real finding,
// but it is not this test's subject and the noise buried the result. Counted
// and named below rather than silenced.
set_error_handler(function ($no, $str) use (&$warned, &$warnSkin) {
    if ($warnSkin !== '' && !in_array($warnSkin, $warned, true)) { $warned[] = $warnSkin; }
    return true;
}, E_WARNING | E_NOTICE | E_DEPRECATED);
foreach ($ownGrid as $skinSlug) {
    $warnSkin = $skinSlug;
    if (!is_file($root . '/skins/' . $skinSlug . '/layout.php')) { $unrendered[] = $skinSlug; continue; }
    try {
        $c = ts_thumbs(ts_render($root, $skinSlug, 'cropped'));
        $s = ts_thumbs(ts_render($root, $skinSlug, 'square'));
    } catch (Throwable $e) { $unrendered[] = $skinSlug; continue; }
    if ($c === [] && $s === []) { $unrendered[] = $skinSlug; continue; }
    if ($c !== $s) { $honours++; } else { $ignores[] = $skinSlug; }
}
printf("    PASS        %d of %d honour the setting in rendered output\n", $honours, count($ownGrid));
printf("    FAIL        %d still render the same file both ways: %s\n",
    count($ignores), $ignores === [] ? 'none' : implode(', ', $ignores));
printf("    NOT CHECKED %d could not be rendered here: %s\n",
    count($unrendered), $unrendered === [] ? 'none' : implode(', ', $unrendered));
restore_error_handler();
$warnSkin = '';
printf("    WARNED      %d emitted a PHP warning when rendered with no saved",
    count($warned));
printf(" options, which is a layout reading a missing option key without a
");
printf("                guard, not a thumb-style fault: %s
",
    $warned === [] ? 'none' : implode(', ', $warned));
echo "    A FAIL above is not a defect in this build. Core prepares the answer;\n";
echo "    printing it is one line per layout, and that work is Codex's.\n";

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Archive thumb style reach: FAIL ({$fail}).\n";
    exit(1);
}
echo "Archive thumb style reach: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
