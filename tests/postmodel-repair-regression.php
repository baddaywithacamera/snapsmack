<?php
/**
 * Audit 049 — static guardrails for the Maintenance post-model repair.
 * The live migration is deliberately not run by the test suite.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$source = (string)file_get_contents(dirname(__DIR__) . '/smack-maintenance.php');
$handler_start = strpos($source, "if (\$action === 'postmodel_repair')");
$handler_end = strpos($source, '// VAX INJECTOR', $handler_start === false ? 0 : $handler_start);
$handler = ($handler_start !== false && $handler_end !== false)
    ? substr($source, $handler_start, $handler_end - $handler_start)
    : '';

function pmr_expect(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

pmr_expect($handler !== '', 'repair action is registered');
pmr_expect(strpos($handler, 'reauth_verify') === false, 'repair does not require per-site step-up authentication');
pmr_expect(strpos($handler, '$pdo->beginTransaction()') !== false, 'repair starts a transaction');
pmr_expect(strpos($handler, '$pdo->commit()') !== false, 'repair commits on success');
pmr_expect(strpos($handler, '$pdo->rollBack()') !== false, 'repair rolls back on failure');
pmr_expect(strpos($handler, 'i.post_id IS NULL') !== false, 'repair selects only unattached images');
pmr_expect(strpos($handler, 'NOT EXISTS (') !== false && strpos($handler, 'snap_post_images spi') !== false, 'repair excludes existing pivots');
pmr_expect(strpos($handler, "post_type, status, created_at") !== false, 'canonical post lifecycle fields are copied');
pmr_expect(strpos($handler, "fedi_published_at, is_sensitive, content_warning") !== false, 'federation metadata is copied');
pmr_expect(strpos($handler, "'fit', 50, 50, 100") !== false, 'canonical single-image pivot defaults are used');
pmr_expect(strpos($handler, 'UPDATE snap_images SET post_id = ? WHERE id = ? AND post_id IS NULL') !== false, 'image attachment update is guarded');
pmr_expect(strpos($source, 'CONVERT PHOTOS TO POSTS') !== false, 'Maintenance button is present');
pmr_expect(strpos($source, 'name="reauth_password"') === false || strpos($handler, 'reauth_password') === false,
    'repair form does not request a password');
pmr_expect(strpos($handler, 'snap_likes') === false && strpos($handler, 'snap_reactions') === false
    && strpos($handler, 'snap_community_comments') === false, 'repair does not remap engagement');

echo "PASS: post-model repair regression suite\n";
// ===== SNAPSMACK EOF =====
