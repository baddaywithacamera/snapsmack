<?php
/**
 * Regression guard: the scheduled federation drain must be bounded and must
 * expose a truthful running/finished heartbeat.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
$cron = file_get_contents($root . '/cron-fediverse.php');
$fediverse = file_get_contents($root . '/core/fediverse.php');

$checks = [
    'cron records running before maintenance' => strpos($cron, "'fediverse_cron_last_status', 'running'") !== false,
    'cron records ok after maintenance' => strpos($cron, "'fediverse_cron_last_status', 'ok'") !== false,
    'cron drain supplies a 240 second budget' => preg_match('/sv_process_deliveries\([\s\S]*?null,\s*null,\s*null,\s*240\s*\)/', $cron) === 1,
    'delivery processor accepts a runtime budget' => strpos($fediverse, 'int $max_runtime_secs = 0') !== false,
    'delivery processor enforces a deadline' => strpos($fediverse, 'microtime(true) + $gap + 12 >= $deadline') !== false,
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) $failed[] = $label;
}
if ($failed) {
    fwrite(STDERR, "FAIL: " . implode('; ', $failed) . "\n");
    exit(1);
}
echo "PASS: federation cron is bounded and reports its state truthfully.\n";

// ===== SNAPSMACK EOF =====
