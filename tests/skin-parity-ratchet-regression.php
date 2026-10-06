<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// SECAUDIT 060 finding A: the security half of the skin boundary is fail-closed,
// but the parity half only proved the audit tools still ran. This program makes
// the parity half fail-closed too. Known-missing behaviour is recorded once in
// tests/fixtures/skin-parity-ratchet-baseline.json; it may shrink but never grow.
// A fixed entry must be removed from the baseline in the same change, so parity
// cannot silently regress back to a number the gate already tolerated.

$root = dirname(__DIR__);
$baselinePath = $root . '/tests/fixtures/skin-parity-ratchet-baseline.json';
$baseline = json_decode((string)file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
if (($baseline['schema_version'] ?? null) !== 1) {
    throw new RuntimeException('Parity ratchet baseline schema drifted.');
}

/** Run a build audit and return its decoded report. */
$runAudit = static function (string $tool, array $args) use ($root): array {
    $output = sys_get_temp_dir() . '/snapsmack-parity-' . bin2hex(random_bytes(6)) . '.json';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/_build/' . $tool);
    foreach ($args as $arg) $command .= ' ' . escapeshellarg($arg);
    $command .= ' ' . escapeshellarg($output);
    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);
    try {
        if ($exitCode !== 0 || !is_file($output)) {
            throw new RuntimeException($tool . ' did not produce a report.');
        }
        return json_decode((string)file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
    } finally {
        if (is_file($output)) unlink($output);
    }
};

$failures = [];

// 1. Declared routes must reach their skin. New fallthroughs are regressions.
$routes = $runAudit('audit-public-skin-route-coverage.php', []);
$allowed = $baseline['uncovered_routes'] ?? [];
$observed = [];
foreach (($routes['skins'] ?? []) as $skin => $entry) {
    foreach (($entry['routes'] ?? []) as $route => $info) {
        if (empty($info['covered'])) $observed[$skin][] = $route;
    }
    if (isset($observed[$skin])) sort($observed[$skin], SORT_STRING);
}
foreach ($observed as $skin => $list) {
    foreach ($list as $route) {
        if (!in_array($route, $allowed[$skin] ?? [], true)) {
            $failures[] = "NEW declared-route fallthrough: {$skin}/{$route} renders no bounded content.";
        }
    }
}
foreach ($allowed as $skin => $list) {
    foreach ($list as $route) {
        if (!in_array($route, $observed[$skin] ?? [], true)) {
            $failures[] = "FIXED route still listed as known-missing: {$skin}/{$route} — delete it from the parity baseline.";
        }
    }
}

// 2. Pre-migration presentation hooks that current CSS still styles but no
//    template emits. The count may fall; it may not rise.
$presentation = $runAudit('audit-skin-presentation-parity.php', [(string)$baseline['baseline_ref']]);
foreach (($presentation['skins'] ?? []) as $skin => $entry) {
    $recorded = (int)($baseline['presentation_candidates'][$skin] ?? 0);
    $current = (int)($entry['candidate_count'] ?? 0);
    if ($current > $recorded) {
        $failures[] = "Presentation parity regressed for {$skin}: {$current} orphaned hooks against a baseline of {$recorded}.";
    }
}
$presentationTotal = array_sum(array_map(
    static fn (array $entry): int => (int)($entry['candidate_count'] ?? 0),
    $presentation['skins'] ?? []
));
if ($presentationTotal > (int)$baseline['presentation_candidate_total']) {
    $failures[] = 'Fleet presentation-parity candidate total rose above the recorded baseline.';
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

printf(
    "Skin parity ratchet: PASS (%d known route fallthroughs, %d known orphaned presentation hooks).\n",
    (int)$baseline['route_fallthrough_total'],
    $presentationTotal
);

// ===== SNAPSMACK EOF =====
