<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * The demo-parity record builder must never emit a record the gate refuses.
 *
 * WHY. tools/release-flow.php will not tag without --demo-parity-record, and
 * rf_demo_parity_record_problem() rejects that file on a dozen shape rules you
 * cannot see from the command line. A generator that emits a file the gate then
 * refuses has only moved the failure later, so this drives the builder and the
 * REAL validator together: whatever the builder produces must pass, and every
 * missing field must be caught by the builder rather than at tag time.
 */

require_once dirname(__DIR__) . '/tools/_build/release-demo-parity.php';
require_once dirname(__DIR__) . '/tools/_build/make-demo-parity-record.php';

$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) { $fails[] = $what; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

$sha = str_repeat('a', 40);
$good = [[
    'ticket' => 'SS-1600',
    'acceptance' => 'The control saves and the endpoint follows it.',
    'site' => 'http://127.0.0.1:56266/',
    'room_message_id' => 'abc123',
    'captures' => ['the control off', 'the control on'],
]];

// --- what the builder produces must satisfy the real gate -------------------
$record = dpr_build_record($sha, 'claude', date('c'), $good);
$check('a built record passes the real validator',
    rf_demo_parity_record_problem($record, $sha) === '');
$check('it names the exact commit it was built for', $record['commit'] === $sha);
$check('its status is pass', $record['status'] === 'pass');
$check('it carries the signer', $record['checked_by'] === 'claude');
$check('checked_at is a time that parses', strtotime($record['checked_at']) !== false);
$check('a feature defaults to result pass', $record['features'][0]['result'] === 'pass');
$check('a single capture given as a string becomes a list',
    dpr_build_record($sha, 'claude', date('c'),
        [['ticket' => 'SS-1', 'acceptance' => 'a', 'site' => 'b',
          'room_message_id' => 'c', 'captures' => 'one capture']]
    )['features'][0]['captures'] === ['one capture']);

// --- the gate must refuse every incomplete record ---------------------------
// Each of these is a field somebody would forget at one in the morning.
$cases = [
    'a missing ticket' => ['ticket' => ''],
    'a ticket that is not an SS number' => ['ticket' => 'POCKET-1'],
    'missing acceptance text' => ['acceptance' => '   '],
    'a missing site' => ['site' => ''],
    'a missing room message id' => ['room_message_id' => ''],
    'no captures at all' => ['captures' => []],
    'an empty capture reference' => ['captures' => ['']],
    'a result that is not pass' => ['result' => 'partial'],
];
foreach ($cases as $label => $override) {
    $feature = array_merge($good[0], $override);
    $broken = dpr_build_record($sha, 'claude', date('c'), [$feature]);
    $check('the gate refuses ' . $label,
        rf_demo_parity_record_problem($broken, $sha) !== '');
}
$check('the gate refuses a record naming the wrong commit',
    rf_demo_parity_record_problem($record, str_repeat('b', 40)) !== '');
$check('the gate refuses a record with no features',
    rf_demo_parity_record_problem(
        dpr_build_record($sha, 'claude', date('c'), []), $sha) !== '');
$check('the gate refuses an unsigned record',
    rf_demo_parity_record_problem(
        dpr_build_record($sha, '', date('c'), $good), $sha) !== '');

// --- the note summary the gate wants beside the record ----------------------
$summary = dpr_note_summary($record, hash('sha256', 'x'));
$check('the note summary passes the note validator',
    rf_demo_parity_note_problem($summary) === '');
$check('the note summary counts the captures', $summary['demo_parity_features'][0]['capture_count'] === 2);
$check('the note summary carries the record hash',
    $summary['demo_parity_record_sha256'] === hash('sha256', 'x'));
$check('a note summary with a zero capture count is refused',
    rf_demo_parity_note_problem(array_merge($summary, ['demo_parity_features' => [[
        'ticket' => 'SS-1600', 'site' => 'x', 'room_message_id' => 'y',
        'capture_count' => 0]]])) !== '');

// --- the SS-1600 feature file in the repository -----------------------------
// It ships with FILL IN markers on purpose: the site and the captures are facts
// only whoever ran the site can supply. It must still be structurally right,
// and it must NOT be mistakable for finished evidence.
$featuresPath = dirname(__DIR__) . '/opaudits/evidence/1007D/demo-parity-features.json';
$check('the 1007D feature file is in the repository', is_file($featuresPath));
if (is_file($featuresPath)) {
    $features = json_decode((string)file_get_contents($featuresPath), true);
    $check('it is a list of features', is_array($features) && $features !== []);
    $check('it covers both SS-1600 features', is_array($features) && count($features) === 2);
    $acceptances = array_map(static fn ($f) => (string)($f['acceptance'] ?? ''), $features ?: []);
    foreach ($acceptances as $text) {
        $check('each acceptance states what must be true, not what was built: '
            . substr($text, 0, 40) . '…', strlen($text) > 120);
    }
    $check('every ticket is an SS number',
        $features && array_filter($features, static fn ($f) => (bool)preg_match('/^SS-\d+$/', (string)($f['ticket'] ?? ''))) === $features);
    // THE GATE ACCEPTS AN UNFILLED RECORD. Measured, not assumed: the template
    // below passes rf_demo_parity_record_problem() because every field is a
    // non-empty string. That is a hole in the release gate, reported to the
    // room. My first version of this check hid it behind an `||`, which made
    // the check vacuous -- the third such of mine this month.
    $built = dpr_build_record($sha, 'nobody', date('c'), $features ?: []);
    $check('THE GATE ITSELF ACCEPTS PLACEHOLDER TEXT (this is the hole, not a pass)',
        rf_demo_parity_record_problem($built, $sha) === '');
    $check('the BUILDER refuses it instead, naming every unsupplied fact',
        dpr_placeholders_left($built) !== []);
    $check('it names the site, the room message and every capture as unsupplied',
        count(dpr_placeholders_left($built)) === 2 + 2 + 4 + 2);
    $check('a fully supplied record has nothing left to fill in',
        dpr_placeholders_left(dpr_build_record($sha, 'claude', date('c'), $good)) === []);
    foreach (['FILL IN', 'todo', 'TBD', 'xxx', 'PLACEHOLDER'] as $marker) {
        $check('the builder spots the marker ' . $marker,
            dpr_is_placeholder('the site is ' . $marker . ' something'));
    }
    $check('ordinary text is not mistaken for a placeholder',
        !dpr_is_placeholder('http://127.0.0.1:56266/ with the control reading OFF'));
    $check('the unfilled file still says FILL IN where a fact is missing',
        str_contains((string)file_get_contents($featuresPath), 'FILL IN'));
}

echo "\n";
if ($fails) {
    echo "FAILED (" . count($fails) . "):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL PASS\n";

// NOT CHECKED: the captures themselves. This proves the record cannot be
// malformed; it cannot prove anything was demonstrated on a site. That needs a
// running site, which needs a database this machine's PHP cannot reach.

// ===== SNAPSMACK EOF =====
