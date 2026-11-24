<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// EVERY SHAPE THE SKIN VERSION GATE CAN MEET, DRIVEN ON PURPOSE.
//
// SS-1566. tests/skin-version-content-regression.php guards a real rule: one
// published skin version must mean exactly one set of files, because a site
// installs a skin BY VERSION and no CMS release can reach markup that is not
// in the CMS. It runs over the real skins/ folder, which on any given day is
// in ONE state, so all but one of its branches were never exercised. One of
// them did not exist at all.
//
// THE HOLE, measured on dev before the fix, over the real 30 skins:
//
//   1. The gate said PASS over 30 skins.
//   2. Editing one baseline row to claim version 1.0.0 with a files digest of
//      zeroes, against a manifest that says 1.3.44, still said PASS over 30
//      skins. Nothing required the baseline to be brought forward.
//   3. With that stale row left in place, appending a line to that skin's
//      style.css WITHOUT bumping its version ALSO said PASS -- and that is
//      precisely the fault the gate exists to catch, the one that left
//      photowalk.ing serving an old layout through a fortnight of releases.
//
// Step 3 is the part that matters. Every check in the gate compares against
// the recorded row, so a row allowed to fall behind does not merely go stale:
// it switches the gate off for that skin, silently, for good.
//
// The comparison now lives in the generator next to the digest, for the same
// stated reason the digest lives there, and this file drives it with crafted
// rows. A test that can only observe whatever the tree happens to be today is
// not a test of the rule; it is a test of today.
//
// Run:  php tests/skin-version-gate-shapes-regression.php

$root = dirname(__DIR__);
require_once $root . '/tools/_build/generate-skin-version-baseline.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

/** A row as the generator produces one. */
$row = static function (string $version, string $files, string $css = 'css-a'): array {
    return [
        'version' => $version,
        'files_sha256' => hash('sha256', $files),
        'manifest_css_sha256' => hash('sha256', $css),
    ];
};

/** The complaint about one named skin, or '' if the gate said nothing. */
$complaintAbout = static function (array $recorded, array $current, string $skin): string {
    foreach (snapsmack_skin_version_failures($recorded, $current) as $f) {
        if (str_starts_with($f, $skin . ':')) { return $f; }
    }
    return '';
};

echo "Skin version gate: every shape\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "THE HOLE THAT WAS FOUND: A BASELINE ROW LEFT BEHIND\n";
// ─────────────────────────────────────────────────────────────────────────────
// Reproduced at the level of the rule rather than by hand-editing the real
// fixture: the row records an older version AND older content, which is what
// every ordinary skin change produces if nobody regenerates.

$stale = $complaintAbout(
    ['chaplin' => $row('1.0.0', 'files-old')],
    ['chaplin' => $row('1.3.44', 'files-new')],
    'chaplin');
$check('a row recording an older version AND older content is a failure', $stale !== '');
$check('  it says the baseline row is out of date', str_contains($stale, 'out of date'));
$check('  it names both versions, so the reader can see how far behind it is',
       str_contains($stale, '1.0.0') && str_contains($stale, '1.3.44'));
$check('  and it says how to fix it', str_contains($stale, 'generate-skin-version-baseline.php'));

// THE CONSEQUENCE, which is worse than the staleness and is why this is not a
// tidiness complaint. Left alone, the row keeps the gate quiet about the very
// thing it is for. This asserts the ORDER of events, not just one comparison.
$recorded = ['chaplin' => $row('1.0.0', 'files-old')];
$afterEdit = ['chaplin' => $row('1.3.44', 'files-new-then-edited-again')];
$check('and while the row is behind, a later file edit under an unchanged '
       . 'version is still reported',
       $complaintAbout($recorded, $afterEdit, 'chaplin') !== '');

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE THREE SHAPES THAT ALREADY WORKED, HELD IN PLACE\n";
// ─────────────────────────────────────────────────────────────────────────────
// Adding the branch above must not have taken over a case that had its own,
// more specific message. A reader given the generic message when the precise
// one applies is being told less than the gate knows.

$same = $complaintAbout(
    ['chaplin' => $row('1.3.44', 'files-old')],
    ['chaplin' => $row('1.3.44', 'files-new')],
    'chaplin');
$check('files changed with the version standing still is a failure', $same !== '');
$check('  and it still gets the specific message about installing by version',
       str_contains($same, 'Sites install by version'));

$cssOnly = $complaintAbout(
    ['chaplin' => $row('1.3.44', 'files-same', 'css-a')],
    ['chaplin' => $row('1.3.44', 'files-same', 'css-b')],
    'chaplin');
$check('a manifest option changing with the version standing still is a failure',
       $cssOnly !== '');
$check('  and it still cites SS-1214, which is the case that proved it',
       str_contains($cssOnly, 'SS-1214'));

$bumpOnly = $complaintAbout(
    ['chaplin' => $row('1.3.44', 'files-same')],
    ['chaplin' => $row('1.3.45', 'files-same')],
    'chaplin');
$check('a version bump with nothing else changed is a failure', $bumpOnly !== '');
$check('  and it still offers both ways out: regenerate, or drop the bump',
       str_contains($bumpOnly, 'drop the bump'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAND THE GATE STILL SAYS NOTHING WHEN NOTHING IS WRONG\n";
// ─────────────────────────────────────────────────────────────────────────────
// Without this, every check above is satisfied by a gate that fails on
// everything, which would be useless in a different way. This is the half that
// makes the failures above mean something.

$clean = ['alfred' => $row('2.0.1', 'a'), 'chaplin' => $row('1.3.44', 'b')];
$check('a baseline that matches the tree produces no complaints',
       snapsmack_skin_version_failures($clean, $clean) === []);
$check('  and that holds for a tree of one skin as well',
       snapsmack_skin_version_failures(['alfred' => $row('2.0.1', 'a')],
                                       ['alfred' => $row('2.0.1', 'a')]) === []);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA SKIN THAT ARRIVED OR LEFT\n";
// ─────────────────────────────────────────────────────────────────────────────

$added = $complaintAbout([], ['newskin' => $row('1.0.0', 'a')], 'newskin');
$check('a skin not in the baseline at all is a failure', $added !== '');
$check('  and it is not mistaken for a stale row',
       !str_contains($added, 'out of date') && str_contains($added, 'missing from the baseline'));

$removed = snapsmack_skin_version_failures(['goneskin' => $row('1.0.0', 'a')], []);
$check('a baseline row for a skin that no longer exists is a failure',
       $removed !== [] && str_contains($removed[0], 'goneskin'));

$noVersion = $complaintAbout(
    ['chaplin' => $row('1.3.44', 'a')],
    ['chaplin' => ['version' => '', 'files_sha256' => 'x', 'manifest_css_sha256' => 'y']],
    'chaplin');
$check('a manifest with no version at all is a failure',
       str_contains($noVersion, 'no version'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE REAL TREE, AS THE GATE SEES IT TODAY\n";
// ─────────────────────────────────────────────────────────────────────────────
// Crafted rows prove the rule; this proves the rule is wired to the real
// thing, which is the other half of the same contract.

$baselinePath = $root . '/tests/fixtures/skin-version-content-baseline.json';
$baseline = json_decode((string)file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
$current = snapsmack_skin_version_rows($root);
$check('the generator finds the skins on disk', count($current) > 0);
$check('every one of them has a baseline row with a matching version',
       snapsmack_skin_version_failures($baseline['skins'] ?? [], $current) === []);
printf("        %d skins, baseline in step with all of them\n", count($current));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — the gate reports every shape, including a baseline left behind.\n";
exit(0);

// ===== SNAPSMACK EOF =====
