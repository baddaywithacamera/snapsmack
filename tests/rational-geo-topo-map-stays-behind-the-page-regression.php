<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// THE TOPO MAP PAINTS BEHIND THE PHOTOGRAPHS, NOT ACROSS THEM. (SS-1518)
//
// Muse's diagnosis (Brain Trust 6484). body::after is a fixed, full-viewport
// cartographic overlay. It sat at z-index 0, and a POSITIONED element at
// z-index 0 paints above non-positioned content, so the contour lines were
// drawn across every photograph and across the footer. The stylesheet's own
// comment claimed it was safe "since #page-wrapper establishes its own
// stacking layer"; this layout renders no #page-wrapper, so nothing did.
//
// HOW IT WAS PROVED, because none of it can be proved from CSS text. A flat
// single-colour fixture photograph was rendered through the real layout and
// the real strict render function, served over localhost and screenshotted;
// the distinct colour count inside the photograph is the measurement, since a
// flat fill can only gain colours from something painted on top. Against the
// owner's own map off-switch as the control:
//
//   region   before   after   map switched off (control)
//   photo      191      21              19
//   footer     241     109             111
//   margin       3       3               1   (the map itself, still there)
//
// and at 1920, sampled well clear of the tile frame, the photograph came back
// a single colour: distinct=1, against 79 before.
//
// WHAT THIS FILE HOLDS is the declaration that produced those numbers, and
// the reasons it cannot drift back: the z-index, the fact that no layout
// renders the #page-wrapper the old comment relied on, and that the map is
// still a map (fixed, full-bleed, owner-dimmable, owner-switchable) rather
// than quietly disabled, which is the other way to make a photograph clean.

$root = dirname(__DIR__);
$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

echo "SS-1518: RATIONAL GEO's topo map stays behind the page\n\n";

$css = (string)file_get_contents($root . '/skins/rational-geo/style.css');
$at = strpos($css, 'body::after {');
$block = $at === false ? '' : substr($css, $at, (int)strpos($css, '}', $at) - $at);
$decls = (string)preg_replace('#/\*.*?\*/#s', '', $block);

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THE MAP IS BEHIND THE CONTENT\n";
// ─────────────────────────────────────────────────────────────────────────────
$check('the map layer exists at all', $decls !== '');
$check('it sits at a NEGATIVE z-index, so non-positioned content paints over it',
       preg_match('/z-index:\s*-1\s*;/', $decls) === 1);
$check('  and not at 0, which is what painted it across the photographs',
       preg_match('/z-index:\s*0\s*;/', $decls) !== 1);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — IT IS STILL A MAP, NOT A DISABLED ONE\n";
// ─────────────────────────────────────────────────────────────────────────────
// The lazy way to make a photograph clean is to stop drawing the map. These
// pin the feature itself, so that cannot be mistaken for the repair.
$check('still fixed to the viewport', preg_match('/position:\s*fixed/', $decls) === 1);
$check('still full-bleed', preg_match('/width:\s*100%/', $decls) === 1
                            && preg_match('/height:\s*100%/', $decls) === 1);
$check('still drawing the topo artwork', str_contains($decls, 'topo-map.png'));
$check('still dimmable by the owner\'s slider',
       str_contains($decls, 'var(--rg-map-pct'));
$check('still ignoring the pointer, so it cannot eat a click on a photograph',
       preg_match('/pointer-events:\s*none/', $decls) === 1);
$check('and the owner\'s off-switch still hides it',
       preg_match('/body\.rg-map-hidden::after\s*\{[^}]*display:\s*none/s', $css) === 1);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — THE STACKING LAYER THE OLD COMMENT RELIED ON DOES NOT EXIST\n";
// ─────────────────────────────────────────────────────────────────────────────
// This is why the fault was invisible to a reader: the stylesheet said the
// map was safe, and the thing making it safe was never rendered. Asserted
// against every skin layout so the claim stays true, or fails loudly.
$rendersWrapper = [];
foreach ((array)glob($root . '/skins/*/layout.php') as $layout) {
    if (str_contains((string)file_get_contents($layout), 'page-wrapper')) {
        $rendersWrapper[] = basename(dirname($layout));
    }
}
$check('RATIONAL GEO\'s layout renders no #page-wrapper',
       !in_array('rational-geo', $rendersWrapper, true));
// NO CHECK HERE ON THE COMMENT TEXT, deliberately. I wrote one, scanning the
// stylesheet for the old false sentence, and it failed on the clean file: the
// replacement comment QUOTES the sentence it is correcting, and a regex
// cannot tell a quotation from a claim. Rewording the comment to get past my
// own check would have made the file worse to read in order to make a test
// green. A comment cannot break a page, and the thing that can — the z-index
// — is pinned in Stage 1. So a mutation that puts the false sentence back
// SURVIVES this file, and that is stated rather than hidden.

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1518 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "SS-1518 PASS — the map paints behind the page, it is still a dimmable,\n";
echo "switchable, full-viewport map, and the stacking layer its old comment\n";
echo "relied on is confirmed absent rather than assumed present.\n";
echo "NOT PROVED HERE: the rendered result. That was measured in a browser and\n";
echo "the colour counts are in the commit message.\n";
// ===== SNAPSMACK EOF =====
