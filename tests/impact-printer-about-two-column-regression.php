<?php
declare(strict_types=1);

/**
 * IMPACT PRINTER's About page puts the portrait BESIDE the words.
 *
 * Sean reported the About page as one column, portrait on top and text
 * underneath, and asked what had happened to it. Nothing had. It was never
 * there.
 *
 * The skin DID float the portrait left. Verified for THIS skin, not inherited
 * from the NEW HORIZON repair it was found alongside: `float: left` entered
 * skins/impact-printer/style.css at 71780ef0 (0.7.159, 2026-05-20), and no
 * version of that rule has ever set a numeric width on it -- checked by
 * extracting the rule from every commit that has touched this stylesheet.
 *
 * A shrink-to-fit float against a large photograph takes the whole column.
 * Measured by rendering IMPACT PRINTER's own layout with a 2655x2655 portrait,
 * at 1920:
 *
 *     container             850px
 *     floated portrait     2655px   <- the image's full natural size
 *     overflow                       1805px past the container
 *     text line boxes          16
 *     LINE BOXES BESIDE IT    0 of 16
 *
 * AND IT WAS CLIPPED, NOT MERELY WIDE. .static-content carries
 * overflow-x: hidden, so the right ~1800px of the portrait was cut off rather
 * than scrolled to. Nobody was seeing the whole photograph. That is the second
 * symptom of the same cause, and on a photographer's site it is the worse one.
 *
 * AFTER, same harness, same portrait:
 *
 *     width  display  container  portrait  text starts  lines beside
 *     1920   grid     1100px     445px     right of it  14 of 22
 *     1440   grid     1100px     445px     right of it  14 of 22
 *     1024   grid      899px     361px     right of it  12 of 28
 *      880   block     755px     715px     below it      0 (collapsed, correct)
 *
 * 390px was NOT measured for this skin. IMPACT PRINTER is a desktop and
 * large-tablet skin and 880 already proves the collapse; recording a width I
 * did not measure would be the same fault this docblock was corrected for.
 *
 * The heading's 24px offset from the portrait's left edge held at every width
 * above, before and after. No horizontal overflow at any, and the portrait now
 * fits its track instead of being cropped by the container.
 *
 * SS-1269 IS NOT THIS SKIN'S TICKET. It aligned NEW HORIZON's About heading;
 * no commit for it has ever touched skins/impact-printer. This skin simply has
 * the same 24px left padding on .static-page-title, and the grid preserves it
 * for the same reason -- the portrait still starts at the container's content
 * edge, so the same 24px lands in the same place.
 *
 * THIS BLOCK WAS WRONG WHEN FIRST COMMITTED AND MUSE CAUGHT IT. The test was
 * derived from tests/new-horizon-about-two-column-regression.php, and the
 * derivation renamed the identifiers but carried NEW HORIZON's measurements,
 * NEW HORIZON's live page, and NEW HORIZON's ticket history into a file about
 * a different skin. Every assertion below was correct; the record above them
 * described the wrong skin, which in this repository is the part that gets
 * read later and believed.
 *
 * WHAT THIS TEST CAN AND CANNOT DO. The numbers above need a browser and this
 * repository has no resolvable puppeteer or playwright, so they are recorded
 * here and in the commit rather than re-run. What runs here is the two halves
 * that CAN be checked without one, and that between them are what make those
 * numbers true:
 *
 *   1. the RENDERED MARKUP contract the selectors depend on -- IMPACT PRINTER's
 *      own layout.php is executed and the result inspected, because
 *      `.static-content > #photobox.page-hero` is a CHILD combinator and a
 *      wrapper introduced anywhere in that chain would silently return the
 *      page to one column with every CSS assertion still green;
 *   2. the STYLESHEET structure -- the grid exists, is scoped to a page that
 *      has a portrait, places the three children in the right tracks, keeps
 *      the heading spanning both, removes the float that caused this, and
 *      collapses on a narrow screen.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root  = dirname(__DIR__);
$fails = 0;

function ip(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}

// ── PART 1: the rendered markup ────────────────────────────────────────────
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';

$body = SnapTrustedHtml::__snapsmackCmsOnly('<p>A paragraph of the About text.</p><p>And another.</p>');
$response = [
    'status' => 200,
    'kind'   => 'page',
    'item'   => [
        'id' => 1, 'title' => 'About Sean', 'page_title' => 'About Sean', 'slug' => 'about',
        'content' => $body, 'description' => $body,
        'image_asset' => 'media_assets/portrait.jpg',
        'image_size' => 'full', 'image_align' => 'center',
    ],
    'page_title' => 'About Sean',
    'rendered_content' => $body,
    'navigation' => [],
];
$site = [
    'site_name' => 'curb appeal', 'language' => 'en', 'direction' => 'ltr',
    'skin_style_url' => '/skins/impact-printer/style.css',
    'skin_presentation' => snapsmack_skin_presentation([], 'impact-printer'),
    'footer' => [],
];

$html = '';
try {
    $view = snapsmack_build_skin_view($response, $site);
    ob_start();
    snapsmack_render_strict_skin_template($root . '/skins/impact-printer', 'layout.php', $view);
    $html = (string) ob_get_clean();
} catch (\Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    ip('IMPACT PRINTER renders a static page: ' . $e->getMessage(), false);
}

ip('IMPACT PRINTER renders a static page', $html !== '');
if ($html === '') {
    fwrite(STDERR, "\nCould not render the skin — the rest cannot be judged.\n");
    exit(1);
}

$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
libxml_clear_errors();
$xp = new DOMXPath($doc);

$sc = $xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " static-content ")]');
ip('the page has a .static-content container', $sc->length === 1);

if ($sc->length === 1) {
    $container = $sc->item(0);
    // DIRECT children only. This is the assertion that protects the selector:
    // a wrapper added around the hero or the text would leave every rule below
    // matching nothing, and the page would quietly stack again.
    $kids = [];
    foreach ($container->childNodes as $n) {
        if ($n->nodeType === XML_ELEMENT_NODE) {
            $kids[] = strtolower($n->nodeName) . '.' . trim((string) $n->getAttribute('class'));
        }
    }
    $classOf = static fn(string $k): string => explode('.', $k, 2)[1] ?? '';

    ip(
        'the heading is a DIRECT child of .static-content',
        (bool) array_filter($kids, static fn($k) => str_contains($classOf($k), 'static-page-title'))
    );
    ip(
        'the portrait box is a DIRECT child of .static-content',
        (bool) array_filter($kids, static fn($k) => str_contains($classOf($k), 'page-hero'))
    );
    ip(
        'the text is a DIRECT child of .static-content',
        (bool) array_filter($kids, static fn($k) => str_contains($classOf($k), 'description'))
    );

    $hero = $xp->query('.//*[@id="photobox"][contains(concat(" ", normalize-space(@class), " "), " page-hero ")]', $container);
    ip('the portrait box is #photobox.page-hero, which the grid selects on', $hero->length === 1);
    ip(
        'the portrait box actually holds an image',
        $hero->length === 1 && $xp->query('.//img', $hero->item(0))->length >= 1
    );
}

// A page with no portrait must still render, and must NOT grow an empty track.
$noHero = $response;
$noHero['item']['image_asset'] = '';
try {
    $view2 = snapsmack_build_skin_view($noHero, $site);
    ob_start();
    snapsmack_render_strict_skin_template($root . '/skins/impact-printer', 'layout.php', $view2);
    $html2 = (string) ob_get_clean();
} catch (\Throwable $e) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    $html2 = '';
}
ip('a static page with no portrait still renders', $html2 !== '');
ip(
    'and emits no #photobox.page-hero, so the grid never engages on it',
    $html2 !== '' && !preg_match('/id="photobox"[^>]*page-hero/', $html2)
);

// ── PART 2: the stylesheet ─────────────────────────────────────────────────
$css_raw = (string) @file_get_contents($root . '/skins/impact-printer/style.css');
ip('the IMPACT PRINTER stylesheet is readable', $css_raw !== '');
if ($css_raw === '') { fwrite(STDERR, "\nStylesheet missing.\n"); exit(1); }

// Strip comments before anything is matched. The stylesheet now explains, in
// prose, the float it removed — and the first run of this test FAILED on that
// explanation, reading "WAS float: left !important" as a live declaration. A
// commented-out rule must not count as a rule either, so the comments come out
// once, here, rather than being worked around at each assertion.
$css = (string) preg_replace('#/\*.*?\*/#s', ' ', $css_raw);

/** One declaration block by its exact selector. */
function ip_block(string $css, string $selector): string {
    $at = strpos($css, $selector . ' {');
    if ($at === false) return '';
    $open = strpos($css, '{', $at);
    $end  = strpos($css, '}', $open);
    return ($open === false || $end === false) ? '' : substr($css, $open + 1, $end - $open - 1);
}

$SCOPE = '.static-content:has(#photobox.page-hero)';

$grid = ip_block($css, $SCOPE);
ip('the two-column rule exists', $grid !== '');
ip(
    'it is a grid',
    (bool) preg_match('/display:\s*var\(--ip-static-layout,\s*grid\)|display:\s*grid/', $grid)
);
ip(
    'it declares two tracks: the portrait and the rest',
    (bool) preg_match('/grid-template-columns:[^;]*1fr/s', $grid)
);
ip(
    'the portrait width is a named property, not a buried number',
    str_contains($grid, '--ip-static-hero-width')
);

// THE SCOPE IS THE POINT. Without :has(), the blogroll and every static page
// with no portrait would open an empty column.
ip(
    'the grid is scoped to a page that HAS a portrait',
    str_contains($css, $SCOPE)
);
ip(
    'the bare .static-content rule did NOT become a grid',
    !preg_match('/display:\s*grid/', ip_block($css, '.static-content'))
);

// Placement of the three children.
$title = ip_block($css, $SCOPE . ' > .static-page-title');
$heroR = ip_block($css, $SCOPE . ' > #photobox.page-hero');
$descR = ip_block($css, $SCOPE . ' > .description');

ip('the heading spans both columns', (bool) preg_match('/grid-column:\s*1\s*\/\s*-1/', $title));
ip('the heading keeps no 850px cap that would cut it short', str_contains($title, 'max-width: none'));
ip('the portrait sits in its own column', str_contains($heroR, 'grid-column'));
ip('the text sits in the other column', str_contains($descR, 'grid-column'));

// THE FAULT ITSELF: the float must be gone. It is what produced one column.
$heroBase = ip_block($css, '.static-transmission #photobox.page-hero');
ip('the static hero rule still exists', $heroBase !== '');
ip(
    'the float that caused this is gone from the static hero',
    $heroBase !== '' && !preg_match('/float:\s*left/', $heroBase)
);
ip(
    'and it is explicitly none, not merely absent',
    (bool) preg_match('/float:\s*none/', $heroBase)
);
ip(
    'the float is also neutralised inside the grid',
    (bool) preg_match('/float:\s*none/', $heroR)
);

// The bio image's own float is a DIFFERENT thing and must survive.
$bio = ip_block($css, '.static-transmission .description .align-left');
ip(
    'the inline bio image keeps its own float (different feature, untouched)',
    (bool) preg_match('/float:\s*left/', $bio) && (bool) preg_match('/max-width:\s*40%/', $bio)
);

// Narrow screens collapse to one column.
ip(
    'a narrow screen collapses it to one column',
    (bool) preg_match('/@media\s*\(max-width:\s*900px\)\s*\{[^@]*' . preg_quote($SCOPE, '/') . '\s*\{[^}]*display:\s*block/s', $css)
);

// ── The version must move or no site receives this ─────────────────────────
$manifest = json_decode((string) @file_get_contents($root . '/skins/impact-printer/manifest.json'), true);
$version  = is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';
ip('the IMPACT PRINTER manifest carries a version', $version !== '');
ip(
    'it is past 2.1.25, the version that shipped the single column',
    $version !== '' && version_compare($version, '2.1.25', '>')
);

if ($fails) {
    fwrite(STDERR, "\n{$fails} FAILURE(S): IMPACT PRINTER's About page no longer puts the portrait "
        . "beside the words. Either the grid is gone, or the markup it selects on has changed "
        . "shape, or the float is back — and the float is what made the page one column for "
        . "six months.\n");
    exit(1);
}
echo "IMPACT PRINTER About two-column: PASS\n";
exit(0);

// ===== SNAPSMACK EOF =====
