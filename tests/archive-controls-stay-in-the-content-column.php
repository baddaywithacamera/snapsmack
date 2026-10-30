<?php
declare(strict_types=1);

/**
 * The archive controls stay in the content column. The engine must not move
 * them out of it.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1057. The owner reported the T/M/C cluster sitting outside the content
 * container, hard against the right edge of the page, past where the grid
 * ends -- twice, on two different skins, and still at 0.7.973D.
 *
 * THE CAUSE WAS NOT THE STYLESHEET. It was one comparison in
 * assets/js/ss-engine-archive-toggle.js:
 *
 *     if (dock && dock !== ctrls.parentNode) { dock.appendChild(ctrls); }
 *
 * The server renders the controls one level deeper than the dock --
 * #infobox > .archive-filter-inner > .archive-controls -- so that test was
 * always true and the engine always MOVED them up to #infobox itself.
 *
 * .archive-filter-row (which is #infobox) is FULL-BLEED: it carries the bar's
 * background across the whole window. .archive-filter-inner is the content
 * column, with the max-width and the padding. Moving the controls from the
 * inner wrapper to the row takes them out of the content column, so they land
 * flush against the window edge.
 *
 * MEASURED IN THE GEOMETRY HARNESS, TRUE GRIT at 1440:
 *     server-rendered   controls 770..942, inside .archive-filter-inner
 *     after the move    controls 1268..1440, flush to the window
 *     grid inner right edge 1117, content column right edge 1360
 * The same structural check across every skin the fixture renders the control
 * on -- 50-shades-of-noah-grey, chaplin, impact-printer, onyx, true-grit --
 * showed the old comparison moving it on all five and the new one on none.
 *
 * WHAT THIS FILE CAN AND CANNOT DO. It pins the two things that broke: where
 * the server puts the controls, and the shape of the guard that decides
 * whether to move them. IT DOES NOT MEASURE GEOMETRY -- a PHP test cannot, and
 * pretending otherwise is the mistake this project keeps paying for. The
 * geometry is verified in a browser, at 1920, 1440 and 1024, and that is
 * recorded on the ticket, not here.
 *
 * Run: php tests/archive-controls-stay-in-the-content-column.php
 */

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');

$checks = 0;
$failures = [];
function a_ok(bool $cond, string $why): void {
    global $checks, $failures;
    $checks++;
    if (!$cond) $failures[] = $why;
}

// ── 1. THE SERVER PUTS THE CONTROLS IN THE CONTENT COLUMN ──────────────────
//
// Rendered, not read: the component is asked for a filter row carrying both a
// layout toggle and a calendar, and the result is inspected as a document.

// The component is 'archive-filters' and the keys are its own -- filters,
// search, layouts, calendar, content_width. Taken from the component rather
// than guessed: an earlier version of this test invented a name and a shape,
// rendered nothing, and reported three failures that were all its own fault.
$html = snap_render_html(snap_render_component('archive-filters', [
    'show_all_url'  => 'https://example.test/archive',
    'filters'       => [],
    'search'        => [],
    'selected'      => [],
    'query'         => '',
    'content_width' => '1280',
    'layouts'       => [
        ['mode' => 'thumbs',  'label' => 'T', 'title' => 'Thumbs',  'url' => '?l=thumbs',  'active' => true],
        ['mode' => 'masonry', 'label' => 'M', 'title' => 'Masonry', 'url' => '?l=masonry', 'active' => false],
    ],
    'calendar'      => ['enabled' => true, 'open' => false],
]));

a_ok($html !== '', 'the filter row rendered nothing, so nothing below means anything');
a_ok(str_contains($html, 'archive-controls--docked'),
    'the controls are not rendered at all — the layout toggle and calendar button are gone');

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
libxml_clear_errors();
$xp = new DOMXPath($dom);

$controls = $xp->query('//*[contains(@class,"archive-controls--docked")]')->item(0);
a_ok($controls !== null, 'cannot find the controls in the rendered row');

if ($controls !== null) {
    $parent = $controls->parentNode;
    $parentClass = $parent instanceof DOMElement ? (string) $parent->getAttribute('class') : '';
    a_ok(str_contains($parentClass, 'archive-filter-inner'),
        'the controls are no longer rendered inside .archive-filter-inner (parent is "'
        . $parentClass . '"). The inner wrapper is the CONTENT COLUMN -- it carries the '
        . 'max-width and the padding -- while the row around it is full-bleed. Rendering '
        . 'them anywhere else puts them outside the content column, which is SS-1057.');

    // And the inner wrapper really is inside the row, so "one level deeper than
    // the dock" is the shape the engine's guard has to cope with.
    $grandparent = $parent instanceof DOMElement ? $parent->parentNode : null;
    $gpId = $grandparent instanceof DOMElement ? (string) $grandparent->getAttribute('id') : '';
    a_ok($gpId === 'infobox',
        'the content column is no longer a child of #infobox, so the engine\'s dock target '
        . 'and the controls\' parent are no longer related the way the guard assumes');
}

// ── 2. THE ENGINE'S GUARD ASKS THE RIGHT QUESTION ──────────────────────────
//
// Comments stripped first: this file's own subject is a comparison written out
// in prose in the engine, and a check that matches the explanation would pass
// while the code did the opposite.

$engine = (string) file_get_contents($root . '/assets/js/ss-engine-archive-toggle.js');
$code = preg_replace('#/\*.*?\*/#s', '', $engine);
$code = preg_replace('#^\s*//.*$#m', '', (string) $code);
$code = (string) $code;

a_ok(!str_contains($code, 'the bar\'s background across the window'),
    'comment stripping is not working, so the checks below could pass on prose');

a_ok(str_contains($code, '!dock.contains(ctrls)'),
    'the dock guard no longer asks whether the controls are ALREADY INSIDE the dock. '
    . 'Without that question the engine moves a server-rendered control out of the '
    . 'content column and into the full-bleed row, which is SS-1057 exactly.');

a_ok(!preg_match('/dock\s*!==\s*ctrls\.parentNode/', $code),
    'the direct-parent comparison is back. It is true whenever the controls are rendered '
    . 'deeper than the dock, which they always are, so it always moves them.');

// The move must still be POSSIBLE, for a legacy page that renders the controls
// somewhere else entirely. A guard that never moves anything is not a fix.
a_ok(str_contains($code, 'dock.appendChild(ctrls)'),
    'the engine can no longer dock controls at all — a legacy archive page that renders '
    . 'them outside the filter row now never gets them moved into it');

// ── 3. NOTHING WRITES AN INLINE POSITION ANY MORE ──────────────────────────
//
// The server renders the controls WITH the docked class, so the old runtime
// aligner ran on them whether or not they had been moved, and its inline
// right: beat the stylesheet. Measured on 50-SHADES at 1440 after the
// parentage fix: stylesheet only put the controls at 1141..1313, the 40px
// inset the stylesheet asks for; the aligner moved them to 1181..1353, flush
// against the content column's edge. So it was not neutral and it is gone.
//
// If it comes back, the stylesheet stops being the thing that positions this
// control, and that is the condition the whole ticket is about.

// ANY inline write, under ANY variable name. The first version of this check
// looked for the literal 'ctrls.style.right', and a mutation that wrote the
// same inline style through a differently named variable sailed past it. A
// check that pins a variable name tests the spelling, not the behaviour.
a_ok(!preg_match('/\.\s*style\s*\.\s*right\s*=/', $code)
     && !preg_match('~setProperty\(\s*.right.~', $code),
    'something writes an inline right: on the controls again. An inline style beats the '
    . 'stylesheet, and the stylesheet is what keeps the control in the content column at '
    . 'the intended inset.');

a_ok(!preg_match('/function\s+alignDockedControls/', $code),
    'the runtime aligner is back. It measured against #infobox, which is full-bleed, and '
    . 'applied the answer to an element positioned against the content column -- two '
    . 'different reference frames -- and clamped to zero, which means flush.');

a_ok(!preg_match("/addEventListener\(\s*'resize'/", $code),
    'a resize listener is back in this engine. The only one it ever had existed to '
    . 're-run the aligner; the stylesheet needs no help on resize.');

// The stylesheet must actually carry the rule that does the positioning now,
// or removing the JavaScript leaves nothing doing the job.
$css = (string) file_get_contents($root . '/assets/css/page-archive.css');
a_ok((bool) preg_match('/\.archive-filter-row\s+\.archive-controls--docked\s*\{[^}]*right:\s*var\(--archive-content-padding/', $css),
    'the stylesheet no longer positions the docked controls against the content padding, '
    . 'and nothing else does either now the aligner is gone');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
printf("PASS: the archive controls stay in the content column (%d checks)\n", $checks);
// ===== SNAPSMACK EOF =====
