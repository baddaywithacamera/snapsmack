<?php
/** Static safety regression for the one-follower push control.
 *
 * Successor to smackverse-targeted-push-regression.php — the 652D merge carried
 * the old-name test (and the followers-page button) but the smackverse→fediverse
 * rename had dropped the backend; this pins the recovered feature under the
 * current filenames so it can never silently vanish again.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$core  = file_get_contents(__DIR__ . '/../core/fediverse.php');
$admin = file_get_contents(__DIR__ . '/../core/fediverse-admin-shared.php');
$page  = file_get_contents(__DIR__ . '/../smack-sv-followers.php');
$ok = function (bool $v, string $m): void { if (!$v) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } };
$ok(str_contains($core, 'function sv_push_to_follower'), 'targeted push helper missing');
$ok(str_contains($core, 'WHERE actor_url = ? AND is_active = 1'), 'actor is not resolved through active followers');
$ok(str_contains($core, "['inbox_url']"), 'trusted direct inbox is not used');
$ok(str_contains($core, "fediverse_backfill_count"), 'default count must read the CURRENT setting name');
$ok(str_contains($core, '$order_by = "priority ASC, id ASC"'), 'delivery drain is not ordered by explicit priority');
$ok(str_contains($core, 'SELECT id, inbox_url, priority FROM snap_ap_deliveries'), 'paced delivery plan does not retain priority');
$ok(str_contains($core, '$candidate[\'priority\'] < $current[\'priority\']'), 'paced cross-host picker can put backfills ahead of live work');
$ok(str_contains($core, '$type === \'Announce\' ? 5 : 10'), 'boosts are not prioritized ahead of live posts');
$ok(substr_count($core, 'null, 100)') >= 5, 'bulk backfill writers are not demoted behind live posts');
$ok(str_contains($core, 'sv_queue_delivery($pdo, $inbox, $payload, null, 10)'), 'one-follower repair is not promoted with live posts');
$ok(str_contains($core, 'priority = LEAST(priority, VALUES(priority))'), 're-queueing cannot promote an existing duplicate');
$ok(str_contains($core, "status = 'queued', attempts = 0"), 'duplicate promotion does not restore the queue schema status');
$ok(str_contains($admin, "=== 'push_follower'"), 'targeted push POST handler missing');
$ok(str_contains($admin, "fediverse-kick.php"), 'delivery kick must use the renamed kick module');
$ok(str_contains($page, 'name="follower_actor"'), 'per-follower control missing');
$ok(!str_contains($page, 'smackverse_backfill_count'), 'followers page still reads a stranded smackverse_* setting');
echo "targeted follower push regression: OK\n";

// ===== SNAPSMACK EOF =====
