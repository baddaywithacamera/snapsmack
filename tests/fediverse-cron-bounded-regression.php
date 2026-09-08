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
    'delivery processor enforces a deadline' => strpos($fediverse, 'microtime(true) + 12 >= $deadline') !== false,
    // Throughput fix: pacing is per receiving HOST, and the old global
    // sleep-before-every-send ($gap) is gone. A regression to a global sleep
    // reopens the ~23/run starvation this build removed.
    'delivery pacing is keyed per receiving host' => strpos($fediverse, '$next_allowed[$pick]') !== false
        && strpos($fediverse, 'sv_normalize_delivery_host(') !== false,
    'no global sleep-before-every-send survives' => strpos($fediverse, 'microtime(true) + $gap + 12 >= $deadline') === false,
    // A rate-limited receiver (429) must rest the whole host (honouring
    // Retry-After) and must NOT count toward the 8-try park cliff, or a busy
    // peer silently drops good posts.
    'delivery honours a 429 rate limit per host' => strpos($fediverse, "'retry_after'") !== false
        && strpos($fediverse, '$is_429') !== false
        && strpos($fediverse, "'cooldown'") !== false,
    'CLI drains before mesh and optional network maintenance' =>
        strpos($cron, 'list($sent, $failed) = sv_process_deliveries(') < strpos($cron, '$mesh_follow = sv_reconcile_mesh_follows('),
    'web runner drains before mesh and optional network maintenance' =>
        strpos($fediverse, 'list($sent, $failed) = sv_process_deliveries(') < strpos($fediverse, '$mesh_follow = sv_reconcile_mesh_follows('),
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
