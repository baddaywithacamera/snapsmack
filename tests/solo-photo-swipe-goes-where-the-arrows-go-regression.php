<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// A SWIPE ON A SOLO PHOTOGRAPH GOES WHERE THE ARROW KEYS GO.
//
// A tablet has no arrow keys, so on a touch screen the only way to the next
// photograph was the small PREV/NEXT text links. The lightbox has had swipe
// since it was written, but it only engages with two or more images in one
// gallery and a solo page wires exactly one — so on RATIONAL GEO, and every
// skin shaped like it, swiping did nothing at all. Reported by Sean on his
// tablet, diagnosed by Muse.
//
// WHAT THIS FILE PROVES AND WHAT IT DOES NOT. The gesture itself is
// JavaScript and PHP cannot run it. It was proved by dispatching real
// TouchEvents at the real engine in a browser, on the real solo page
// rendered through the strict render function:
//
//   swipe left 120px   -> the rel="next" page          NAVIGATED
//   swipe right 130px  -> the rel="prev" page          NAVIGATED
//   swipe 40px                                         no navigation
//   mostly vertical swipe                              no navigation
//   two fingers (pinch)                                no navigation
//   pure vertical scroll                               no navigation
//   swipe inside a sideways-scrollable table           no navigation
//   swipe while the lightbox is open                   no navigation
//
// Those results are in the commit message. THIS FILE HOLDS THE CONTRACT THE
// GESTURE DEPENDS ON, which PHP can execute: the destinations themselves.
// The swipe reads the page's own rel="prev"/rel="next" anchors, exactly as
// the arrow keys do. If those stop being rendered, swipe and arrows both die
// silently and no JavaScript test would notice either.

$root = dirname(__DIR__);
define('BASE_URL', '/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

echo "A swipe on a solo photograph goes where the arrows go\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THE DESTINATIONS, EXECUTED\n";
// ─────────────────────────────────────────────────────────────────────────────
// snap_render_component() is run for real and its output read, rather than
// the source being inspected for the attributes.
$html = (string)snap_render_component('photo-navigation', [
    'item'     => ['url' => '/photo/here', 'img_slug' => 'here'],
    'next'     => ['url' => '/photo/older', 'img_slug' => 'older'],
    'previous' => ['url' => '/photo/newer', 'img_slug' => 'newer'],
    'first'    => ['url' => '/photo/first', 'img_slug' => 'first'],
    'last'     => ['url' => '/photo/last',  'img_slug' => 'last'],
    'comments' => [], 'comments_enabled' => false,
]);

$check('the navigation renders a rel="next" link',
       preg_match('/<a href="\/photo\/older"[^>]*rel="next"/', $html) === 1);
$check('and a rel="prev" link',
       preg_match('/<a href="\/photo\/newer"[^>]*rel="prev"/', $html) === 1);
$check('  both carrying a real href, which is what the engine reads',
       substr_count($html, 'href="/photo/older"') === 1
       && substr_count($html, 'href="/photo/newer"') === 1);

// A destination that is the page you are already on must not be a link, or a
// swipe at the end of the archive would reload the same photograph and read
// as the gesture being broken.
$end = (string)snap_render_component('photo-navigation', [
    'item'     => ['url' => '/photo/here', 'img_slug' => 'here'],
    'next'     => ['url' => '/photo/here', 'img_slug' => 'here'],
    'previous' => ['url' => '/photo/newer', 'img_slug' => 'newer'],
    'comments' => [], 'comments_enabled' => false,
]);
$check('at the end of the archive the exhausted direction is not a link',
       !str_contains($end, 'rel="next"') && str_contains($end, 'rel="prev"'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — ONE SET OF DESTINATIONS, NOT TWO\n";
// ─────────────────────────────────────────────────────────────────────────────
// The swipe lives in the hotkey engine and calls the same ssNavUrl() the
// arrows call. A second resolver reading the same links is how two answers
// to one question get into a codebase, so this pins that there is one.
$js = (string)file_get_contents($root . '/assets/js/ss-engine-comms.js');
$check('the swipe resolves its destination through ssNavUrl, as the arrows do',
       preg_match('/ssNavUrl\(\x27next\x27\)\s*:\s*ssNavUrl\(\x27prev\x27\)/', $js) === 1);
$check('  with no second rel-link lookup of its own',
       substr_count($js, "querySelector('a[rel=\"") === 1);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — THE GESTURE ITSELF, EXECUTED\n";
// ─────────────────────────────────────────────────────────────────────────────
// THE REAL ENGINE IS RUN AND REAL GESTURES ARE DRIVEN THROUGH IT. An earlier
// version of this file checked the engine's SOURCE for the handler and for
// each guard's name, and two mutations walked past it: a `return;` inserted
// at the top of the handler, which disables the gesture while leaving every
// string in place, and the scrollable-element guard's CALL deleted while its
// definition kept the name in the file. Both passed a source check and both
// break the feature. tests/support/solo-swipe-harness.cjs loads the engine
// into a stub DOM and dispatches touches at it, so what is asserted below is
// what the engine DID.
$harness = $root . '/tests/support/solo-swipe-harness.cjs';
$node = null;
foreach (['node --version'] as $probe) {
    @exec($probe . ' 2>&1', $out, $rc);
    if ($rc === 0) { $node = 'node'; }
}
if ($node === null) {
    // Unknown is never a pass.
    echo "  [NOT CHECKED] node is not on PATH, so the gesture could not be run here.\n";
    $failures[] = 'the gesture was NOT CHECKED: node unavailable';
} else {
    $raw = (string)shell_exec($node . ' ' . escapeshellarg($harness) . ' 2>&1');
    $ran = json_decode($raw, true);
    if (!is_array($ran)) {
        echo "  [FAIL] the harness did not return results:\n" . $raw . "\n";
        $failures[] = 'the swipe harness did not run';
    } else {
        $expect = [
            'swipe left navigates to the rel=next page'        => '/photo/older',
            'swipe right navigates to the rel=prev page'       => '/photo/newer',
            'a 40px drag does nothing'                         => null,
            'a mostly vertical swipe does nothing'             => null,
            'a pure vertical scroll does nothing'              => null,
            'two fingers at once does nothing'                 => null,
            'a second finger mid-gesture cancels it'           => null,
            'an open lightbox keeps the gesture'               => null,
            'a sideways-scrollable element keeps the gesture'  => null,
            'a slider keeps the gesture'                       => null,
            'with no next link a left swipe does nothing'      => null,
        ];
        foreach ($expect as $case => $want) {
            $got = array_key_exists($case, $ran) ? $ran[$case] : '(case missing)';
            $check($case . ($want === null ? '' : ' -> ' . $want), $got === $want);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — IT CANNOT WORK WITHOUT BEING LISTED\n";
// ─────────────────────────────────────────────────────────────────────────────
// This file's own rule, stated in its header: a shortcut that works and is
// not in the help modal is how the help list silently went stale before.
$check('the help modal lists the swipe',
       str_contains($js, "'SWIPE'"));
$check('  and only when there is somewhere to swipe to',
       preg_match('/if \(prev \|\| ssNavUrl\(\x27next\x27\)\) rows\.push\(\[\x27SWIPE\x27/', $js) === 1);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'Solo swipe FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "Solo photo swipe: PASS — the page renders the rel=\"prev\"/rel=\"next\"\n";
echo "destinations the gesture depends on, the real engine was RUN and both\n";
echo "directions navigated while all nine refusing cases refused, the gesture\n";
echo "shares one resolver with the arrow keys, and the help modal lists it.\n";
echo "NOT PROVED HERE: a real finger on real glass. The harness drives a stub\n";
echo "DOM; the same eleven cases were also driven with real TouchEvents in a\n";
echo "browser, and those results are in the commit message. Sean's tablet is\n";
echo "the only thing that settles the feel of it.\n";
// ===== SNAPSMACK EOF =====
