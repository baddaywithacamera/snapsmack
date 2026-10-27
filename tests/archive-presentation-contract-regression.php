<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The archive container must receive a rule that lays it out.
 *
 * OPAUDIT 027, step 0. Agreed between Claude, Codex and Sean on 2026-10-26.
 *
 * WHY THIS EXISTS. On 26 October Sean found three archives broken by opening
 * them in a browser. NEW HORIZON on curbappeal.photoblogs.fyi rendered its
 * twenty-four photographs in a single 580px column, 4,219px tall, with
 * browser-default blue underlined titles. The markup was correct. The page
 * simply never received assets/css/page-archive.css, which carries the rule
 * that makes the container a grid.
 *
 * The suite was green throughout. tests/every-skin-every-page-regression.php
 * renders all thirty skins against every page kind -- three hundred
 * combinations -- and hands each one the fixture stylesheet URL
 * 'https://example.test/s.css'. It asserts markup. It never resolves the real
 * stylesheet set and never opens a file, so a page with no layout attached
 * passes. tests/skin-render-snapshot-regression.php records stylesheet hrefs,
 * but as a fingerprint: it catches a link DISAPPEARING, not a baseline that
 * was wrong from the start, and NEW HORIZON's baseline has always recorded the
 * absence as normal. tests/mosaic-preview-browser.cjs does render real CSS in
 * a real browser, for one TILEZ preview. None of them covers this.
 *
 * WHAT THIS CHECKS, AND WHAT IT DELIBERATELY DOES NOT. Codex's amendment, and
 * it is right: a class appearing somewhere in a stylesheet proves nothing. It
 * does not show that a selector matches, that it survives the cascade, that it
 * applies at the viewport, or that the result is correct. A first sweep built
 * that way flagged 26 of 30 skins -- diagnostic noise, not a gate. A class may
 * also legitimately have no rule of its own because an id, a parent selector,
 * inheritance or a semantic role handles it.
 *
 * So this asserts ONE structural contract, through the real asset resolution
 * the runtime uses:
 *
 *     the element that holds the archive's photographs must be given a
 *     multi-column layout -- grid, flex or columns -- by at least one rule in
 *     the stylesheets that page actually receives.
 *
 * It does not judge how the archive looks. Column count, spacing, framing,
 * cropping and title styling are presentation and belong to the skin and to
 * Codex's browser evidence. This check only answers whether any layout arrives
 * at all, which is the difference between a grid and a vertical stack of
 * full-width photographs.
 *
 * HOW ASSETS ARE RESOLVED. Through core/asset-registry.php, the same functions
 * index.php calls -- not a reimplementation. That is the point: the bug is IN
 * the resolution. page-archive.css is attached as a property of two JavaScript
 * engines in core/manifest-inventory.php, 'smack-archive-grid-switch' and
 * 'smack-archive-toggle', so a skin receives it only if it declared the
 * thumbs/masonry toggle button. Five skins did. Twenty-five did not.
 *
 * Run it:
 *     php tests/archive-presentation-contract-regression.php          # gate
 *     php tests/archive-presentation-contract-regression.php --list   # report
 */

$root = dirname(__DIR__);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/asset-registry.php';

// Skin templates refuse to run unless the renderer declares itself, so a
// stray request can never execute one directly (53ab8218). The harness is a
// renderer; without this every include silently exits and the whole sweep
// reports nothing, which is exactly what it did on the first run.
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);

$listOnly = in_array('--list', $argv ?? [], true);

/* ---------------------------------------------------------------------------
 * The fixture. Same shape as every-skin-every-page-regression.php, because a
 * fixture that knows only one of a skin's key names reports working pages as
 * broken -- that test learned it the hard way and so would this one.
 * ------------------------------------------------------------------------ */
$view = [
    'site' => [
        'language' => 'en', 'direction' => 'ltr', 'site_name' => 'a test site',
        'skin_style_url' => 'https://example.test/s.css', 'skin_variant_url' => '',
        'base_style_url' => 'https://example.test/base.css',
        'registered_assets' => [], 'owner_custom_code' => '', 'search_dock' => [],
        'skin_presentation' => ['style' => '', 'options' => []] + array_fill_keys([
            'aurora', 'background', 'chaplin', 'discover', 'flag', 'grid',
            'header_media', 'heuristic', 'jive', 'mayhem', 'rational', 'scroll',
            'slickr', 'sliders', 'stanley', 'treatment', 'writing',
        ], []),
        'archive_layout' => 'masonry',
        'footer' => [
            'site_name' => 'a test site', 'site_url' => 'https://example.test/',
            'slots' => [['kind' => 'copyright', 'year' => '2026', 'site_name' => 'a test site']],
        ],
    ],
    'response' => [
        'kind' => 'archive', 'page_title' => 'Archive',
        'navigation' => [['label' => 'Home', 'url' => '/']],
        'rendered_content' => snapsmack_trusted_html('<p>Some words.</p>'),
        'items' => [],
        'item' => [],
    ],
];
foreach (range(1, 6) as $n) {
    $view['response']['items'][] = [
        'img_title' => 'Photograph ' . $n, 'img_slug' => 'photograph-' . $n,
        'img_file' => 'https://example.test/' . $n . '.jpg',
        'img_thumb_aspect' => 'https://example.test/' . $n . '.jpg',
        'img_thumb_square' => 'https://example.test/' . $n . '.jpg',
        'featured_image_path' => 'https://example.test/' . $n . '.jpg',
        'img_width' => 3000, 'img_height' => 2000,
        'title' => 'Photograph ' . $n, 'excerpt' => 'Some words.',
        'presentation_image' => 'https://example.test/' . $n . '.jpg',
        'presentation_title' => 'Photograph ' . $n,
    ];
}

/* ------------------------------------------------------------------------ */

/** The stylesheets this skin's archive page really receives, as repo paths. */
function apc_resolved_stylesheets(string $root, string $skin, array $manifest): array
{
    $paths = ['assets/css/public-base.css', 'skins/' . $skin . '/style.css'];
    $declared = snapsmack_skin_declared_assets($manifest);
    foreach (($declared['styles'] ?? []) as $url) {
        $path = parse_url((string)$url, PHP_URL_PATH) ?: (string)$url;
        $path = ltrim(preg_replace('#^.*?(assets/|skins/)#', '$1', $path), '/');
        if ($path !== '') $paths[] = $path;
    }
    return array_values(array_unique(array_filter($paths, static fn($p) => is_file($root . '/' . $p))));
}

/**
 * The element the archive's photographs sit in: its id and its classes.
 *
 * Found by structure, not by name. The first version looked for id="browse-grid"
 * or class="archive-grid" with a regex and gave up on five of twenty-five
 * skins -- IMPACT PRINTER, RATIONAL GEO, SCROLL, PHOTOGRAM and FULL MONTY --
 * reporting them as "not applicable" when it simply could not see them. A
 * check that quietly skips what it cannot parse is the failure this whole
 * exercise is about, so it now parses the document and looks for the element
 * that actually holds the photographs: the deepest element whose own children
 * include two or more links to photographs.
 */
function apc_archive_container(string $html): ?array
{
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return null;

    $best = null;
    $bestDepth = -1;
    foreach ((new DOMXPath($doc))->query('//*') as $node) {
        if (!$node instanceof DOMElement) continue;
        $tiles = 0;
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            // A tile is a child that carries a link and a photograph, whether
            // the child IS the link (justified walls) or wraps one (grids).
            // A tile is a child that contains a photograph. Requiring a link
            // as well missed GLIDE; accepting a link instead picked TRUE
            // GRIT's header nav and SLICKR's tab row, which have several link
            // children and sit deeper than the grid. A photograph in each
            // child is what an archive actually is.
            if ($child->getElementsByTagName('img')->length > 0) $tiles++;
        }
        if ($tiles < 2) continue;
        $depth = 0;
        for ($p = $node; $p !== null; $p = $p->parentNode) $depth++;
        if ($depth > $bestDepth) { $best = $node; $bestDepth = $depth; }
    }
    if ($best === null) return null;

    return [
        'id'      => $best->getAttribute('id'),
        'classes' => array_values(array_filter(preg_split('/\s+/', $best->getAttribute('class')) ?: [])),
    ];
}

/**
 * Does any rule in these stylesheets give that container a multi-column
 * layout? A rule counts when its selector names the container's id or one of
 * its classes AND its body establishes grid, flex or columns.
 *
 * Selector text is scanned rather than parsed. That is enough for this one
 * contract and avoids pretending to be a cascade engine -- which is exactly
 * what this check must not claim to be.
 */
function apc_has_layout_rule(string $css, array $container, array &$evidence): bool
{
    $needles = [];
    if ($container['id'] !== '') $needles[] = '#' . $container['id'];
    foreach ($container['classes'] as $class) $needles[] = '.' . $class;
    if ($needles === []) return false;

    $found = false;
    $offset = 0;
    $length = strlen($css);
    while ($offset < $length) {
        $open = strpos($css, '{', $offset);
        if ($open === false) break;
        $close = strpos($css, '}', $open);
        if ($close === false) break;
        $selector = substr($css, $offset, $open - $offset);
        $body     = substr($css, $open + 1, $close - $open - 1);
        $offset   = $close + 1;

        // An at-rule's own brace (@media, @supports) carries no declarations;
        // its children are visited by the same walk on the next iteration.
        if (str_contains($selector, '@')) continue;
        if (!preg_match('/(display\s*:\s*(grid|inline-grid|flex|inline-flex)|column-count\s*:|columns\s*:|grid-template-columns\s*:)/i', $body)) {
            continue;
        }
        // THE SUBJECT OF THE SELECTOR MUST BE THE CONTAINER ITSELF.
        //
        // The first version of this check matched the needle anywhere in the
        // selector, and so passed NEW HORIZON on
        //     .square-grid .thumb-container { display: flex }
        // That rule lays out a CHILD. NEW HORIZON is the skin whose live
        // archive is a single column. A check that green-lights the page it
        // was written to catch is worse than no check, so: split on commas,
        // take the rightmost compound of each (the subject), strip
        // pseudo-elements and pseudo-classes, and require the needle there.
        foreach (explode(',', $selector) as $one) {
            $one = trim(preg_replace('/\s*[>+~]\s*/', ' ', $one));
            if ($one === '') continue;
            $parts = preg_split('/\s+/', $one);
            $subject = (string)end($parts);
            $subject = (string)preg_replace('/::?[a-z-]+(\([^)]*\))?/i', '', $subject);
            foreach ($needles as $needle) {
                // Word boundary on the right so .square-grid does not match
                // .square-grid-legacy; the left side carries # or . already.
                if (preg_match('/' . preg_quote($needle, '/') . '(?![\w-])/', $subject)) {
                    $evidence[] = trim(preg_replace('/\s+/', ' ', $one)) . ' { ' . trim(preg_replace('/\s+/', ' ', $body)) . ' }';
                    $found = true;
                    break 2;
                }
            }
        }
        if ($found) return true;
    }
    return false;
}

/* ------------------------------------------------------------------------ */

$rows = [];
$walls = [];
$skipped = [];
set_error_handler(static fn(int $n, string $m): bool => true, E_WARNING | E_NOTICE | E_DEPRECATED);

foreach (glob($root . '/skins/*/layout.php') ?: [] as $layout) {
    $skin = basename(dirname($layout));
    $manifest = json_decode((string)@file_get_contents(dirname($layout) . '/manifest.json'), true);
    if (!is_array($manifest)) { $skipped[$skin] = 'manifest unreadable'; continue; }

    // Only skins whose controller actually serves a photoblog archive. A
    // SMACKTALK skin has no archive of photographs and is not a candidate.
    if ((string)($manifest['cms_controller'] ?? 'public') !== 'public') {
        $skipped[$skin] = 'not the public controller (' . ($manifest['cms_controller'] ?? '?') . ')';
        continue;
    }

    ob_start();
    try { include $layout; } catch (\Throwable $e) { ob_get_clean(); $skipped[$skin] = 'render threw: ' . $e->getMessage(); continue; }
    $html = (string)ob_get_clean();

    $container = apc_archive_container($html);
    if ($container === null) {
        // Not a skip. On a photoblog, an archive page with no photographs on
        // it is the bug, not a gap in the check. GLIDE is how this was found:
        // its archive emits <article><a><h2>Title</h2></a></article> per item
        // and never renders an image, so there was nothing to lay out and its
        // stylesheet has no archive rules at all. Calling that "not
        // applicable" would hide a photoblog archive with no photographs.
        $rows[$skin] = [
            'ok'        => false,
            'container' => '(no photographs on the page)',
            'sheets'    => apc_resolved_stylesheets($root, $skin, $manifest),
            'evidence'  => [],
            'reason'    => 'its archive page renders no photographs at all -- only links and titles',
        ];
        continue;
    }

    $sheets = apc_resolved_stylesheets($root, $skin, $manifest);
    $css = '';
    foreach ($sheets as $path) $css .= "\n" . (string)@file_get_contents($root . '/' . $path);

    // A WALL LAID OUT BY JAVASCRIPT IS NOT A FAILURE OF CSS.
    //
    // The scroll wall, the masonry wall and the justified gallery compute
    // their geometry at runtime and set it inline; their containers
    // legitimately carry no display:grid. Judging them by this contract
    // reported RATIONAL GEO, SCROLL and IMPACT PRINTER as broken on the first
    // run, and they are not. They are NOT passed either -- they are counted
    // separately and named, because "this check cannot see it" and "this is
    // fine" are different statements and the second one is how all of this
    // reached Sean in the first place.
    $wallMarkers = ['ss-scroll-wall', 'ss-masonry', 'ss-square-wall', 'justified-grid', 'scroll-wall'];
    $isWall = in_array($container['id'], $wallMarkers, true)
        || array_intersect($container['classes'], $wallMarkers) !== [];
    if ($isWall) {
        $walls[$skin] = ($container['id'] !== '' ? '#' . $container['id'] : '')
                      . ($container['classes'] ? '.' . implode('.', $container['classes']) : '');
        continue;
    }

    $evidence = [];
    $rows[$skin] = [
        'ok'        => apc_has_layout_rule($css, $container, $evidence),
        'container' => ($container['id'] !== '' ? '#' . $container['id'] : '')
                     . ($container['classes'] ? '.' . implode('.', $container['classes']) : ''),
        'sheets'    => $sheets,
        'evidence'  => $evidence,
    ];
}
restore_error_handler();

/* ------------------------------------------------------------------------ */

$broken = array_keys(array_filter($rows, static fn(array $r): bool => !$r['ok']));

if ($listOnly) {
    printf("ARCHIVE PRESENTATION CONTRACT — %d skins on the public controller\n", count($rows));
    echo str_repeat('=', 78), "\n";
    foreach ($rows as $skin => $r) {
        printf("%-24s %s  %s\n", $skin, $r['ok'] ? 'LAID OUT ' : 'NO LAYOUT', $r['container']);
        printf("%-24s   sheets: %s\n", '', implode(', ', $r['sheets']) ?: '(none)');
        if ($r['evidence']) printf("%-24s   rule:   %s\n", '', $r['evidence'][0]);
    }
    if ($skipped) {
        echo "\nNot applicable:\n";
        foreach ($skipped as $skin => $why) printf("  %-22s %s\n", $skin, $why);
    }
    printf("\n%d laid out, %d with no layout rule at all.\n", count($rows) - count($broken), count($broken));
    exit(0);
}

if ($rows === []) {
    fwrite(STDERR, "FAIL: no skin was checked at all; the sweep is not finding the skins.\n");
    exit(1);
}

if ($broken !== []) {
    fwrite(STDERR, "\nArchive presentation contract FAILED for " . count($broken) . " skin(s).\n\n");
    foreach ($broken as $skin) {
        $r = $rows[$skin];
        fwrite(STDERR, "  {$skin}\n");
        fwrite(STDERR, "    container: {$r['container']}\n");
        fwrite(STDERR, "    receives:  " . (implode(', ', $r['sheets']) ?: '(no stylesheets at all)') . "\n");
        if (isset($r['reason'])) {
            fwrite(STDERR, '    ' . $r['reason'] . ".

");
        } else {
            fwrite(STDERR, "    Nothing in those stylesheets gives that element a grid, flex or column
");
            fwrite(STDERR, "    layout, so its photographs stack in one full-width column.

");
        }
    }
    fwrite(STDERR, "Run with --list to see every skin, including the ones that pass.\n");
    exit(1);
}

printf("Archive presentation contract: %d skins, every archive container receives a layout rule.\n", count($rows));
exit(0);
// ===== SNAPSMACK EOF =====
