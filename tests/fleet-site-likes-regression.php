<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1543. Fleet Stats must not call ActivityPub likes every kind of like.
 * Existing snap_likes rows travel through the normal enriched spoke pull and
 * appear in a separate SITE LIKES tile beside FEDIVERSE LIKES.
 */

$root = dirname(__DIR__);
$api = (string)file_get_contents($root . '/core/multisite-api.php');
$page = (string)file_get_contents($root . '/smack-multisite-stats.php');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $message . PHP_EOL;
    if (!$condition) $failures[] = $message;
};

$check(str_contains($api, '"SELECT COUNT(*) FROM snap_likes"'),
    'the enriched stats endpoint counts existing on-site heart rows');
$check(str_contains($api, "\$payload['site_likes']"),
    'the spoke response names the count site_likes');
$check(str_contains($api, "\$payload['site_likes'] = 0"),
    'an older site without the table fails soft instead of breaking fleet stats');
$check(str_contains($page, "'site_likes'   => (int)(\$resp['site_likes'] ?? 0)"),
    'the existing multisite stats pull reads each spoke count');
$check(str_contains($page, '$hub_site_likes = (int)$pdo->query("SELECT COUNT(*) FROM snap_likes")'),
    'the hub contributes its own existing heart rows');
$check(str_contains($page, '$fleet_site_likes  += (int)($ss[\'site_likes\'] ?? 0)'),
    'the fleet rollup adds every spoke count');
$check(substr_count($page, 'SITE LIKES') === 1,
    'exactly one SITE LIKES tile is rendered');
$check(substr_count($page, 'FEDIVERSE LIKES') === 1,
    'the old ambiguous tile is exactly FEDIVERSE LIKES');
$check(!str_contains($page, '>LIKES RECEIVED<'),
    'the misleading LIKES RECEIVED label is gone');
$check(strpos($page, 'FEDIVERSE LIKES') < strpos($page, 'SITE LIKES'),
    'the two kinds of likes sit beside one another');

echo (count($failures) ? 'FAIL' : 'PASS')
    . ': Fleet Stats separates site hearts from fediverse likes (SS-1543)' . PHP_EOL;
exit(count($failures) ? 1 : 0);
// ===== SNAPSMACK EOF =====
