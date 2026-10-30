<?php
declare(strict_types=1);

/**
 * A page a browser is sent must be a WHOLE DOCUMENT, or it gets no stylesheet.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * REPORTED 30 October 2026, by the owner, on a live site: "blogroll is
 * broken". https://allinthewrist.photoblogs.fyi/blogroll came back as plain
 * serif text on white with default blue links. Fetched, the response begins
 * `<main class="blogroll-page">` -- no <!doctype>, no <html>, no <head>, and
 * therefore NOT ONE <link rel="stylesheet">. The markup was perfect and the
 * page was naked.
 *
 * The cause is in the skin layouts, which are not this file's lane to repair.
 * skins/parade/layout.php opens with a `kind === 'blogroll'` branch that emits
 * <main> and <footer> and stops; the <!doctype> lives in the `else` branch that
 * branch never reaches. It reads like the fragment branch next to it was copied
 * -- and a fragment IS correct for the infinite-scroll photo case, which is why
 * this test only ever asks about kinds that are whole pages.
 *
 * WHY NO EXISTING CHECK CAUGHT IT, which is the part worth keeping. Every skin
 * test we have reads MARKUP and asserts that an element is present. The
 * blogroll markup WAS present and correct -- class for class, it is what the
 * component emits. What was missing was the document around it. A test that
 * looks for a <div> finds it just as happily in a fragment as in a page, so the
 * whole family of checks was blind to this by construction. It is the same gap
 * recorded on 26 October as "no test can see a missing CSS file".
 *
 * So this check does not look for elements. It renders the page and asks
 * whether a browser would have anything to style it with.
 *
 * Run: php tests/every-page-kind-renders-a-whole-document.php
 */

$root = dirname(__DIR__);
chdir($root);

require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/asset-registry.php';
require_once $root . '/core/skin-presentation.php';
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');

$checks = 0;
$failures = [];
function d_ok(bool $cond, string $why): void {
    global $checks, $failures;
    $checks++;
    if (!$cond) $failures[] = $why;
}

/**
 * KNOWN BROKEN ON 30 OCTOBER 2026, measured by rendering all 30 skins.
 *
 * This list is SHRINK-ONLY. A skin may leave it when its layout is repaired;
 * nothing may ever join it. The repair belongs to the skin lane, so this test
 * holds the line meanwhile rather than going red and blocking a release for a
 * fault it did not cause and cannot fix.
 *
 * Every one of these sends a blogroll page with no stylesheet. Any live site
 * on one of these skins has the defect the owner reported.
 */
const BLOGROLL_FRAGMENT_SKINS = [
    // EMPTY, 30 October 2026. All eight were repaired the same day they were
    // found: aurora, heuristic, instant-camera, jive-turkey, parade, sliders,
    // sudden-impact, the-grid. Each had a byte-identical 787-character
    // blogroll branch ahead of its doctype -- one copy-paste across the gram
    // family, not eight mistakes -- and each now handles the kind inside the
    // document, the way GAME ON always did.
    //
    // The list stays here rather than being deleted because an empty list that
    // the suite still enforces is the thing that stops this coming back. Do
    // not add a skin to it to make a failure go away; fix the layout.
];

/** Kinds that are whole pages. 'photo' is excluded ONLY when it is explicitly
 *  requested as a fragment, which is the infinite-scroll path and legitimate. */
const WHOLE_PAGE_KINDS = ['blogroll', 'page', 'collection', 'collections', 'albums'];

$groups = [[
    'category' => 'FRIENDS AND FAMILY',
    'peers' => [[
        'name' => 'Away With A Camera',
        'url' => 'https://awaywithacamera.com/',
        'description' => 'Travel photography.',
    ]],
]];

$render = static function (string $dir, string $skin, string $kind) use ($groups): string {
    $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true) ?: [];
    $view = snapsmack_build_skin_view([
        'kind' => $kind,
        'status' => 200,
        'page_title' => 'TITLE',
        'blogroll_groups' => $groups,
        'navigation' => [],
        'items' => [],
    ], [
        'site_name' => 'all in the wrist', 'language' => 'en', 'direction' => 'ltr',
        'skin_style_url' => '/skins/' . $skin . '/style.css',
        'base_style_url' => '/assets/css/public-base.css',
        'skin_variant_url' => '',
        'registered_assets' => [],
        'owner_custom_code' => '', 'search_dock' => [],
        'skin_slug' => $skin,
        'skin_canvas_width_option' => (string) ($manifest['features']['canvas_width_option'] ?? ''),
        'skin_presentation' => snapsmack_skin_presentation([], $skin),
        'footer' => [
            'site_name' => 'all in the wrist', 'site_url' => 'https://example.test/',
            'slots' => [['kind' => 'copyright', 'year' => '2026', 'site_name' => 'all in the wrist']],
        ],
    ]);
    ob_start();
    @snapsmack_render_strict_skin_template($dir, 'layout.php', $view);
    return (string) ob_get_clean();
};

$broken_now = [];
$skins = 0;

foreach (glob($root . '/skins/*/manifest.json') ?: [] as $manifestPath) {
    $dir = dirname($manifestPath);
    $skin = basename($dir);
    if (!is_file($dir . '/layout.php')) continue;   // beatbox has no layout
    $skins++;

    foreach (WHOLE_PAGE_KINDS as $kind) {
        $html = $render($dir, $skin, $kind);
        if (trim($html) === '') continue;           // skin declines this kind

        $whole = stripos($html, '<!doctype') !== false
              && stripos($html, '<head') !== false
              && preg_match('/<link[^>]+rel=["\']stylesheet/i', $html) === 1;

        $known = $kind === 'blogroll' && in_array($skin, BLOGROLL_FRAGMENT_SKINS, true);

        if (!$whole && !$known) {
            $broken_now[] = "{$skin}/{$kind}";
        }
        if ($whole && $known) {
            // Good news, and it must be recorded rather than silently tolerated:
            // the list is the inventory the owner is owed.
            $broken_now[] = "{$skin}/{$kind} IS NOW FIXED — remove it from BLOGROLL_FRAGMENT_SKINS";
        }

        d_ok($whole || $known,
            "{$skin} sends a bare fragment for '{$kind}' — no doctype, head or stylesheet, so the browser "
            . 'renders it unstyled. This is the fault reported on the live blogroll.');
    }
}

d_ok($skins >= 30, "only {$skins} skins rendered; expected at least 30 — the sweep is not covering the fleet");

// The exemption list may only shrink. If a skin is listed but no longer broken,
// the entry has to go, or the list stops meaning anything.
d_ok($broken_now === [],
     'the whole-document sweep changed: ' . implode(', ', $broken_now));

// And the list itself must not grow by editing. Eight were measured.
d_ok(count(BLOGROLL_FRAGMENT_SKINS) <= 8,
     'BLOGROLL_FRAGMENT_SKINS grew beyond the eight measured on 30 October 2026 — a new skin was '
     . 'exempted instead of repaired');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

printf("PASS: every page kind renders a whole document (%d checks, %d skins)\n", $checks, $skins);
printf("      KNOWN BROKEN, skin lane, blogroll only: %s\n", implode(', ', BLOGROLL_FRAGMENT_SKINS));
// ===== SNAPSMACK EOF =====
