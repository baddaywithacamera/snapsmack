<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * THE THUMBNAIL FRAME CONTROL MUST NOT CLAIM MORE SKINS THAN IT REACHES.
 *
 * SS-1207, second half. The tip on ARCHIVE APPEARANCE said: "Border style
 * applied to archive thumbnails in all grid modes and all skins." Nobody had
 * measured that, and the ticket said so rather than guessing.
 *
 * It is now measured, and it is wrong. The control writes two rules into the
 * custom_css_public blob, targeting:
 *
 *   grid     .square-grid .thumb-link, .cropped-grid .thumb-link,
 *            .fsog-archive-item .fsog-thumb, .rg-archive-item .rg-thumb
 *   masonry  #justified-grid .justified-item
 *
 * So it reaches a skin only if that skin's rendered archive contains a
 * matching element. Rendering all 30 archives in square, cropped and masonry
 * and asking the DOM gives FIVE: 50 SHADES OF NOAH GREY, CHAPLIN, IMPACT
 * PRINTER, NEW HORIZON and TRUE GRIT. Twenty-five skins get nothing.
 *
 * This is the same repair as 830192ad, which stopped THUMB STYLE claiming all
 * thirty skins. A control that quietly does nothing is worse than a control
 * that is absent, because the owner changes it, sees no change, and concludes
 * the product is broken -- which it is, just not where he is looking.
 *
 * WHAT THIS FILE PINS: the tip names every skin the control reaches and does
 * not name one it misses. If a skin's archive markup changes so that it starts
 * or stops matching, this fails and the wording has to be brought back in line.
 *
 * WHAT IT CANNOT SEE, and the tip says so too: MASONRY AT RUNTIME.
 * ss-engine-justified.js uses .justified-item as an itemSelector -- it lays
 * out items that already exist rather than creating them -- and the only
 * engine that creates them, ss-engine-lighttable.js, is declared by no skin.
 * Server-side, only CHAPLIN's page component emits .justified-item. Whether
 * any other skin's masonry items carry that class on a live page needs a
 * browser, so it is NOT CHECKED here and is named as unmeasured in the tip.
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
function tf(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

/** The selector pairs the saved css targets, read as outer/inner class. */
$targets = [
    '.square-grid .thumb-link'        => ['square-grid', 'thumb-link'],
    '.cropped-grid .thumb-link'       => ['cropped-grid', 'thumb-link'],
    '.fsog-archive-item .fsog-thumb'  => ['fsog-archive-item', 'fsog-thumb'],
    '.rg-archive-item .rg-thumb'      => ['rg-archive-item', 'rg-thumb'],
    '#justified-grid .justified-item' => ['#justified-grid', 'justified-item'],
];

function tf_render(string $root, string $skin, string $mode, int $count = 9): string {
    $items = [];
    for ($i = 0; $i < $count; $i++) {
        $items[] = ['id' => $i + 1, 'img_title' => 'Photo ' . $i, 'img_slug' => 'photo-' . $i,
            'img_thumb_square' => '/t.jpg', 'img_thumb_aspect' => '/a.jpg', 'img_file' => '/p.jpg',
            'img_width' => 1200, 'img_height' => 800, 'img_orientation' => 'landscape'];
    }
    $items = snapsmack_grid_frame_items($items, [], $skin);
    $view = snapsmack_build_skin_view(
        ['kind' => 'archive', 'photo_count' => $count, 'navigation' => [], 'items' => $items,
         'page' => 1, 'per_page' => $count, 'total_count' => $count, 'total_pages' => 1,
         'archive_layout' => $mode],
        ['site_name' => 'Reach', 'language' => 'en', 'direction' => 'ltr',
         'skin_style_url' => '/skin.css', 'archive_layout' => $mode,
         'skin_presentation' => snapsmack_skin_presentation(['archive_layout' => $mode], $skin)]
    );
    ob_start();
    $ok = snapsmack_render_strict_skin_template($root . '/skins/' . $skin, 'layout.php', $view);
    $html = (string)ob_get_clean();
    return $ok ? $html : '';
}

function tf_matches(string $html, array $pair): int {
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    [$outer, $inner] = $pair;
    $outerX = str_starts_with($outer, '#')
        ? "//*[@id='" . substr($outer, 1) . "']"
        : "//*[contains(concat(' ',normalize-space(@class),' '),' {$outer} ')]";
    $found = (new DOMXPath($doc))->query(
        $outerX . "//*[contains(concat(' ',normalize-space(@class),' '),' {$inner} ')]"
    );
    return $found === false ? 0 : $found->length;
}

echo "WHICH SKINS THE SAVED CSS CAN REACH -- RENDERED AND ASKED, NOT ASSUMED\n";
$reached = [];
$skins = [];
foreach (glob($root . '/skins/*', GLOB_ONLYDIR) ?: [] as $dir) {
    if (is_file($dir . '/layout.php')) $skins[] = basename($dir);
}
sort($skins);
tf(count($skins) >= 30, count($skins) . ' renderable skins found');

foreach ($skins as $slug) {
    foreach (['square', 'cropped', 'masonry'] as $mode) {
        $html = tf_render($root, $slug, $mode);
        if ($html === '') continue;
        foreach ($targets as $pair) {
            if (tf_matches($html, $pair) > 0) { $reached[$slug] = true; break 2; }
        }
    }
}
$reachedList = array_keys($reached);
sort($reachedList);
tf($reachedList !== [], 'the control reaches at least one skin, so this check is not vacuous');
echo "        reached: " . implode(', ', $reachedList) . "\n";

echo "\nTHE TIP NAMES EVERY SKIN IT REACHES, AND NO SKIN IT MISSES\n";
$admin = (string)@file_get_contents($root . '/smack-appearance-archive.php');
$tip = (static function (string $src): string {
    $at = strpos($src, 'THUMBNAIL FRAME');
    if ($at === false) return '';
    $open = strpos($src, 'data-tip="', $at);
    if ($open === false) return '';
    $open += 10;
    $close = strpos($src, '"', $open);
    return $close === false ? '' : substr($src, $open, $close - $open);
})($admin);
tf($tip !== '', 'the THUMBNAIL FRAME tip was found');
tf(!str_contains(strtolower($tip), 'all skins'),
   'it no longer says "all skins"');

/** Slug to the name an owner sees in the admin. */
$spoken = static fn(string $slug): string => strtoupper(str_replace('-', ' ', $slug));
$unnamed = [];
foreach ($reachedList as $slug) {
    $name = $spoken($slug);
    if ($name === '50 SHADES OF NOAH GREY') $name = '50 SHADES'; // the tip spells it in full
    if (!str_contains(strtoupper($tip), $name) && !str_contains(strtoupper($tip), $spoken($slug))) {
        $unnamed[] = $slug;
    }
}
tf($unnamed === [],
   'every reached skin is named in the tip' . ($unnamed === [] ? '' : ': missing ' . implode(', ', $unnamed)));

$wronglyNamed = [];
foreach ($skins as $slug) {
    if (isset($reached[$slug])) continue;
    if (str_contains(strtoupper($tip), $spoken($slug))) $wronglyNamed[] = $slug;
}
tf($wronglyNamed === [],
   'and no skin it misses is named' . ($wronglyNamed === [] ? '' : ': ' . implode(', ', $wronglyNamed)));
tf(str_contains($tip, (string)count($reachedList)),
   'the tip states the count it reaches (' . count($reachedList) . ')');

echo "\nTHE TIP SAYS PLAINLY THAT THE OTHERS DO NOTHING, AND WHAT IS UNMEASURED\n";
tf(stripos($tip, 'nothing will happen') !== false,
   'it tells the owner nothing will happen on the rest');
tf(stripos($tip, 'masonry') !== false && stripos($tip, 'not been measured') !== false,
   'and it says the masonry case has not been measured, rather than implying it works');

echo "\nTHE SAVED CSS IS STILL THE CSS THIS WAS MEASURED AGAINST\n";
// If the selector list changes, the measurement above no longer describes the
// product and the wording has to be redone. Pinned so that cannot pass quietly.
tf(str_contains($admin, ".square-grid .thumb-link, .cropped-grid .thumb-link, "
   . ".fsog-archive-item .fsog-thumb, .rg-archive-item .rg-thumb"),
   'the grid selector list is unchanged');
tf(str_contains($admin, '#justified-grid .justified-item'),
   'and the masonry selector is unchanged');

echo "\nONE SELECTOR IN THAT LIST MATCHES NOTHING IN THE FLEET, RECORDED NOT REMOVED\n";
$emitsRg = false;
foreach (array_merge(glob($root . '/skins/*/layout.php') ?: [],
                     glob($root . '/core/*-page-component.php') ?: []) as $file) {
    if (str_contains((string)file_get_contents($file), 'rg-archive-item')) $emitsRg = true;
}
tf(!$emitsRg,
   'no layout or page component emits rg-archive-item, so that part of the rule '
   . 'is dormant -- kept deliberately so the border returns if RATIONAL GEO '
   . 'emits it again');

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Thumbnail frame honesty: FAIL ({$fail}).\n";
    exit(1);
}
echo "Thumbnail frame honesty: PASS ({$pass} assertions).\n";
echo "  NOT CHECKED: masonry on a live page, and whether the border LOOKS right\n";
echo "  on the five skins it reaches. This renders markup and reads the admin\n";
echo "  source; it drives no browser and it saves nothing through the form.\n";

// ===== SNAPSMACK EOF =====
