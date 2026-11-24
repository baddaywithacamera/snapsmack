<?php
/**
 * SNAPSMACK - Release fleet BEFORE/AFTER safety-net regression checks.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
$failures = [];

function fleet_expect(bool $condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}

$rosterPath = $root . '/tools/_build/release-visual-sites.txt';
$hosts = array_values(array_filter(array_map('trim', file($rosterPath) ?: []),
    static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')));
$runner = file_get_contents($root . '/tools/browser-tests/release-fleet-safety-net-runner.cjs') ?: '';
$rules = file_get_contents($root . '/tools/browser-tests/release-fleet-safety-net.cjs') ?: '';
$flow = file_get_contents($root . '/tools/release-flow.php') ?: '';

fleet_expect(count($hosts) === 17, 'the canonical public fleet roster must contain exactly 17 sites');
fleet_expect(count(array_unique($hosts)) === 17, 'the canonical public fleet roster must not contain duplicates');
fleet_expect(in_array('portalproject.photoblogs.fyi', $hosts, true)
    && in_array('curbappeal.photoblogs.fyi', $hosts, true),
    'the release visual roster must include every production-skin representative');
fleet_expect(!in_array('snapsmack.ca', $hosts, true) && !in_array('photoblogs.fyi', $hosts, true),
    'product and network landing sites must not be mistaken for production-skin representatives');
foreach (['home', 'archive', 'solo'] as $pageType) {
    fleet_expect(str_contains($rules, "'{$pageType}'"), "the comparison inventory must include {$pageType} pages");
}
foreach ([1920, 1024] as $width) {
    fleet_expect(str_contains($rules, (string)$width), "the comparison inventory must include {$width}px");
}
fleet_expect(str_contains($runner, "phase === 'after'")
    && str_contains($runner, 'rollback-required.json')
    && str_contains($runner, 'process.exitCode = 2'),
    'AFTER regressions must fail closed and emit a rollback-required record');
fleet_expect(!str_contains($runner, '.click(')
    && !preg_match('/\b(post|put|patch|delete)\s*\(/i', $runner),
    'the public fleet sweep must remain read-only');
fleet_expect(str_contains($flow, "rf_fleet_capture('before', \$head)")
    && str_contains($flow, "'fleet_before' => \$fleetBefore['phase']")
    && str_contains($flow, "if (\$command === 'fleet-before' || \$command === 'fleet-after')"),
    'release-flow must capture BEFORE beside the parity gate and expose the AFTER comparison');
fleet_expect(str_contains($flow, "'fleet_before' => 'pass'")
    && str_contains($flow, "fleet_before_surface_count")
    && str_contains($flow, "fleet_before_manifest_sha256"),
    'release-gate validation must require complete, digest-bound BEFORE evidence');

$command = escapeshellarg('node') . ' '
    . escapeshellarg($root . '/tools/browser-tests/release-fleet-safety-net.cjs')
    . ' --self-test 2>&1';
exec($command, $output, $code);
fleet_expect($code === 0, 'the pure BEFORE/AFTER comparison self-test must pass: ' . implode("\n", $output));

if ($failures !== []) {
    fwrite(STDERR, "FAIL release fleet safety net:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "PASS release fleet safety net: 17 sites, 102 surfaces, read-only capture, measured comparison, and rollback refusal.\n";

// ===== SNAPSMACK EOF =====
