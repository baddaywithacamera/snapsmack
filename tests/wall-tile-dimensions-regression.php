<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A wall tile never claims a shape it was not told.
 *
 * Sean, on his own search results: "there are portrait images in there as
 * landscape" and "sizing of last row is inconsistent and sloppy". Both came
 * from one line.
 *
 * The wall-tile component emitted data-w and data-h with a fallback of 3 and
 * 2. That is not a safe default, it is an assertion that every photograph is
 * landscape. PublicRepository::search() was the one tile query in the file
 * that did not select img_width and img_height -- every other one does -- so
 * every search result arrived without dimensions and was handed 3:2.
 *
 * Measured live at 1920 before the repair: a 400x600 portrait rendered
 * 266x177, a 526x600 portrait rendered 330x220, a 600x305 panorama rendered
 * 266x177. Every tile computed to aspect 1.500 with object-fit cover. And
 * because every tile claimed the same shape, the masonry engine could not
 * vary widths: row one was five tiles of 266x177 and the last row three of
 * 330x220 in a 1400 container.
 *
 * ss-engine-masonry already falls back to the image's own naturalWidth and
 * naturalHeight when the attributes are absent. The 3:2 default defeated that
 * fallback by supplying a value that was never true.
 */

$root = dirname(__DIR__);
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';

$fail = 0;
function wt_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

$render = static function (array $item): string {
    return (string)snap_render_component('wall-tile', ['item' => $item]);
};

// 1. Real dimensions are carried through exactly.
$portrait = $render(['img_slug' => 'p', 'url' => '/p', 'img_file' => '/a.jpg', 'img_width' => 400, 'img_height' => 600]);
wt_test(str_contains($portrait, 'data-w="400"') && str_contains($portrait, 'data-h="600"'),
    'a photograph with dimensions carries its own width and height');

// 2. A portrait must never be described as landscape.
wt_test(!str_contains($portrait, 'data-w="3"') && !str_contains($portrait, 'data-h="2"'),
    'a 400x600 portrait is not relabelled 3:2');

// 3. THE FAULT ITSELF. With no dimensions the tile must say nothing, so the
//    engine measures the loaded image instead of trusting an invented shape.
$unknown = $render(['img_slug' => 'u', 'url' => '/u', 'img_file' => '/b.jpg']);
wt_test(!str_contains($unknown, 'data-w='),
    'a tile with unknown width does not invent one');
wt_test(!str_contains($unknown, 'data-h='),
    'a tile with unknown height does not invent one');
wt_test(!str_contains($unknown, 'data-w="3"'),
    'the old 3:2 default is gone, so a missing dimension cannot read as landscape');

// 4. A zero or nonsense dimension is the same as no dimension, not a 1px tile.
$zero = $render(['img_slug' => 'z', 'url' => '/z', 'img_file' => '/c.jpg', 'img_width' => 0, 'img_height' => 0]);
wt_test(!str_contains($zero, 'data-w=') && !str_contains($zero, 'data-h='),
    'a zero dimension is treated as unknown rather than emitted');

// 5. The query that feeds this component must select what it needs. This is
//    the half of the fault that lives in the repository: every other tile
//    query in that file selected the dimensions and search did not.
$repo = (string)file_get_contents($root . '/core/public-repository.php');
$searchFn = '';
$at = strpos($repo, 'public function search(');
if ($at !== false) $searchFn = substr($repo, $at, 1800);
wt_test($searchFn !== '', 'the search query was found, so the check below means something');
wt_test(str_contains($searchFn, 'i.img_width') && str_contains($searchFn, 'i.img_height'),
    'search results select img_width and img_height like every other tile query');

echo "\n";
if ($fail > 0) {
    echo "Wall tile dimensions: FAIL ({$fail}).\n";
    exit(1);
}
echo "Wall tile dimensions: PASS.\n";

// ===== SNAPSMACK EOF =====
