<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

require_once dirname(__DIR__) . '/tools/_build/release-demo-parity.php';

$failures = 0;
function rdpg_check(bool $ok, string $what): void
{
    global $failures;
    if ($ok) return;
    $failures++;
    fwrite(STDERR, "FAIL: {$what}\n");
}

$head = str_repeat('a', 40);
$record = [
    'commit' => $head,
    'status' => 'pass',
    'checked_by' => 'Sean',
    'checked_at' => '2026-11-23T12:00:00-05:00',
    'features' => [[
        'ticket' => 'SS-1543',
        'acceptance' => 'SITE LIKES and FEDIVERSE LIKES show the right totals.',
        'site' => 'https://example.test/smack-multisite-stats.php',
        'result' => 'pass',
        'room_message_id' => 'room-proof-1543',
        'captures' => ['capture-1920.png', 'capture-1024.png'],
    ]],
];

rdpg_check(rf_demo_parity_record_problem($record, $head) === '',
    'a complete exact-commit real-site record did not pass');

$cases = [
    'wrong commit' => ['commit', str_repeat('b', 40)],
    'failed result' => ['features.0.result', 'fail'],
    'missing acceptance text' => ['features.0.acceptance', ''],
    'missing site' => ['features.0.site', ''],
    'missing room proof' => ['features.0.room_message_id', ''],
    'missing captures' => ['features.0.captures', []],
];
foreach ($cases as $label => [$path, $value]) {
    $bad = $record;
    $parts = explode('.', $path);
    if (count($parts) === 1) {
        $bad[$parts[0]] = $value;
    } else {
        $bad[$parts[0]][(int)$parts[1]][$parts[2]] = $value;
    }
    rdpg_check(rf_demo_parity_record_problem($bad, $head) !== '',
        "the gate accepted {$label}");
}

$empty = $record;
$empty['features'] = [];
rdpg_check(rf_demo_parity_record_problem($empty, $head) !== '',
    'the gate accepted a record with no changed features');

$raw = json_encode($record, JSON_UNESCAPED_SLASHES);
$fields = rf_demo_parity_note_fields($record, $raw);
rdpg_check(rf_demo_parity_note_problem($fields) === '',
    'the exact-commit record did not produce a valid durable gate summary');
rdpg_check(($fields['demo_parity_record_sha256'] ?? '') === hash('sha256', $raw),
    'the gate note does not bind the supplied parity record by digest');
rdpg_check(($fields['demo_parity_features'][0]['capture_count'] ?? 0) === 2,
    'the gate note did not retain the capture count');

$missingDigest = $fields;
unset($missingDigest['demo_parity_record_sha256']);
rdpg_check(rf_demo_parity_note_problem($missingDigest) !== '',
    'the durable note accepts a missing record digest');

$flow = (string)file_get_contents(dirname(__DIR__) . '/tools/release-flow.php');
rdpg_check(str_contains($flow, '--demo-parity-record=<json file>'),
    'gate-note no longer requires the real-site parity record explicitly');
rdpg_check((bool)preg_match('/rf_demo_parity_record_problem\(.*?rf_browser_tests\(\)/s', $flow),
    'gate-note runs the browsers before refusing an invalid parity record');

if ($failures > 0) {
    fwrite(STDERR, "\nRelease demo-parity gate FAILED with {$failures} problem(s).\n");
    exit(1);
}
print("Release demo-parity gate: exact commit, acceptance, real site, room proof and captures required.\n");
exit(0);
// ===== SNAPSMACK EOF =====
