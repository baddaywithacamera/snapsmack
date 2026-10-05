<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

$root = dirname(__DIR__);
$tool = $root . '/tools/_build/audit-public-skin-route-coverage.php';
$output = sys_get_temp_dir() . '/snapsmack-route-coverage-' . bin2hex(random_bytes(6)) . '.json';
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' ' . escapeshellarg($output);
$lines = [];
$exitCode = 0;
exec($command, $lines, $exitCode);

try {
    if ($exitCode !== 0 || !is_file($output)) {
        throw new RuntimeException('Public route-coverage audit did not produce a report.');
    }
    $report = json_decode((string)file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
    if (($report['schema_version'] ?? null) !== 1 || ($report['skin_count'] ?? 0) < 1) {
        throw new RuntimeException('Public route-coverage audit metadata drifted.');
    }
    foreach (['game-on', 'aurora', 'parade', 'jive-turkey'] as $skin) {
        $entry = $report['skins'][$skin] ?? null;
        if (!is_array($entry) || !isset($entry['routes']['landing'], $entry['routes']['not_found'])) {
            throw new RuntimeException('Public route-coverage audit omitted first-batch skin: ' . $skin);
        }
        foreach ($entry['routes'] as $route) {
            foreach (['declared_template', 'covered', 'marker_present', 'not_found_fallthrough', 'warning_count', 'warnings'] as $field) {
                if (!array_key_exists($field, $route)) {
                    throw new RuntimeException('Public route-coverage audit omitted route field: ' . $field);
                }
            }
        }
    }
    echo "Public skin route coverage audit regression passed.\n";
} finally {
    if (is_file($output)) unlink($output);
}

// ===== SNAPSMACK EOF =====
