<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

$root = dirname(__DIR__);
$tool = $root . '/tools/_build/audit-skin-presentation-parity.php';
$output = sys_get_temp_dir() . '/snapsmack-skin-parity-' . bin2hex(random_bytes(6)) . '.json';
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool)
    . ' ' . escapeshellarg('v0.7.772D') . ' ' . escapeshellarg($output);
$lines = [];
$exitCode = 0;
exec($command, $lines, $exitCode);

try {
    if ($exitCode !== 0 || !is_file($output)) {
        throw new RuntimeException('Presentation-parity audit did not produce a report.');
    }
    $report = json_decode((string)file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
    if (($report['schema_version'] ?? null) !== 1 || ($report['baseline_ref'] ?? '') !== 'v0.7.772D') {
        throw new RuntimeException('Presentation-parity audit report metadata drifted.');
    }
    if (($report['skin_count'] ?? 0) < 1 || !is_array($report['skins']['game-on']['candidate_classes'] ?? null)) {
        throw new RuntimeException('Presentation-parity audit did not inventory the current skins.');
    }
    foreach (['candidate_count', 'candidate_classes', 'baseline_php_files', 'current_php_file_count', 'current_css_file_count'] as $field) {
        if (!array_key_exists($field, $report['skins']['game-on'])) {
            throw new RuntimeException('Presentation-parity audit omitted field: ' . $field);
        }
    }
    echo "Skin presentation parity audit regression passed.\n";
} finally {
    if (is_file($output)) unlink($output);
}

// ===== SNAPSMACK EOF =====
