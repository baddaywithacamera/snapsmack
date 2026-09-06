<?php
/** Static safety regression for the one-follower push control. */
$core = file_get_contents(__DIR__ . '/../core/smackverse.php');
$admin = file_get_contents(__DIR__ . '/../core/smackverse-admin-shared.php');
$page = file_get_contents(__DIR__ . '/../smack-sv-followers.php');
$ok = function (bool $v, string $m): void { if (!$v) { fwrite(STDERR, "FAIL: $m\n"); exit(1); } };
$ok(str_contains($core, 'function sv_push_to_follower'), 'targeted push helper missing');
$ok(str_contains($core, 'WHERE actor_url = ? AND is_active = 1'), 'actor is not resolved through active followers');
$ok(str_contains($core, "['inbox_url']"), 'trusted direct inbox is not used');
$ok(str_contains($admin, "=== 'push_follower'"), 'targeted push POST handler missing');
$ok(str_contains($page, 'name="follower_actor"'), 'per-follower control missing');
echo "targeted follower push regression: OK\n";
