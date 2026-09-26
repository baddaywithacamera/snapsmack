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
    'queue display mirrors worker priority order' => str_contains($source, "ORDER BY (attempts > 0 OR (last_error IS NOT NULL AND last_error <> '')) ASC, priority ASC, id ASC"),
    'queue display includes priority' => str_contains($source, 'created_at, priority'),
    'queue totals are not derived from capped rows' => str_contains($source, "SUM(status = 'queued' AND attempts = 0")
        && !str_contains($source, "foreach (\$queue as \$q) { (\$q['status'] === 'failed')"),
    'priority classes make backfills explicit' => str_contains($source, "return 'routine update';")
        && str_contains($source, "return 'backfill';")
        && str_contains($source, 'backfills within each group'),
];

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? "PASS" : "FAIL") . ": {$label}\n";
    if (!$ok) $failed++;
}
exit($failed > 0 ? 1 : 0);

// ===== SNAPSMACK EOF =====
