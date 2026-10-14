<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A blogroll is a list of other people's sites, not an empty photo wall.
 *
 * None of the four skin renderers in core/ had a branch for a blogroll, so a
 * blogroll request fell through to the archive branch. Measured live on
 * https://photowalk.ing/blogroll, the whole visible text of the page was:
 *
 *     photowalk.ing HOME ARCHIVE VIEW BLOGROLL ABOUT SEAN [ SHOW ALL ] / FILTER
 *
 * -- the archive's filter row, a #justified-grid 1650x936 with ZERO children,
 * no blogroll at all, and a footer at display:none because the body carried
 * neither the static-transmission nor the archive-page class the skin needs.
 *
 * TILEZ, GAME ON and SLICKR each hand-rolled the same loop in their own
 * layout. It is one shared component now, so a fix reaches every caller.
 * Those three keep their own copies for the moment: they work, and replacing
 * them means three skin releases for no visible change.
 */

if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');
if (!defined('SNAPSMACK_VERSION')) define('SNAPSMACK_VERSION', 'Alpha 0.0.0D');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.0.0D');

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$groups = [
    ['label' => 'Photographers', 'items' => [
        ['name' => 'A Bad Day With A Camera', 'url' => 'https://baddaywithacamera.ca',
         'description' => 'Rust, ruin & bad weather.'],
        ['name' => 'Found Textures', 'url' => 'https://foundtextures.ca'],
    ]],
    ['label' => 'Nothing here', 'items' => []],
];

// --- the shared component ------------------------------------------------
$html = (string)snap_render_component('blogroll', ['groups' => $groups]);
// The class names are a CONTRACT. Every skin styles these, and five manifest
// controls target them -- Column Layout (.blogroll-grid), Content Max Width
// (.blogroll-canvas), Column Gutter (.blogroll-grid), Show Peer Description
// (.blogroll-peer-desc) and Show Peer URL (.blogroll-peer-url). Invent a
// different name and all five silently do nothing, which is exactly how the
// About page lost the photograph frame its owner had chosen.
foreach (['blogroll-category-block', 'blogroll-category-heading', 'blogroll-grid',
          'blogroll-peer', 'blogroll-peer-name', 'blogroll-peer-desc',
          'blogroll-peer-url'] as $contract) {
    $check(str_contains($html, 'class="' . $contract . '"') || str_contains($html, 'class="' . $contract . ' '),
        'the blogroll must emit .' . $contract . '; a skin control targets it');
}
$check(substr_count($html, 'blogroll-category-block') === 1,
    'a group with no entries must not render an empty heading');
$check(str_contains($html, '>A Bad Day With A Camera</a>'), 'a peer is a link by name');
$check(str_contains($html, 'href="https://baddaywithacamera.ca"'), 'the peer url must survive');
$check(str_contains($html, 'rel="noopener noreferrer"'), 'outbound links must not leak the opener');
$check(str_contains($html, 'Rust, ruin &amp; bad weather.'), 'the note must be escaped');
$check(!str_contains($html, 'blogroll-peer-desc"></p>'), 'no empty note paragraph when there is no note');
// The heading is uppercased by the stylesheet, so the CMS must not shout too.
$check(str_contains($html, '>Photographers</h2>'),
    'the CMS must not uppercase the heading; text-transform belongs to the skin');

$nasty = (string)snap_render_component('blogroll', ['groups' => [
    ['label' => '<script>x</script>', 'items' => [
        ['name' => 'script scheme', 'url' => 'javascript:alert(1)'],
        ['name' => 'no url at all'],
        ['name' => '<b>bold</b>', 'url' => 'https://ok.test', 'description' => '<i>y</i>'],
    ]]]]);
$check(!str_contains($nasty, '<script>x'), 'a group label must be escaped');
$check(!str_contains($nasty, 'javascript:'), 'a script-scheme link must be dropped entirely');
$check(!str_contains($nasty, 'no url at all'), 'an entry with no url must be dropped');
$check(!str_contains($nasty, '<b>bold</b>') && !str_contains($nasty, '<i>y</i>'),
    'names and notes must be escaped');

// --- every strict renderer must answer a blogroll ------------------------
foreach (['50-shades', 'chaplin', 'galleria', 'impact-printer'] as $slug) {
    $src = (string)@file_get_contents($root . '/core/' . $slug . '-page-component.php');
    $check(str_contains($src, "=== 'blogroll'"),
        $slug . ' must have a blogroll branch, or a blogroll renders as an empty archive');
    $check(str_contains($src, "snap_render_component('blogroll'"),
        $slug . ' must render the shared blogroll, not its own copy of the loop');
}

// --- and 50 Shades must render it as a PAGE ------------------------------
$view = [
    'site' => ['language' => 'en', 'direction' => 'ltr', 'site_name' => 'photowalk.ing',
               'skin_style_url' => 'https://example.test/s.css',
               'footer' => ['site_name' => 'photowalk.ing', 'slots' => []]],
    'response' => ['kind' => 'blogroll', 'page_title' => 'Blogroll',
                   'navigation' => [], 'blogroll_groups' => $groups],
];
ob_start();
include $root . '/core/50-shades-page-component.php';
$page = (string)ob_get_clean();

$check(str_contains($page, 'static-transmission'),
    'the body must carry the page class, or the skin hides the footer outright');
$check(str_contains($page, 'blogroll-grid'), 'the blogroll must actually render');
$check(str_contains($page, 'baddaywithacamera.ca'), 'the peers must reach the page');
foreach (['archive-filter-row' => "the archive's control row",
          'justified-grid'     => 'the photo wall',
          'browse-grid'        => 'the thumbnail grid'] as $needle => $what) {
    $check(!str_contains($page, $needle), 'a blogroll must not render ' . $what);
}
$check(str_contains($page, 'footer-metadata-bar'), 'a blogroll still gets the footer');

// --- the list pages must not hold a photograph column open ---------------
$css = (string)@file_get_contents($root . '/skins/50-shades-of-noah-grey/style.css');
$check(str_contains($css, '.static-content--list'),
    'a list page must collapse the two-column layout; there is no photograph to sit beside');
// The skin has styled .blogroll-* all along -- it was the markup that went
// missing, not the presentation. Nothing here may be reinvented beside it.
foreach (['.blogroll-canvas', '.blogroll-category-heading', '.blogroll-peer-desc',
          '.blogroll-peer-url'] as $rule) {
    $check(str_contains($css, $rule), 'the skin must keep styling ' . $rule);
}
$check(!str_contains($css, '.blogroll-group'),
    'no second set of invented blogroll classes beside the real ones');
$check(str_contains($page, 'class="blogroll-canvas"'),
    'the renderer must use the canvas the Content Max Width control targets');

if ($failures) {
    fwrite(STDERR, "Blogroll page regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Blogroll page regression passed.\n";

// ===== SNAPSMACK EOF =====
