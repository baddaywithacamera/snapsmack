<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1541. Hearts are per-photograph toggles, not a metered action. A visitor
 * may like any number of different photographs. The existing user id or
 * salted guest IP hash remains the only identity used for each toggle.
 */

$root = dirname(__DIR__);
$endpoint = (string)file_get_contents($root . '/process-like.php');
$legacyEndpoint = (string)file_get_contents($root . '/process-reaction.php');
$settings = (string)file_get_contents($root . '/smack-community-settings.php');
$identity = (string)file_get_contents($root . '/core/like-identity.php');
$session = (string)file_get_contents($root . '/core/community-session.php');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(!str_contains($endpoint, "community_rate_limit('likes')"),
    'the like endpoint has no hourly likes gate');
$check(!str_contains($endpoint, "['error' => 'rate_limited']"),
    'the like endpoint cannot return the removed cap response');
$check(str_contains($legacyEndpoint, "http_response_code(410)"),
    'the retired reaction endpoint cannot meter or accept a heart');
$check(!str_contains($legacyEndpoint, "community_rate_limit('likes')"),
    'the retired reaction endpoint has no leftover likes bucket gate');
$check(!str_contains($settings, "'rate_limit_likes'"),
    'the settings save path no longer persists a likes cap');
$check(!str_contains($settings, 'LIKES / HOUR'),
    'the admin page no longer offers a likes cap');

foreach (['comments', 'signups', 'logins', 'resets'] as $action) {
    $check(str_contains($settings, "'rate_limit_{$action}'"),
        "the {$action} abuse-control setting remains");
}
$check(str_contains($session, 'function community_rate_limit(string $action): bool'),
    'the shared limiter remains for every other protected action');

$check(str_contains($endpoint, 'SELECT id FROM snap_likes WHERE post_id = ? AND user_id = ?'),
    'authenticated hearts still find the existing per-photo toggle');
$check(str_contains($endpoint, 'SELECT id FROM snap_likes WHERE post_id = ? AND guest_hash = ?'),
    'guest hearts still find the existing per-photo toggle');
$check(str_contains($endpoint, 'DELETE FROM snap_likes WHERE id = ?'),
    'a second tap still removes the existing like');
$check(str_contains($endpoint, 'INSERT IGNORE INTO snap_likes (post_id, user_id)'),
    'an authenticated tap still adds one like');
$check(str_contains($endpoint, 'INSERT IGNORE INTO snap_likes (post_id, guest_hash)'),
    'a guest tap still adds one like');
$check(str_contains($identity, "hash('sha256', \$ip . \$salt)"),
    'guest identity remains the existing salted IP hash');
$check(!preg_match('/\b(?:setcookie|fingerprint)\s*\(/i', $identity),
    'the identity helper adds no cookie or fingerprint operation');

echo (count($failures) ? 'FAIL' : 'PASS')
    . ': likes remain unlimited per visitor and toggle per photograph (SS-1541)' . PHP_EOL;
exit(count($failures) ? 1 : 0);
// ===== SNAPSMACK EOF =====
