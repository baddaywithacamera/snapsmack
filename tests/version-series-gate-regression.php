<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * Everything stays on 0.7.x, a published version is exactly the last plus one,
 * and a re-baselined release must reach sites that hold the old bigger number.
 *
 * WHY. Sean asked for the version inflation to stop and it carried on: 19 of
 * the 30 skins are at 0.8 or above, IMPACT PRINTER reached 2.1.30. Asking is
 * not the mechanism, so this pins the rules the tools now enforce. The worked
 * examples below are HIS, relayed by the PM, and they are the acceptance test:
 * if this file disagrees with them, this file is wrong.
 */

require_once dirname(__DIR__) . '/core/constants.php';
require_once dirname(__DIR__) . '/core/version-series.php';

$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) { $fails[] = $what; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

// ---------------------------------------------------------------------------
// THE EPOCH. Without it a re-baselined release is older than what sites hold,
// and every one of them refuses the update for ever with nobody told.
// ---------------------------------------------------------------------------
$check('plain semver still compares as it always did',
    snap_version_compare('0.7.31', '0.7.30', '>')
    && !snap_version_compare('0.7.30', '0.7.31', '>'));
$check('the legacy letter suffix still works',
    snap_version_compare('0.7.9p', '0.7.9n', '>'));
$check('a version prefix is still stripped',
    snap_version_compare('Alpha 0.7.1007D', '0.7.1006', '>'));
$check('WITHOUT an epoch, 2.1.30 beats 0.7.31 -- which is the whole problem',
    snap_version_compare('2.1.30', '0.7.31', '>'));
$check('WITH an epoch, 0.7.31 is newer than 2.1.30',
    snap_version_compare('1:0.7.31', '2.1.30', '>'));
$check('an explicit epoch 0 is the same as no epoch',
    snap_version_compare('1:0.7.31', '0:2.1.30', '>'));
$check('a higher epoch wins however small the version',
    snap_version_compare('1:0.7.1', '0:99.99.99', '>'));
$check('the version decides when the epochs match',
    snap_version_compare('1:0.7.32', '1:0.7.31', '>')
    && !snap_version_compare('1:0.7.31', '1:0.7.32', '>'));
$check('an epoch is not consulted when neither side has one',
    !snap_version_compare('0.7.31', '2.1.30', '>'));
$check('whitespace around an epoch is tolerated',
    snap_version_compare(' 1 : 0.7.31 ', '2.1.30', '>'));
$check('equality still works with epochs',
    !snap_version_compare('1:0.7.31', '1:0.7.31', '>')
    && !snap_version_compare('1:0.7.31', '1:0.7.31', '<'));

// The helper the call sites use, so nobody hand-formats "1:".
$check('an entry with no epoch yields a plain version',
    snap_version_with_epoch(['version' => '0.7.31']) === '0.7.31');
$check('an entry with an epoch yields the prefixed form',
    snap_version_with_epoch(['version' => '0.7.31', 'version_epoch' => 1]) === '1:0.7.31');
$check('an epoch of 0 is not written out',
    snap_version_with_epoch(['version' => '0.7.31', 'version_epoch' => 0]) === '0.7.31');
$check('a missing version is still comparable',
    snap_version_with_epoch([]) === '0.0');
$check('the helper reads an alternate key',
    snap_version_with_epoch(['local_version' => '0.7.5'], 'local_version') === '0.7.5');

// ---------------------------------------------------------------------------
// RULE 1: THE SERIES.
// ---------------------------------------------------------------------------
$check('0.7.31 is in series', snap_version_series_problem('0.7.31') === '');
foreach (['0.8.0', '0.9.1', '1.0.0', '1.3.64', '2.1.30', '2.0.33'] as $out) {
    $check('refuses ' . $out, snap_version_series_problem($out) !== '');
}
$check('the refusal says what the limit is and who owns it',
    str_contains(snap_version_series_problem('2.1.30'), '0.8')
    && stripos(snap_version_series_problem('2.1.30'), 'Sean') !== false);
// The skins Sean named as keeping their numbers must be accepted as they are.
foreach (['0.2.79' => 'TILEZ', '0.1.36' => 'GLIDE', '0.1.82' => 'SCROLL',
          '0.1.27' => 'ONYX', '0.1.11' => 'TELEGRAM', '0.7.1007' => 'core'] as $v => $name) {
    $check($name . ' at ' . $v . ' is accepted unchanged, as Sean said it should be',
        snap_version_series_problem($v) === '' && snap_version_rebaseline($v) === null);
}
foreach (['beta', 'stable', '0.7', '0.7.x', 'v0.7.31', '0.7.9p', ''] as $notAVersion) {
    $check('refuses ' . json_encode($notAVersion) . ' as a version',
        snap_version_series_problem($notAVersion) !== '');
}
$check('the refusal says status words are not versions',
    stripos(snap_version_series_problem('stable'), 'label') !== false);

// ---------------------------------------------------------------------------
// RULE 2: EXACTLY ONE MORE THAN THE LAST PUBLISHED.
// ---------------------------------------------------------------------------
$check('0.7.31 may follow 0.7.30', snap_version_step_problem('0.7.31', '0.7.30') === '');
$check('a jump is refused', snap_version_step_problem('0.7.35', '0.7.30') !== '');
$check('republishing the same number is refused',
    snap_version_step_problem('0.7.30', '0.7.30') !== '');
$check('going backwards is refused', snap_version_step_problem('0.7.29', '0.7.30') !== '');
$check('the refusal names the number that IS allowed',
    str_contains(snap_version_step_problem('0.7.35', '0.7.30'), '0.7.31'));
$check('the first publish of a package is accepted',
    snap_version_step_problem('0.7.1', '') === '');
$check('an off-series version is refused whatever the step',
    snap_version_step_problem('0.8.31', '0.7.30') !== '');

// The re-baseline case: the last published is off-series.
$check('after 2.1.30 the only allowed version is 0.7.31',
    snap_version_step_problem('0.7.31', '2.1.30') === '');
$check('after 2.1.30, 0.7.32 is refused',
    snap_version_step_problem('0.7.32', '2.1.30') !== '');
$check('after 2.1.30, 0.7.30 is refused',
    snap_version_step_problem('0.7.30', '2.1.30') !== '');
$check('the re-baseline refusal names the epoch',
    str_contains(snap_version_step_problem('0.7.99', '2.1.30'), 'epoch'));

// ---------------------------------------------------------------------------
// THE MAPPING: Sean's own worked examples are the acceptance test.
// ---------------------------------------------------------------------------
$examples = [
    'IMPACT PRINTER' => ['2.1.30', '0.7.31'],
    'RATIONAL GEO' => ['2.1.46', '0.7.47'],
    'PHOTOGRAM' => ['2.0.33', '0.7.34'],
    '50 SHADES' => ['1.7.9', '0.7.10'],
    'ALFRED' => ['1.0.30', '0.7.31'],
    'THE GRID' => ['1.3.64', '0.7.65'],
];
foreach ($examples as $name => [$from, $to]) {
    $check($name . ' ' . $from . ' becomes ' . $to,
        snap_version_rebaseline($from) === $to);
}
$check('everything mapped stays under 1.0',
    array_filter($examples, static fn ($pair) => version_compare($pair[1], '1.0.0', '>=')) === []);
foreach (['0.7.79', '0.2.79', '0.1.11'] as $inSeries) {
    $check($inSeries . ' needs no re-baselining',
        snap_version_rebaseline($inSeries) === null
        && !snap_version_needs_rebaseline($inSeries));
}
$check('an unparseable version maps to nothing rather than guessing',
    snap_version_rebaseline('beta') === null);

// The table must keep the unchanged rows, or it cannot be checked against the
// fleet: a silently shorter list reads as "nothing else to do".
$rows = snap_version_mapping(['impact-printer' => '2.1.30', 'tilez' => '0.2.79']);
$check('the mapping keeps a row per package, changed or not', count($rows) === 2);
$byName = [];
foreach ($rows as $row) { $byName[$row['name']] = $row; }
$check('a changed row carries the epoch',
    $byName['impact-printer']['to'] === '0.7.31'
    && $byName['impact-printer']['changed'] === true
    && $byName['impact-printer']['epoch'] === SNAP_VERSION_EPOCH);
$check('an unchanged row carries no epoch and keeps its version',
    $byName['tilez']['to'] === '0.2.79'
    && $byName['tilez']['changed'] === false
    && $byName['tilez']['epoch'] === 0);
$check('every row says why', $byName['tilez']['why'] !== '' && $byName['impact-printer']['why'] !== '');

// ---------------------------------------------------------------------------
// THE BUILD LETTER. Every core release is 0.7.1007D, and the first version of
// this gate refused that outright as "not three numbers" -- so wiring the gate
// to core would have refused every core release. Muse flagged it as cosmetic
// on 8faa5eab; it was not.
// ---------------------------------------------------------------------------
$check('the core version format 0.7.1007D is in series',
    snap_version_series_problem('0.7.1007D') === '');
$check('0.7.1008D may follow 0.7.1007D',
    snap_version_step_problem('0.7.1008D', '0.7.1007D') === '');
$check('the expected next version keeps the build letter',
    str_contains(snap_version_step_problem('0.7.1009D', '0.7.1007D'), '0.7.1008D'));
$check('dropping the build letter is refused',
    snap_version_step_problem('0.7.1008', '0.7.1007D') !== '');
$check('adding a build letter is refused',
    snap_version_step_problem('0.7.32D', '0.7.31') !== '');
$check('the build letter is reported',
    snap_version_build_letter('0.7.1007D') === 'D'
    && snap_version_build_letter('0.7.31') === '');
$check('a build letter survives a re-baseline',
    snap_version_rebaseline('1.2.3D') === '0.7.4D');
$check('an off-series version with a letter is still refused',
    snap_version_series_problem('1.2.3D') !== '');
// Muse's note (b): the message named an empty version when the last published
// one could not be read. Fail closed AND legibly.
$unreadable = snap_version_step_problem('0.7.31', 'not-a-version');
$check('an unreadable last-published version is refused', $unreadable !== '');
$check('and the refusal does not name an empty version',
    !str_contains($unreadable, "'' ") && stripos($unreadable, 'cannot be read') !== false);

// ---------------------------------------------------------------------------
// AGAINST THE REAL FLEET: every skin in the build must land in series.
// ---------------------------------------------------------------------------
$manifests = glob(dirname(__DIR__) . '/skins/*/manifest.json') ?: [];
$check('all 30 skins are in the build', count($manifests) === 30);
$offSeries = [];
$mapped = [];
foreach ($manifests as $manifest) {
    $slug = basename(dirname($manifest));
    $data = json_decode((string)file_get_contents($manifest), true);
    $version = (string)($data['version'] ?? '');
    $target = snap_version_rebaseline($version) ?? $version;
    $mapped[$slug] = $target;
    if (snap_version_series_problem($target) !== '') {
        $offSeries[] = $slug . ' ' . $version . ' -> ' . $target;
    }
}
$check('EVERY skin maps into the 0.7 series: ' . ($offSeries ? implode(', ', $offSeries) : 'all 30'),
    $offSeries === []);
$check('every mapped skin stays under 1.0',
    array_filter($mapped, static fn (string $v) => version_compare($v, '1.0.0', '>=')) === []);

// A mapping must not collide two skins onto one number in a way that hides a
// mistake -- different skins may share a number, so this only reports it.
$counts = array_count_values($mapped);
$shared = array_keys(array_filter($counts, static fn (int $n) => $n > 1));
echo '     (note: ' . count($shared) . " target version(s) are shared by more than one skin, which is allowed)\n";

echo "\n";
if ($fails) {
    echo "FAILED (" . count($fails) . "):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL PASS\n";

// NOT CHECKED: that the packager, updater and installer actually CALL these
// rules. That wiring is the next commit, and it needs its own checks -- a rule
// nothing calls is the fault class that put a dead control in Configuration.

// ===== SNAPSMACK EOF =====
