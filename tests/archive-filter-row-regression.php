<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The archive's control row, and the content column it docks to.
 *
 * SHOW ALL, the FILTER panel, the search field and the layout toggles lived
 * inside archive.php. When strict skins stopped rendering through that file
 * the entire row vanished from their archives: photowalk.ing served no
 * <select> and no <input> at all, while ss-engine-archive-grid-switch.js
 * stayed loaded with nothing to bind to.
 *
 * It is rendered here as one shared component, from the bounded filter data
 * the view model already prepares, so every skin with an archive gets it.
 * It carries NO navigation: a skin's header and menus are its own.
 *
 * The docked controls used to escape the content width. page-archive.css
 * falls back to right:40px measured from whatever is positioned above them,
 * corrected at runtime by ss-engine-archive-toggle.js -- which a skin that
 * does not load that engine never receives. Measured in a harness built from
 * the live page at a 1900px viewport, with the inner column present: the row
 * stays full-bleed at 1885px, its inner column measures 1650px -- identical
 * to the wall -- and the controls' right edge lands 40px inside the wall's
 * right edge instead of out at the window.
 */

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$bar = (string)snap_render_component('archive-filters', [
    'content_width' => '1650px',
    'show_all_url'  => 'https://example.test/images',
    'query'         => '',
    'selected'      => ['cat:3'],
    'filters'       => [
        ['label' => 'categories', 'type' => 'cat',
         'items' => [['id' => 3, 'label' => 'Rust & Ruin'], ['id' => 4, 'label' => 'Birds']]],
        ['label' => 'albums', 'type' => 'alb', 'items' => [['id' => 9, 'label' => 'Strathmore']]],
    ],
    'search'  => ['action' => 'https://example.test/', 'placeholder' => 'Search or #tag…'],
    'layouts' => [
        ['url' => 'https://example.test/images?l=t', 'label' => 'T', 'active' => false],
        ['url' => 'https://example.test/images?l=m', 'label' => 'M', 'active' => true],
    ],
]);

// --- every part of the known-good row is present -------------------------
foreach ([
    '[ SHOW ALL ]'                   => 'the show-all link',
    'id="smack-archive-filter-btn"'  => 'the FILTER button the filter engine binds to',
    'id="smack-archive-filter-panel"'=> 'the filter panel',
    'class="saf-search"'             => 'the filter search field',
    'saf-group-header'               => 'the taxonomy group headers',
    'name="cat[]"'                   => 'category checkboxes',
    'name="alb[]"'                   => 'album checkboxes',
    'class="archive-search-input"'   => 'the archive search input',
    'archive-layout-toggle'          => 'the layout toggle group',
    'alt-btn--active'                => 'the active layout marked',
    '1 SELECTED'                     => 'the selected-filter count',
] as $needle => $what) {
    $check(str_contains($bar, $needle), 'the row must contain ' . $what);
}

// --- it must bring no navigation with it ---------------------------------
foreach (['<nav', 'ss-grid-nav', 'scroll-sticky-nav', 'nav-menu'] as $needle) {
    $check(!str_contains($bar, $needle),
        'the row must not carry navigation (' . $needle . '); a skin owns its header');
}

// --- escaping ------------------------------------------------------------
$check(str_contains($bar, 'RUST &amp; RUIN'), 'labels must be escaped');
$check(!str_contains($bar, '<script'), 'the row must never emit script');
$nasty = (string)snap_render_component('archive-filters', [
    'show_all_url' => 'javascript:alert(1)',
    'search' => ['action' => 'https://example.test/', 'placeholder' => '"><script>x</script>'],
    'filters' => [['label' => '<b>x</b>', 'type' => 'cat', 'items' => [['id' => '1"><x', 'label' => '<i>y</i>']]]],
]);
$check(!str_contains($nasty, 'javascript:'), 'an unsafe show-all url must be dropped');
$check(!str_contains($nasty, '<script>x'), 'an unsafe placeholder must be escaped');
$check(!str_contains($nasty, '<i>y</i>'), 'unsafe labels must be escaped');

// --- the content column, which is what fixes the overflow ----------------
$check(str_contains($bar, 'class="archive-filter-inner"'),
    'the row needs an inner column for the controls to dock to');
$check(str_contains($bar, '--archive-content-width:1650px'),
    'the site content width must reach the row');
foreach (['999999px', 'javascript:x', '40', 'calc(100% - 2px)'] as $bad) {
    $guarded = (string)snap_render_component('archive-filters', ['content_width' => $bad]);
    $check(!str_contains($guarded, '--archive-content-width'),
        'a content width of "' . $bad . '" must be refused');
}

// --- the stylesheet must actually define that column ---------------------
$css = (string)@file_get_contents($root . '/assets/css/page-archive.css');
$check(str_contains($css, '.archive-filter-row .archive-filter-inner'),
    'page-archive.css must define the inner content column');
$check(str_contains($css, '.archive-filter-row .archive-controls--docked'),
    'page-archive.css must dock the controls to that column, not the window');
$check(preg_match('/\.archive-filter-row \.archive-filter-inner\s*\{[^}]*width:\s*100%/', $css) === 1,
    'the inner column must fill the row; without it it shrink-wraps and the controls dock mid-bar');

// --- and the skin renderer must actually call it -------------------------
$renderer = (string)@file_get_contents($root . '/core/50-shades-page-component.php');
$check(str_contains($renderer, "snap_render_component('archive-filters'"),
    'the 50 Shades archive must render the control row');
$check(!preg_match("/'show_all_url'\s*=>\s*snap_route_url\('archive'\)/", $renderer),
    "show_all_url must not use snap_route_url('archive'): that is the unregistered path that loops");
$check(str_contains($renderer, 'snapsmack_legacy_archive_target('),
    'show_all_url must come from the guarded archive-target helper');

if ($failures) {
    fwrite(STDERR, "Archive filter row regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Archive filter row regression passed.\n";

// ===== SNAPSMACK EOF =====
