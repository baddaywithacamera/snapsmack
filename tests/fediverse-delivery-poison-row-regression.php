<?php
/** Regression: malformed legacy activity JSON cannot kill the whole cron run. */
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
$source = file_get_contents(__DIR__ . '/../core/fediverse.php');
$checks = [
    'queue ordering does not invoke JSON_EXTRACT on untrusted legacy rows'
        => strpos($source, "JSON_EXTRACT(activity_json") === false,
    'handshake priority remains without JSON parsing'
        => strpos($source, "activity_json REGEXP") !== false
           && strpos($source, "(Accept|Reject|Follow|Undo)") !== false,
    'one delivery exception is contained'
        => strpos($source, "'delivery worker error: '") !== false,
    'CLI DNS preflight is bounded before curl begins'
        => strpos($source, 'function sv_resolve_host_bounded') !== false
           && strpos($source, "' 5 '") !== false,
];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}
echo 'OK — ' . count($checks) . " asserts\n";
// ===== SNAPSMACK EOF =====
