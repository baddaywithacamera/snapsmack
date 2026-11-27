<?php
declare(strict_types=1);

/**
 * Write the real-site feature-parity record a release tag demands.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * WHY THIS EXISTS. tools/release-flow.php refuses to tag without
 * --demo-parity-record=<json file>, and rf_demo_parity_record_problem()
 * rejects the file on a dozen separate shape rules: the exact release commit,
 * a signer, a timestamp that parses, and per feature a ticket matching
 * SS-\d+, acceptance text, a site, a room message id, result 'pass', and at
 * least one non-empty capture. Hand-writing that JSON at tag time, against a
 * validator you cannot see, is how an evening gets spent on a typo.
 *
 * So this builds the record from a features file and the CURRENT HEAD, and
 * then hands what it built to the REAL validator before writing anything. If
 * the gate would refuse it, this refuses it first and says which rule.
 *
 * IT DOES NOT CAPTURE ANYTHING AND IT DOES NOT DECIDE ANYTHING. The captures
 * are made by whoever runs the site, and the acceptance text is a judgement
 * the author of the change writes down. This only assembles and checks.
 *
 *   php tools/_build/make-demo-parity-record.php <features.json> <out.json>
 *       [--checked-by=name] [--commit=sha]
 *
 * The features file is a JSON list; each entry needs ticket, acceptance, site,
 * room_message_id and captures, and may state result (default 'pass').
 */

require_once __DIR__ . '/release-demo-parity.php';

function dpr_fail(string $message): void
{
    fwrite(STDERR, "demo-parity record: {$message}\n");
    exit(1);
}

/**
 * Markers that mean "a human has not supplied this fact yet".
 *
 * MEASURED, not assumed: rf_demo_parity_record_problem() checks only that a
 * field is a non-empty string, so a record whose site is "FILL IN: the local
 * site" and whose captures are "FILL IN: the control reading OFF" PASSES the
 * release gate. I found that by testing my own feature template against the
 * real validator -- it accepted it. So the gate cannot tell evidence from a
 * placeholder, and until it can, this builder refuses to write one.
 *
 * Reported to the room for the gate itself; this is the narrow fix in my lane.
 */
const DPR_PLACEHOLDERS = ['FILL IN', 'FILLIN', 'TODO', 'TBD', 'XXX', '<fill', 'PLACEHOLDER'];

/** Does this text still read as an unsupplied fact? */
function dpr_is_placeholder(string $text): bool
{
    $upper = strtoupper($text);
    foreach (DPR_PLACEHOLDERS as $marker) {
        if (str_contains($upper, strtoupper($marker))) {
            return true;
        }
    }
    return false;
}

/**
 * Every placeholder left in a record, as "feature N field" strings.
 *
 * @return list<string>
 */
function dpr_placeholders_left(array $record): array
{
    $left = [];
    foreach (($record['features'] ?? []) as $index => $feature) {
        if (!is_array($feature)) { continue; }
        foreach (['ticket', 'acceptance', 'site', 'room_message_id'] as $field) {
            if (dpr_is_placeholder((string)($feature[$field] ?? ''))) {
                $left[] = "feature {$index} {$field}";
            }
        }
        foreach (($feature['captures'] ?? []) as $position => $capture) {
            if (dpr_is_placeholder((string)$capture)) {
                $left[] = "feature {$index} capture {$position}";
            }
        }
    }
    return $left;
}

/**
 * Assemble a record. Pure, so a test can drive it without a filesystem.
 *
 * @param list<array<string,mixed>> $features
 */
function dpr_build_record(string $commit, string $checkedBy, string $checkedAt, array $features): array
{
    $out = [];
    foreach ($features as $feature) {
        if (!is_array($feature)) {
            dpr_fail('every feature must be an object');
        }
        $captures = $feature['captures'] ?? [];
        if (is_string($captures)) {
            $captures = [$captures];
        }
        $out[] = [
            'ticket' => trim((string)($feature['ticket'] ?? '')),
            'acceptance' => trim((string)($feature['acceptance'] ?? '')),
            'site' => trim((string)($feature['site'] ?? '')),
            'room_message_id' => trim((string)($feature['room_message_id'] ?? '')),
            'result' => (string)($feature['result'] ?? 'pass'),
            'captures' => array_values(array_map(
                static fn ($capture): string => trim((string)$capture),
                is_array($captures) ? $captures : []
            )),
        ];
    }
    return [
        'commit' => $commit,
        'status' => 'pass',
        'checked_by' => $checkedBy,
        'checked_at' => $checkedAt,
        'features' => $out,
    ];
}

/** The note summary the gate wants beside the record, derived from it. */
function dpr_note_summary(array $record, string $recordSha256): array
{
    $features = [];
    foreach (($record['features'] ?? []) as $feature) {
        $features[] = [
            'ticket' => (string)($feature['ticket'] ?? ''),
            'site' => (string)($feature['site'] ?? ''),
            'room_message_id' => (string)($feature['room_message_id'] ?? ''),
            'capture_count' => count($feature['captures'] ?? []),
        ];
    }
    return [
        'demo_parity' => 'pass',
        'demo_parity_record_sha256' => $recordSha256,
        'demo_parity_features' => $features,
    ];
}

if (PHP_SAPI !== 'cli' || basename(__FILE__) !== basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    return;   // included by a test
}

$args = array_slice($argv, 1);
$flags = [];
$positional = [];
foreach ($args as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) === 1) {
        $flags[$m[1]] = $m[2];
    } else {
        $positional[] = $arg;
    }
}
if (count($positional) !== 2) {
    dpr_fail('usage: make-demo-parity-record.php <features.json> <out.json> '
        . '[--checked-by=name] [--commit=sha]');
}
[$featuresPath, $outPath] = $positional;

$raw = @file_get_contents($featuresPath);
$features = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($features)) {
    dpr_fail("could not read a JSON feature list from {$featuresPath}");
}
if (isset($features['features']) && is_array($features['features'])) {
    $features = $features['features'];   // accept a whole record as input too
}

$commit = (string)($flags['commit'] ?? '');
if ($commit === '') {
    $commit = trim((string)@shell_exec('git rev-parse HEAD'));
}
if (preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
    dpr_fail('could not determine the exact release commit; pass --commit=<40 hex>');
}

$checkedBy = trim((string)($flags['checked-by'] ?? ''));
if ($checkedBy === '') {
    dpr_fail('--checked-by is required: a record with no signer is not evidence');
}

$record = dpr_build_record($commit, $checkedBy, date('c'), $features);

// THE REAL VALIDATOR, before anything is written. A generator that emits a
// file the gate then rejects has only moved the failure later.
$problem = rf_demo_parity_record_problem($record, $commit);
if ($problem !== '') {
    dpr_fail($problem);
}

// AND THE CHECK THE GATE DOES NOT MAKE. The gate accepts any non-empty string,
// so an untouched template passes it. An unsupplied fact must not become
// evidence just because it is spelled out in words.
$left = dpr_placeholders_left($record);
if ($left !== []) {
    dpr_fail("these facts have not been supplied yet, so nothing was written:\n  - "
        . implode("\n  - ", $left)
        . "\n  Fill in the site, the room message id and each capture from the run that was actually made.");
}

$encoded = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (@file_put_contents($outPath, $encoded) === false) {
    dpr_fail("could not write {$outPath}");
}

$sha = hash('sha256', $encoded);
fwrite(STDOUT, "wrote {$outPath}\n");
fwrite(STDOUT, "commit        {$commit}\n");
fwrite(STDOUT, "record sha256 {$sha}\n");
fwrite(STDOUT, "features      " . count($record['features']) . "\n\n");
fwrite(STDOUT, "Fields for the release-gate note:\n");
fwrite(STDOUT, json_encode(dpr_note_summary($record, $sha),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// ===== SNAPSMACK EOF =====
