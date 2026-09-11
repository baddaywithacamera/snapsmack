<?php
/**
 * Delivery-log ordering must accurately expose the worker's service classes.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
$source = file_get_contents(__DIR__ . '/../smack-sv-delivery-log.php');
$checks = [
    'queue display mirrors worker priority order' => str_contains($source, 'ORDER BY priority ASC, id ASC'),
    'queue display includes priority' => str_contains($source, 'created_at, priority'),
    'queue totals are not derived from capped rows' => str_contains($source, "SUM(status = 'queued') AS queued_count")
        && !str_contains($source, "foreach (\$queue as \$q) { (\$q['status'] === 'failed')"),
    'priority classes make backfills explicit' => str_contains($source, "return 'backfill';")
        && str_contains($source, 'backfills dead last'),
];

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? "PASS" : "FAIL") . ": {$label}\n";
    if (!$ok) $failed++;
}
exit($failed > 0 ? 1 : 0);

// ===== SNAPSMACK EOF =====
