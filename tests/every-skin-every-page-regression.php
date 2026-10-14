<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Every skin, every page kind, rendered -- not reasoned about.
 *
 * On 2026-09-28 one commit converted the first photoblogs to the strict
 * runtime. It dropped whole page kinds and an ordering, and because an empty
 * page still answers HTTP 200, nothing anywhere complained. Over the sixteen
 * days that followed Sean found the pieces one at a time, on his own sites:
 * static pages wearing the photograph layout, the archive's control row gone,
 * a blogroll that was an empty photo wall, a footer cut to one line on twelve
 * sites, his own appearance settings discarded as invalid CSS, and a visitor
 * counter that never ran. Six symptoms, one cause, and no test asked the one
 * question that would have caught four of them on the first day:
 *
 *     does every page kind a site can route to actually render something,
 *     its OWN something, with the furniture a page is supposed to have?
 *
 * It asks that here, for all 30 skins across 8 public kinds. It RENDERS them.
 * It does not read them for strings and hope.
 *
 * It is also the answer to "should a skin own its presentation". Four skins
 * are drawn by renderers in core/ and twenty-six draw themselves, and a
 * change to either can break the other's pages. Distance does not protect
 * them -- it only decides whether the breakage lands in one place or
 * twenty-six. This does.
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

/**
 * KNOWN BROKEN, found by this test on 2026-10-14 and not yet repaired.
 *
 * A static page on these skins renders with none of its own words -- the same
 * fault 50 Shades had, where 'page' shares the photograph branch and the
 * page's text is never emitted. NEW HORIZON does show its words, but serves
 * them under the photograph's technical-specifications table.
 *
 * They are listed so the suite stays honest instead of red: a NEW failure
 * still fails. Each line goes as its skin is repaired, and the list may only
 * get shorter -- a skin that starts passing while still listed fails too, so
 * this cannot quietly become somewhere to hide things.
 */
$KNOWN_BROKEN = [
];
$seen_broken = [];

/** PHOTOGRAM hides #system-footer in its own stylesheet, with the reason. */
$NO_FOOTER = ['photogram' => true];

$view_for = static function (string $kind): array {
    return [
        'site' => [
            'language' => 'en', 'direction' => 'ltr', 'site_name' => 'a test site',
            'skin_style_url' => 'https://example.test/s.css', 'skin_variant_url' => '',
            'registered_assets' => [], 'owner_custom_code' => '', 'search_dock' => [],
            // Every per-skin presentation key a layout reads. In production the
            // dispatcher sets the one belonging to the active skin; here they are
            // all present and empty so the harness exercises the layout rather
            // than reporting its own missing fixture as a fault.
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
            'kind' => $kind, 'page_title' => 'A Page', 'navigation' => [['label' => 'Home', 'url' => '/']],
            // Every alias a skin may read. Skins are not consistent about
            // this and a fixture that knows only one name reports working
            // pages as broken -- which is how this test first "found"
            // sixty faults that were its own.
            'rendered_content' => snapsmack_trusted_html('<p>Some words.</p>'),
            'items' => [[
                'img_title' => 'A photograph', 'img_slug' => 'a-photograph',
                'img_file' => 'https://example.test/a.jpg',
                'img_thumb_aspect' => 'https://example.test/a.jpg',
                'img_thumb_square' => 'https://example.test/a.jpg',
                'featured_image_path' => 'https://example.test/a.jpg',
                'img_width' => 3000, 'img_height' => 2000,
                'excerpt' => 'Some words.', 'title' => 'A photograph',
                'presentation_image' => 'https://example.test/a.jpg',
                'presentation_title' => 'A photograph',
            ]],
            'item' => [
                'img_title' => 'A photograph', 'title' => 'A page',
                'img_file' => 'https://example.test/a.jpg',
                'featured_image_path' => 'https://example.test/a.jpg',
                'img_thumb_aspect' => 'https://example.test/a.jpg',
                'content' => snapsmack_trusted_html('<p>Some words.</p>'),
                'description' => snapsmack_trusted_html('<p>Some words.</p>'),
                'img_description' => snapsmack_trusted_html('<p>Some words.</p>'),
                'excerpt' => 'Some words.',
                'presentation_image' => 'https://example.test/a.jpg',
                'presentation_title' => 'A photograph',
            ],
            'results' => [], 'comments' => [],
            'blogroll_groups' => [['label' => 'Photographers', 'items' => [
                ['name' => 'A Bad Day', 'url' => 'https://baddaywithacamera.test',
                 'description' => 'Rust and ruin.'],
            ]]],
        ],
    ];
};

// The kinds each controller ACTUALLY emits, read from the controllers
// themselves -- a public skin never receives 'single', and a SMACKTALK skin
// never receives 'photo', so asserting the whole union would invent failures
// rather than find them. Value = a string the page must contain, or null
// where a skin may legitimately render anything.
$KINDS = [
    'public' => [
        'landing'     => null,
        'photo'       => 'example.test/a.jpg',
        'post'        => 'example.test/a.jpg',
        'page'        => 'Some words.',
        'collection'  => null,
        'blogroll'    => 'baddaywithacamera.test',
        'archive'     => 'example.test/a.jpg',
        'search'      => null,
        'albums'      => null,
        'collections' => null,
        'hashtag'     => null,
    ],
    'smacktalk' => [
        'single'   => 'Some words.',
        'page'     => 'Some words.',
        'blogroll' => 'baddaywithacamera.test',
        'archive'  => null,
        'taxonomy' => null,
    ],
];

$skins = glob($root . '/skins/*/layout.php') ?: [];
$check(count($skins) >= 30, 'expected at least 30 skins, found ' . count($skins));

$rendered = 0;

// A layout that reads a key the harness does not set warns, and the harness
// cannot know every skin's private presentation keys. Those warnings are
// collected rather than printed or failed on -- but they are COUNTED and
// shown, because a warning is still a layout reading something it has not
// checked for, and on a live page it prints into the markup.
$notices = [];
set_error_handler(static function (int $no, string $msg) use (&$notices): bool {
    $notices[$msg] = ($notices[$msg] ?? 0) + 1;
    return true;
}, E_WARNING | E_NOTICE | E_DEPRECATED);

foreach ($skins as $layout) {
    $skin = basename(dirname($layout));
    $manifest = json_decode((string)@file_get_contents(dirname($layout) . '/manifest.json'), true);
    $controller = (string)($manifest['cms_controller'] ?? 'public');
    $check(isset($KINDS[$controller]), $skin . ' declares an unknown controller "' . $controller . '"');
    foreach (($KINDS[$controller] ?? []) as $kind => $evidence) {
        $view = $view_for($kind);
        ob_start();
        $error = null;
        try {
            include $layout;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $out = (string)ob_get_clean();
        $rendered++;

        $where = $skin . ' / ' . $kind;
        if ($error !== null) {
            $check(false, $where . ' threw: ' . $error);
            continue;
        }
        $check(trim($out) !== '', $where . ' rendered nothing at all');

        // A known-broken combination must still render and still owes its
        // footer; only the content assertions below are suspended.
        $known = isset($KNOWN_BROKEN[$where]);
        if ($known) $seen_broken[$where] = true;

        // --- the footer, which twelve sites lost on every page -----------
        // Count the SITE footer, not every <footer> element: 52 CARD PICKUP
        // draws a decorative ghost strip as a <footer> and that is its own
        // business. What must be present exactly once is the real one.
        $footers = preg_match_all('/<footer\b[^>]*(?:id="system-footer"|class="[^"]*site-footer)/', $out);
        if (isset($NO_FOOTER[$skin])) {
            $check($footers === 0, $where . ' is the documented no-footer skin and grew one');
        } else {
            $check($footers === 1, $where . ' has ' . $footers . ' footers; it must have exactly one');
            $check(str_contains($out, 'footer-metadata-bar'),
                $where . ' has a footer with no metadata bar in it');
        }
        $check(!str_contains($out, 'sig-text'),
            $where . ' emits the one-line footer that replaced the real one');

        // --- the page must render its OWN content ------------------------
        $content_ok = ($evidence === null) || str_contains($out, $evidence);
        if (!$known) {
            $check($content_ok,
                $where . ' rendered without its own content (looked for "' . $evidence . '")');
        }

        // --- and must not wear another kind's furniture ------------------
        // The control row belongs on every archive-like kind -- verified
        // against the renderer: archive, landing, hashtag, albums and
        // collections all show it, and nothing else does.
        if (!in_array($kind, ['archive', 'landing', 'hashtag', 'albums', 'collections'], true)) {
            $check(!str_contains($out, 'archive-filter-row'),
                $where . ' carries the archive control row');
        }
        $furniture_ok = true;
        if (in_array($kind, ['page', 'blogroll'], true)) {
            $furniture_ok = !str_contains($out, 'TECHNICAL SPECIFICATIONS');
            if (!$known) {
                $check($furniture_ok, $where . ' carries the photograph EXIF table');
            }
        }
        // A listed combination that has started passing must be taken OFF the
        // list in the same change that repaired it, or the list rots into a
        // place things get hidden.
        if ($known && $content_ok && $furniture_ok) {
            $check(false, $where . ' is repaired and must be removed from $KNOWN_BROKEN');
        }
    }
}

restore_error_handler();

// The list may only shrink. A skin repaired but still listed fails here, so
// nobody can leave a stale exemption behind and call it passing.
foreach ($KNOWN_BROKEN as $where => $why) {
    $check(isset($seen_broken[$where]),
        'known-broken entry "' . $where . '" names a skin/kind that does not exist; remove it');
}

printf("every skin, every page: %d combinations across %d skins, %d still known broken.
",
    $rendered, count($skins), count($KNOWN_BROKEN));
if ($notices) {
    printf("  %d distinct warnings raised while rendering (not failures; the
"
         . "  harness cannot set every skin's private keys). Most frequent:
", count($notices));
    arsort($notices);
    foreach (array_slice($notices, 0, 5, true) as $msg => $n) {
        printf("    %4d x %s
", $n, substr($msg, 0, 90));
    }
}

if ($failures) {
    fwrite(STDERR, "Every-skin-every-page regression FAILED:\n");
    foreach (array_slice($failures, 0, 30) as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    if (count($failures) > 30) fwrite(STDERR, '  ... and ' . (count($failures) - 30) . " more\n");
    exit(1);
}
echo "Every-skin-every-page regression passed.\n";

// ===== SNAPSMACK EOF =====
