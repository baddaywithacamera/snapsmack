<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * 52-CARD-PICKUP's ARCHIVE TILES MUST CARRY THE CLASS ITS STYLESHEET STYLES.
 *
 * The fault: style.css styled .pickup-archive-item -- three rules, the square
 * aspect-ratio, the white card with its padding and shadow, and the hover
 * lift -- and layout.php emitted .pickup-card. Nothing matched. The archive
 * grid itself was styled, because .pickup-archive-grid did match, so the page
 * looked deliberate: tiles in a 4-column grid, at the image's own aspect
 * ratio, with no card and no hover. Nothing failed and no check saw it.
 *
 * The repair was one class name in the layout, not three in the stylesheet,
 * because every other skin that styles a tile this way uses
 * <prefix>-archive-item -- GLIDE and HIP TO BE SQUARE both do -- so the
 * stylesheet followed the house convention and the template was the odd one.
 *
 * WHY A NAME CHECK IS NOT ENOUGH, and what this does instead. Comparing two
 * strings in two files would pass if both were renamed to something the grid
 * does not contain, or if the tile moved into a branch the archive never
 * reaches. So this RENDERS the archive through the real strict-skin template
 * path and then asks the rendered page the three questions that matter:
 * is the class on the tile, is the tile inside the styled grid, and does the
 * stylesheet still style that class.
 *
 * WHAT THIS CANNOT SEE, said plainly: it reads markup and stylesheet text. It
 * does not measure a rendered tile, so it cannot prove the square crop or the
 * shadow appears. Codex holds the headed browser and that proof is his.
 */

define('SNAPSMACK_SKIN_RENDER', true);
$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';
require_once $root . '/core/public-controller.php';

$pass = 0; $fail = 0;
function pk(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

function pk_render_archive(string $root, string $skin, int $count = 9): string {
    $items = [];
    for ($i = 0; $i < $count; $i++) {
        $items[] = ['img_title' => 'Photo ' . $i, 'img_slug' => 'photo-' . $i,
            'img_thumb_square' => '/t.jpg', 'img_thumb_aspect' => '/a.jpg',
            'img_file' => '/p.jpg'];
    }
    $items = snapsmack_grid_frame_items($items, [], $skin);
    $view = snapsmack_build_skin_view(
        ['kind' => 'archive', 'photo_count' => $count, 'navigation' => [], 'items' => $items,
         'page' => 1, 'per_page' => $count, 'total_count' => $count, 'total_pages' => 1],
        ['site_name' => 'Pickup', 'language' => 'en', 'direction' => 'ltr',
         'skin_style_url' => '/skin.css',
         'skin_presentation' => snapsmack_skin_presentation([], $skin)]
    );
    ob_start();
    $ok = snapsmack_render_strict_skin_template($root . '/skins/' . $skin, 'layout.php', $view);
    $html = (string)ob_get_clean();
    if (!$ok) throw new RuntimeException("{$skin} did not render");
    return $html;
}

$css = (string)@file_get_contents($root . '/skins/52-card-pickup/style.css');
$html = pk_render_archive($root, '52-card-pickup');

echo "THE STYLESHEET STILL STYLES THE TILE -- OTHERWISE THERE IS NOTHING TO CARRY\n";
pk(str_contains($css, '.pickup-archive-item'),
   'style.css declares rules for .pickup-archive-item');
pk(substr_count($css, '.pickup-archive-item') >= 3,
   'all three of them are still there -- the box, the image and the hover (found '
   . substr_count($css, '.pickup-archive-item') . ')');
pk(preg_match('/\.pickup-archive-item\s+img\s*\{[^}]*aspect-ratio/s', $css) === 1,
   'the square crop is still declared on the tile image');
pk(preg_match('/\.pickup-archive-item\s+img\s*\{[^}]*object-fit:\s*cover/s', $css) === 1,
   'and it still crops rather than squashing');

echo "\nTHE RENDERED ARCHIVE CARRIES IT. THIS IS THE CHECK THAT WAS MISSING\n";
pk($html !== '', 'the archive rendered');
pk(str_contains($html, 'pickup-archive-item'),
   'the rendered page carries pickup-archive-item');
pk(!str_contains($html, 'class="pickup-card"'),
   'and no longer carries the orphaned pickup-card, which matched no rule');
pk(substr_count($html, 'pickup-archive-item') === 9,
   'every one of the nine tiles carries it (found '
   . substr_count($html, 'pickup-archive-item') . ')');

echo "\nTHE TILE IS INSIDE THE GRID THE STYLESHEET LAYS OUT\n";
// A tile with the right class in the wrong place is styled and still wrong.
// The grid is what gives it a column; measured on the DOM, not on string
// order, because string order would pass for a sibling.
$doc = new DOMDocument();
$prev = libxml_use_internal_errors(true);
$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
libxml_clear_errors();
libxml_use_internal_errors($prev);
$x = new DOMXPath($doc);
$inGrid = $x->query(
    "//*[contains(concat(' ',normalize-space(@class),' '),' pickup-archive-grid ')]"
    . "//*[contains(concat(' ',normalize-space(@class),' '),' pickup-archive-item ')]"
);
pk($inGrid !== false && $inGrid->length === 9,
   'all nine tiles are descendants of .pickup-archive-grid (found '
   . ($inGrid === false ? 'query failed' : (string)$inGrid->length) . ')');
pk(str_contains($css, '.pickup-archive-grid'),
   'and the grid itself is still styled, which is why this looked deliberate '
   . 'rather than broken');
$withImg = $x->query(
    "//*[contains(concat(' ',normalize-space(@class),' '),' pickup-archive-item ')]//img"
);
pk($withImg !== false && $withImg->length === 9,
   'each tile contains an image for the img rules to reach (found '
   . ($withImg === false ? 'query failed' : (string)$withImg->length) . ')');

echo "\nMUTATION PROOF: THE OLD MARKUP MUST FAIL THIS\n";
// The pre-repair page, reproduced from the repaired one. If these checks
// cannot tell the two apart they are not measuring anything.
$old = str_replace('class="pickup-archive-item"', 'class="pickup-card"', $html);
pk(!str_contains($old, 'pickup-archive-item'),
   'the pre-repair markup carries no styled tile class at all');
$oldDoc = new DOMDocument();
$prev = libxml_use_internal_errors(true);
$oldDoc->loadHTML('<?xml encoding="UTF-8">' . $old);
libxml_clear_errors();
libxml_use_internal_errors($prev);
$oldIn = (new DOMXPath($oldDoc))->query(
    "//*[contains(concat(' ',normalize-space(@class),' '),' pickup-archive-grid ')]"
    . "//*[contains(concat(' ',normalize-space(@class),' '),' pickup-archive-item ')]"
);
pk($oldIn !== false && $oldIn->length === 0,
   'and nothing in its grid is styled, which is the state Sean would have seen');

echo "\nTHE HOUSE CONVENTION, so the next skin does not drift the same way\n";
$conventional = [];
foreach (glob($root . '/skins/*/layout.php') ?: [] as $layout) {
    if (preg_match('/class="[a-z0-9-]*-archive-item"/', (string)file_get_contents($layout))) {
        $conventional[] = basename(dirname($layout));
    }
}
sort($conventional);
pk(in_array('52-card-pickup', $conventional, true),
   'this skin now follows it, with ' . count($conventional) . ' others: '
   . implode(', ', $conventional));

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "52-CARD-PICKUP archive tiles: FAIL ({$fail}).\n";
    exit(1);
}
echo "52-CARD-PICKUP archive tiles: PASS ({$pass} assertions).\n";
echo "  NOT CHECKED: a measured tile on a rendered page. This reads markup and\n";
echo "  stylesheet text and drives no browser, so it cannot prove the square crop\n";
echo "  or the card shadow appears. That proof is the headed runner's.\n";

// ===== SNAPSMACK EOF =====
