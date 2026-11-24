<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// A PUBLISHED SKIN VERSION NEVER SKIPS A NUMBER.
//
// SS-1568. Sean's standing rule, 24 November 2026, relayed by the PM: a skin's
// version on dev is its LAST PUBLISHED version plus one, however many commits
// touch it before it ships. A second change to a skin that has not been
// published keeps the same number and regenerates the content baseline. It
// does not bump again.
//
// MEASURED against the live registry when this was written -- the real thing,
// fetched, not assumed:
//
//   new-horizon     published 1.3.42   dev 1.3.45   skips 1.3.43 and 1.3.44
//   true-grit       published 1.3.30   dev 1.3.32   skips 1.3.31
//   impact-printer  published 2.1.29   dev 2.1.30   correct
//   alfred          published 1.0.28   dev 1.0.28   untouched
//
// Thirteen skins were already at published+1 and the rest untouched, so this
// is a slip on two skins, not a rule nobody follows.
//
// WHY AN OWNER CARES. A site installs a skin BY VERSION, and the owner reads
// that number in the admin picker to tell whether they have the current one. A
// tree at 1.3.45 against a registry serving 1.3.42 means two numbers were
// never published at all, so anybody comparing is comparing against versions
// that cannot be installed.
//
// THE HONEST LIMITATION, stated here because it decides how much this proves.
// The only authority for a published version is the live registry SMACK
// CENTRAL writes when Sean packages. This gate must not fetch it: a check that
// fails because a connection timed out is a check people learn to ignore. So
// the numbers are a committed fixture, refreshed with
// tools/_build/refresh-skin-published-versions.php.
//
// When that fixture falls behind, this gate FAILS LOUDLY -- it demands a lower
// number than the true one and says so with both numbers and the refresh
// command. That direction is the whole difference between this and the fault
// SS-1566 fixed: THAT gate went quiet when its record fell behind, and quiet
// is how a gate stops protecting anything without anyone noticing.
//
// Run:  php tests/skin-versions-never-skip-a-number-regression.php

$root = dirname(__DIR__);
require_once $root . '/core/skin-published-versions.php';
require_once $root . '/tools/_build/generate-skin-version-baseline.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

/** The complaint about one named skin, or '' if the rule said nothing. */
$about = static function (array $published, array $current, string $skin): string {
    foreach (snapsmack_skin_version_skip_failures($published, $current) as $f) {
        if (str_starts_with($f, $skin . ':')) { return $f; }
    }
    return '';
};

echo "Skin versions never skip a number\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "THE RULE ITSELF\n";
// ─────────────────────────────────────────────────────────────────────────────

$check('published + 1 is accepted',
       snapsmack_skin_version_skip_failures(['a' => '1.3.42'], ['a' => '1.3.43']) === []);
$check('the published version itself is accepted (nothing changed since it shipped)',
       snapsmack_skin_version_skip_failures(['a' => '1.3.42'], ['a' => '1.3.42']) === []);

$skip = $about(['a' => '1.3.42'], ['a' => '1.3.44'], 'a');
$check('published + 2 is a failure', $skip !== '');
$check('  it says the number is SKIPPED', str_contains($skip, 'SKIPS'));
$check('  it names the published version and the one number that is allowed',
       str_contains($skip, '1.3.42') && str_contains($skip, '1.3.43'));
$check('  it gives the command that regenerates the baseline after the fix',
       str_contains($skip, 'generate-skin-version-baseline.php'));
$check('  and it offers the other possibility: the record is stale',
       str_contains($skip, 'refresh-skin-published-versions.php'));

$far = $about(['a' => '1.3.42'], ['a' => '1.4.0'], 'a');
$check('a minor-part jump is a failure too, not just the last part', $far !== '');

// ─────────────────────────────────────────────────────────────────────────────
echo "\nA VERSION CANNOT GO BACKWARDS\n";
// ─────────────────────────────────────────────────────────────────────────────
// Owners already have the published one installed, so a lower number in the
// tree is either a wrong manifest or a stale record. It gets its own message,
// because telling somebody to "bump to 1.3.43" when the tree says 1.3.40 does
// not describe what they are looking at.

$back = $about(['a' => '1.3.42'], ['a' => '1.3.40'], 'a');
$check('a version below the published one is a failure', $back !== '');
$check('  and it says PUBLISHED rather than telling them to bump',
       str_contains($back, 'PUBLISHED') && !str_contains($back, 'SKIPS'));
$check('  it points at both the manifest and the record as possible causes',
       str_contains($back, 'manifest is wrong')
       && str_contains($back, 'refresh-skin-published-versions.php'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHAT IT DECLINES TO JUDGE, AND SAYS SO BY SAYING NOTHING\n";
// ─────────────────────────────────────────────────────────────────────────────
// Each of these is a case where there is no honest answer. Inventing one
// would block work that is perfectly correct, which is the fastest way to get
// a gate switched off.

$check('a skin the registry has never carried is not judged at all',
       snapsmack_skin_version_skip_failures([], ['brandnew' => '0.1.0']) === []);
$check('  which is what lets a NEW skin be committed on its first day',
       snapsmack_skin_version_skip_failures(['other' => '1.0.0'],
                                            ['brandnew' => '7.7.7']) === []);

// AND IT MUST DO THAT WITHOUT COMPLAINING TO THE ERROR LOG.
//
// FOUND BY MUTATION. Removing the array_key_exists guard changed no verdict,
// because the later checks decline an unknown skin anyway -- so the mutant
// SURVIVED. But it is not dead code: without it, reading the missing key
// raises a PHP warning for every unpublished skin. On a release gate that is
// noise in the log at exactly the moment somebody is reading the log, and on
// a site with display_errors on it is output in the page. The guard earns its
// place for a reason the verdict cannot show, so the test checks the warning.
$noticed = [];
set_error_handler(static function (int $no, string $msg) use (&$noticed): bool {
    $noticed[] = $msg;
    return true;
});
snapsmack_skin_version_skip_failures(['other' => '1.0.0'], ['brandnew' => '7.7.7']);
restore_error_handler();
$check('  and it does so without raising a PHP warning for the missing key',
       $noticed === []);
if ($noticed !== []) { echo '        raised: ' . $noticed[0] . "\n"; }
$check('a version that is not a dotted number has no computable successor',
       snapsmack_skin_version_skip_failures(['a' => '2.0-beta'], ['a' => '9.9.9']) === []);
$check('an empty version is left to the content gate, which already reports it',
       snapsmack_skin_version_skip_failures(['a' => '1.0.0'], ['a' => '']) === []);
$check('an empty PUBLISHED version is not treated as zero',
       snapsmack_skin_version_skip_failures(['a' => ''], ['a' => '5.0.0']) === []);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE SUCCESSOR IS THE LAST PART PLUS ONE, AND NOTHING CLEVERER\n";
// ─────────────────────────────────────────────────────────────────────────────

$check('1.3.42 -> 1.3.43', snapsmack_skin_next_version('1.3.42') === '1.3.43');
$check('1.3.9 -> 1.3.10, not 1.4.0', snapsmack_skin_next_version('1.3.9') === '1.3.10');
$check('2.1.29 -> 2.1.30', snapsmack_skin_next_version('2.1.29') === '2.1.30');
$check('0.1.42 -> 0.1.43', snapsmack_skin_next_version('0.1.42') === '0.1.43');
$check('a two-part version works as well', snapsmack_skin_next_version('1.7') === '1.8');
$check('a non-numeric version has no successor and says so with an empty string',
       snapsmack_skin_next_version('2.0-beta') === ''
       && snapsmack_skin_next_version('') === '');

// ─────────────────────────────────────────────────────────────────────────────
echo "\nTHE PUBLISHED RECORD IS READABLE AND COVERS THE FLEET\n";
// ─────────────────────────────────────────────────────────────────────────────

$published = snapsmack_skin_published_versions($root);
$check('the fixture loads', $published['skins'] !== []);
$check('  it says where the numbers came from',
       str_contains($published['source'], 'registry.json'));
$check('  and when the registry generated them, so staleness is visible',
       $published['registry_generated'] !== '');
printf("        %d published versions recorded, registry generated %s\n",
       count($published['skins']), $published['registry_generated']);

$current = [];
foreach (snapsmack_skin_version_rows($root) as $slug => $row) {
    $current[$slug] = (string)($row['version'] ?? '');
}
$covered = count(array_intersect_key($current, $published['skins']));
printf("        %d of %d skins in the tree have a published version to check against\n",
       $covered, count($current));
$check('most of the tree is covered, so this gate is not checking two skins',
       $covered >= (int)floor(count($current) * 0.8));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAND THE TREE AS IT STANDS\n";
// ─────────────────────────────────────────────────────────────────────────────
// This is the part that will fail while a real skip is on dev, which is the
// point of the gate rather than a problem with it.

$live = snapsmack_skin_version_skip_failures($published['skins'], $current);
foreach ($live as $f) { echo '        ' . $f . "\n"; }
$check('no skin in the tree skips a published number', $live === []);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — every skin version follows straight on from what is published.\n";
echo "NOT PROVED HERE: that the recorded published numbers are still what the\n";
echo "registry serves. That is a network fact, refreshed on purpose with\n";
echo "tools/_build/refresh-skin-published-versions.php after packaging.\n";
exit(0);

// ===== SNAPSMACK EOF =====
