<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The Skin Packager can publish a re-baselined skin, and still refuses an
 * older one. (SS-1652, blocking ALFRED 0.7.31)
 *
 * THE FAULT. ALFRED was re-baselined onto the 0.7 series: its manifest reads
 * version 0.7.31 with version_epoch 1, while the published registry still
 * holds 1.0.30 from the old numbering. core/skin-registry.php already orders
 * those correctly for the fleet, because 1:0.7.31 outranks 1.0.30 once the
 * epoch is read. The PACKAGER did not: it compared raw versions, 0.7.31
 * against 1.0.30, decided the candidate was older, and refused to publish a
 * skin the CMS would have installed happily.
 *
 * IT WAS THREE FAULTS, NOT ONE, and fixing only the comparison would have
 * left the other two to bite later:
 *
 *   1. the repo scan dropped version_epoch, so the candidate's epoch was
 *      never even in hand
 *   2. the publication guard compared raw versions
 *   3. the registry entry WRITTEN at publish dropped version_epoch too, so a
 *      successful publish would have shipped an entry the fleet reads as
 *      epoch 0 -- and the next comparison would be raw all over again
 *
 * Run: php tests/skin-packager-publishes-an-epoch-regression.php
 */

$root = dirname(__DIR__);
require_once $root . '/core/version-series.php';

$fails  = [];
$checks = 0;
function px(bool $ok, string $what, string $saw = ''): void {
    global $fails, $checks;
    $checks++;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fails[] = $what;
    echo "  FAIL  $what" . ($saw !== '' ? "   [saw: $saw]" : '') . "\n";
}

echo "The Skin Packager publishes a re-baselined skin\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "THE REAL ALFRED CASE, READ FROM THE REAL MANIFEST\n";
// ─────────────────────────────────────────────────────────────────────────────
// The numbers are not invented here. If somebody re-bases ALFRED again, or
// drops the epoch from its manifest, this reads the new value and the
// assertions below move with it rather than quietly testing yesterday.
$manifestPath = $root . '/skins/alfred/manifest.json';
px(is_file($manifestPath), 'the ALFRED manifest is where it is expected');
$manifest = is_file($manifestPath)
    ? json_decode((string)file_get_contents($manifestPath), true) : null;
px(is_array($manifest), '  and it parses');

$candidate = is_array($manifest) ? $manifest : [];
px(($candidate['version'] ?? '') === '0.7.31',
   '  it declares version 0.7.31', (string)($candidate['version'] ?? ''));
px((int)($candidate['version_epoch'] ?? 0) === 1,
   '  and version_epoch 1, which is what makes it newer than 1.0.30',
   (string)($candidate['version_epoch'] ?? 0));

// The published registry entry as it stands on snapsmack.ca: the old number,
// and NO epoch, because it predates the re-baseline.
$published = ['version' => '1.0.30'];

$candidateCmp = snap_release_version_with_epoch($candidate);
$publishedCmp = snap_release_version_with_epoch($published);
px($candidateCmp === '1:0.7.31', 'the candidate compares as 1:0.7.31', $candidateCmp);
px($publishedCmp === '1.0.30',   '  the published entry as 1.0.30',    $publishedCmp);

px(snap_compare_release_versions($candidateCmp, $publishedCmp, '>'),
   'SO THE PACKAGER MUST SEE IT AS NEWER and publish it');

// And the fault, stated as the thing that must NOT decide this.
px(version_compare('0.7.31', '1.0.30', '<='),
   '  while a RAW compare calls it older, which is the bug this fixes');

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAND AN OLDER SKIN IS STILL REFUSED\n";
// ─────────────────────────────────────────────────────────────────────────────
// A guard loosened until everything passes is not a guard. These are the
// cases that must keep failing.
$cases = [
    ['0.2.80', 0, '0.2.81', 0, false, 'same epoch, lower patch'],
    ['0.2.81', 0, '0.2.81', 0, false, 'same epoch, identical version'],
    ['0.2.82', 0, '0.2.81', 0, true,  'same epoch, higher patch'],
    ['0.7.31', 1, '0.7.32', 1, false, 'same epoch 1, lower patch'],
    ['0.7.31', 1, '0.7.31', 1, false, 'same epoch 1, identical version'],
    ['1.0.30', 0, '0.7.31', 1, false, 'the OLD numbering must not beat the new epoch'],
];
foreach ($cases as [$cv, $ce, $pv, $pe, $expected, $label]) {
    $got = snap_compare_release_versions(
        snap_release_version_with_epoch(['version' => $cv, 'version_epoch' => $ce]),
        snap_release_version_with_epoch(['version' => $pv, 'version_epoch' => $pe]),
        '>'
    );
    px($got === $expected,
       sprintf('  %s: %s(e%d) over %s(e%d) is %s', $label, $cv, $ce, $pv, $pe,
               $expected ? 'PUBLISHABLE' : 'refused'),
       $got ? 'publishable' : 'refused');
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nALL THREE PLACES IN THE PACKAGER CARRY THE EPOCH\n";
// ─────────────────────────────────────────────────────────────────────────────
// These are SOURCE assertions and are labelled as such. The decision itself is
// exercised above; what cannot be executed from here is the packager's own
// procedural flow, which needs a GitHub fetch, a signing key and a releases
// directory. So this pins the three places the epoch has to appear, because
// fixing only one of them leaves the fault alive in the other two.
$pkgPath = $root . '/smack-central/sc-skins.php';
px(is_file($pkgPath), 'the Skin Packager is where it is expected');
$pkg = is_file($pkgPath) ? (string)file_get_contents($pkgPath) : '';

px(str_contains($pkg, "/core/version-series.php'"),
   'it requires core/version-series.php rather than reimplementing the compare');
// COUNTING OCCURRENCES WAS NOT ENOUGH, and a mutant proved it: deleting the
// epoch from the repo scan left this file's own COMMENTS mentioning
// version_epoch often enough to satisfy a >= 3 count, so the check passed
// while the fault was back. The needle has to be the expression, not the word.
// Comments are stripped first so a comment can never stand in for code.
$pkgCode = (string)preg_replace('~//[^
]*|/\*.*?\*/~s', '', $pkg);
px(str_contains($pkgCode, "(int)(\$manifest['version_epoch'] ?? 0)"),
   '  the repo SCAN carries the epoch out of the manifest');
px(str_contains($pkgCode, "(int)(\$meta['version_epoch'] ?? 0)"),
   '  and the WRITTEN registry entry carries it too');
px(str_contains($pkg, 'snap_release_version_with_epoch($meta)'),
   '  the guard builds the candidate version WITH its epoch');
px(str_contains($pkg, 'snap_compare_release_versions($candidate_cmp, $published_cmp'),
   '  and compares through core, not version_compare');

// THE REGRESSION THAT MATTERS MOST: the old raw compare must be gone from the
// publication guard. It is still legitimate elsewhere in the file for sorting
// plain lists, so this looks for the exact refused comparison.
px(!str_contains($pkg, "version_compare(\$version, \$published_version, '<=')"),
   '  and the raw publication compare is GONE');

// A publish that does not write the epoch ships an entry the fleet reads as
// epoch 0, and then the next comparison is raw again.
$writeAt = strpos($pkg, "'download_url'       => \$download_url");
$epochAfterWrite = $writeAt !== false
    && strpos($pkg, "'version_epoch'", $writeAt) !== false;
px($epochAfterWrite,
   '  the registry entry it WRITES carries version_epoch, so a publish keeps it');

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($fails !== []) {
    echo 'FAIL (' . count($fails) . ' of ' . $checks . "):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS ({$checks} checks) — a re-baselined skin can be published, an older\n";
echo "one still cannot, and the epoch survives into the registry the fleet reads.\n";
echo "NOT VERIFIED HERE: an actual publish. That needs the GitHub fetch, the\n";
echo "signing key and the releases directory, and it is the owner's to run.\n";
exit(0);

// ===== SNAPSMACK EOF =====
