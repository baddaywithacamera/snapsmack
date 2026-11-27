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
// A capture is a FILE now, so the fixture names committed files and hashes
// them at runtime. Two ordinary source files stand in for screenshots, which
// keeps this test free of binaries and of writes into the tree.
$captureA = 'tests/demo-parity-record-builder-regression.php';
$captureB = 'tools/_build/make-demo-parity-record.php';
$root = dirname(__DIR__);
$good = [[
    'ticket' => 'SS-1600',
    'acceptance' => 'The control saves and the endpoint follows it.',
    'site' => 'http://127.0.0.1:56266/',
    'room_message_id' => 'abc123',
    'captures' => [
        ['path' => $captureA, 'sha256' => hash_file('sha256', $root . '/' . $captureA)],
        ['path' => $captureB, 'sha256' => hash_file('sha256', $root . '/' . $captureB)],
    ],
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
$oneString = dpr_build_record($sha, 'claude', date('c'),
    [['ticket' => 'SS-1', 'acceptance' => 'a', 'site' => 'b',
      'room_message_id' => 'c', 'captures' => $captureA]]
)['features'][0]['captures'];
$check('a capture path given as a bare string is turned into a path and a hash',
    count($oneString) === 1 && ($oneString[0]['path'] ?? '') === $captureA
    && ($oneString[0]['sha256'] ?? '') === hash_file('sha256', $root . '/' . $captureA));
$check('a capture path that is not on disk is left for the gate to name',
    !isset(dpr_build_record($sha, 'claude', date('c'),
        [['ticket' => 'SS-1', 'acceptance' => 'a', 'site' => 'b',
          'room_message_id' => 'c', 'captures' => 'tests/no-such-file.png']]
    )['features'][0]['captures'][0]['sha256']));

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
    // THE HOLE IS CLOSED. This check asserted the opposite until the PM moved
    // the gate into my lane (room 8456): the gate used to ACCEPT this record.
    // It now refuses it, and the builder refuses it too, so neither path can
    // turn a template into evidence.
    $check('THE GATE now refuses the unfilled template',
        rf_demo_parity_record_problem($built, $sha) !== '');
    $check('the BUILDER refuses it instead, naming every unsupplied fact',
        dpr_placeholders_left($built) !== []);
    $check('it names the site, the room message and every capture as unsupplied',
        count(dpr_placeholders_left($built)) === 2 + 2 + 4 + 2);
    $check('a fully supplied record has nothing left to fill in',
        dpr_placeholders_left(dpr_build_record($sha, 'claude', date('c'), $good)) === []);
    foreach (['FILL IN', 'todo', 'TBD', 'TBC', 'xxx', 'PLACEHOLDER', 'FIXME'] as $marker) {
        $check('the builder spots the marker ' . $marker,
            dpr_is_placeholder('the site is ' . $marker . ' something'));
    }
    // Muse's caveat on the first version (room 8417): it matched the exact
    // substring, so every one of these slipped past. They are the spellings a
    // template actually arrives in.
    foreach (['FILL  IN', "FILL\tIN", 'FILL-IN', 'FILL_IN', 'fill in', 'Fill In:',
              'fill   in the site'] as $spelling) {
        $check('the builder spots the spelling ' . json_encode($spelling),
            dpr_is_placeholder($spelling));
    }
    $check('a field that is nothing but FILL is a placeholder', dpr_is_placeholder('FILL'));
    $check('an empty field is a placeholder', dpr_is_placeholder('   '));
    foreach (['N/A', 'none', 'NA'] as $nothing) {
        $check('a field saying ' . $nothing . ' is a placeholder', dpr_is_placeholder($nothing));
    }
    // And the other direction, which matters more: ordinary evidence must pass.
    // SnapSmack has a feature called Generative Fill, so "fill" in a capture
    // description is legitimate and must not be refused.
    foreach (['http://127.0.0.1:56266/ with the control reading OFF',
              'the Generative Fill panel open on frame 3',
              'ALBUM SHARING reading ON after a reload',
              'the endpoint answering 404',
              'CRON & JOBS listing the POCKET ALBUM refresh'] as $real) {
        $check('real evidence is not mistaken for a placeholder: ' . substr($real, 0, 32),
            !dpr_is_placeholder($real));
    }
    $check('the unfilled file still says FILL IN where a fact is missing',
        str_contains((string)file_get_contents($featuresPath), 'FILL IN'));
}

// ---------------------------------------------------------------------------
// THE TOOL, NOT JUST ITS FUNCTIONS.
//
// Everything above loads make-demo-parity-record.php and calls its functions,
// which proves they work and NOT that the tool uses them. Mutation-checking
// found exactly that hole: deleting the placeholder refusal, and skipping the
// validator call, both left this suite green, because the tool's CLI body never
// ran. That is the "execute the contract, don't assert its shape" fault, so
// these cases run the real command in a subprocess.
// ---------------------------------------------------------------------------
$tool = dirname(__DIR__) . '/tools/_build/make-demo-parity-record.php';
$tmp = sys_get_temp_dir() . '/dpr-' . getmypid();
@mkdir($tmp, 0777, true);
$run = static function (array $args) use ($tool): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool);
    foreach ($args as $arg) { $cmd .= ' ' . escapeshellarg($arg); }
    $out = [];
    $code = 1;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};

$writeJson = static function (string $path, $data): string {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT) . "\n");
    return $path;
};

// 1. The unfilled template: the tool must refuse and write nothing.
$outPath = $tmp . '/unfilled.json';
[$code, $output] = $run([$featuresPath, $outPath, '--checked-by=claude', '--commit=' . $sha]);
$check('THE TOOL refuses the unfilled template', $code !== 0);
$check('it names an unsupplied fact when it refuses', str_contains($output, 'feature 0 site'));
$check('it writes NOTHING when it refuses', !is_file($outPath));

// 2. A record the gate's own validator would reject: a bad ticket.
$badPath = $writeJson($tmp . '/bad.json', [[
    'ticket' => 'POCKET-1', 'acceptance' => 'something', 'site' => 'http://127.0.0.1:1/',
    'room_message_id' => 'abc', 'captures' => [$captureA],
]]);
[$code, $output] = $run([$badPath, $tmp . '/bad-out.json', '--checked-by=claude', '--commit=' . $sha]);
$check('THE TOOL refuses what the gate would refuse', $code !== 0);
$check('it says which rule failed', stripos($output, 'ticket') !== false);
$check('it writes nothing for a refused record', !is_file($tmp . '/bad-out.json'));

// 3. Unsigned.
[$code, $output] = $run([$badPath, $tmp . '/unsigned.json', '--commit=' . $sha]);
$check('THE TOOL refuses an unsigned record', $code !== 0 && stripos($output, 'checked-by') !== false);

// 4. Everything supplied: it writes, and what it wrote passes the real gate.
$goodPath = $writeJson($tmp . '/good.json', [[
    'ticket' => 'SS-1600',
    'acceptance' => 'ALBUM SHARING saves and the endpoint follows it.',
    'site' => 'http://127.0.0.1:56266/',
    'room_message_id' => 'room-message-1',
    'captures' => [$captureA, $captureB],
]]);
$writtenPath = $tmp . '/good-out.json';
[$code, $output] = $run([$goodPath, $writtenPath, '--checked-by=claude', '--commit=' . $sha]);
$check('THE TOOL writes a complete record', $code === 0 && is_file($writtenPath));
$check('it prints the record hash for the note', str_contains($output, 'record sha256'));
if (is_file($writtenPath)) {
    $written = json_decode((string)file_get_contents($writtenPath), true);
    $check('what the tool actually WROTE passes the real gate',
        is_array($written) && rf_demo_parity_record_problem($written, $sha) === '');
    $check('the written record names the commit it was built for',
        is_array($written) && ($written['commit'] ?? '') === $sha);
    $check('the printed hash is the hash of the file on disk',
        str_contains($output, hash('sha256', (string)file_get_contents($writtenPath))));
}
foreach (glob($tmp . '/*') ?: [] as $leftover) { @unlink($leftover); }
@rmdir($tmp);

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
