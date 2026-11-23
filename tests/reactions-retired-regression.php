<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1537. Emoji reactions are retired without deleting their historical
 * rows. The single-heart path remains the only public engagement write.
 */

$root = dirname(__DIR__);
$endpoint = (string)file_get_contents($root . '/process-reaction.php');
$settings = (string)file_get_contents($root . '/smack-community-settings.php');
$component = (string)file_get_contents($root . '/core/community-component.php');
$communityJs = (string)file_get_contents($root . '/assets/js/ss-engine-community.js');
$photogramJs = (string)file_get_contents($root . '/assets/js/ss-engine-photogram.js');
$manifestLoader = (string)file_get_contents($root . '/core/skin-manifest.php');
$installer = (string)file_get_contents($root . '/install.php');
$schema = (string)file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
$migration = (string)file_get_contents($root . '/migrations/migrate-reactions-to-likes.sql');
$inventory = (string)file_get_contents($root . '/core/tyswy-api.php');
$likeEndpoint = (string)file_get_contents($root . '/process-like.php');
$communityUsers = (string)file_get_contents($root . '/smack-community-users.php');

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . ': ' . $message . PHP_EOL;
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(str_contains($endpoint, "http_response_code(410)"),
    'the retired endpoint returns HTTP 410');
$check(str_contains($endpoint, "['error' => 'reactions_retired']"),
    'the retired endpoint names the removed feature clearly');
$check(!preg_match('/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM|SELECT\b[^;]*\bFROM)\s+snap_reactions\b/i', $endpoint),
    'the retired endpoint cannot read or mutate historical reaction rows');

foreach (['community_reactions_enabled', 'community_active_reactions', 'community_allow_dislike',
          'active_reactions[]', 'reaction-picker-grid'] as $removed) {
    $check(!str_contains($settings, $removed),
        "community settings no longer expose or save {$removed}");
}

$check(!str_contains($component, 'show_reactions')
    && !str_contains($component, 'snap_reactions')
    && !str_contains($component, 'ss-reaction'),
    'the shared component has no reaction query or picker path');
$check(!str_contains($communityJs, 'process-reaction.php')
    && !str_contains($communityJs, 'ss-reaction')
    && !str_contains($communityJs, 'ss-cdock-picker'),
    'the shared browser engine has no reaction client path');
$check(str_contains($photogramJs, "post('process-like.php', { post_id: imageId }")
    && str_contains($photogramJs, 'typeof data.count'),
    'Photogram hearts use the single-heart endpoint and response shape');
$check(!str_contains($manifestLoader, "'community_reactions'"),
    'skin manifests can no longer enable the retired feature');
$check(!str_contains($installer, "'community_reactions_enabled'")
    && str_contains($installer, 'CREATE TABLE IF NOT EXISTS `{$prefix}reactions`'),
    'new installs omit the dead setting but retain the historical table');

$check(str_contains($schema, 'CREATE TABLE IF NOT EXISTS `snap_reactions`'),
    'canonical schema preserves historical reaction rows');
$check(str_contains($migration, 'NON-DESTRUCTIVE: snap_reactions is left in place'),
    'the reactions-to-likes migration remains explicitly non-destructive');
$check(str_contains($inventory, "'table' => 'snap_reactions'"),
    'the owner export continues to inventory preserved reaction rows');
$check(str_contains($likeEndpoint, 'INSERT IGNORE INTO snap_likes')
    && str_contains($likeEndpoint, 'DELETE FROM snap_likes WHERE id = ?'),
    'the single-heart add/remove path remains intact');

$runtimeFiles = glob($root . '/*.php') ?: [];
foreach (['core', 'assets', 'skins'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && in_array($file->getExtension(), ['php', 'js'], true)) {
            $runtimeFiles[] = str_replace('\\', '/', $file->getPathname());
        }
    }
}
$reactionWriters = [];
foreach ($runtimeFiles as $path) {
    $relative = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
    if ($relative === 'smack-community-users.php') {
        continue;
    }
    $source = (string)file_get_contents($path);
    if (preg_match('/\b(?:INSERT\s+(?:IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM)\s+snap_reactions\b/i', $source)) {
        $reactionWriters[] = $relative;
    }
}
$check($reactionWriters === [],
    'no direct reaction-feature SQL writer remains outside account deletion');
$check(str_contains($communityUsers, "'snap_likes', 'snap_reactions'")
    && str_contains($communityUsers, 'DELETE FROM {$table} WHERE user_id = ?'),
    'community account deletion intentionally removes that user\'s preserved reaction rows');

$manifestCount = count(glob($root . '/skins/*/manifest.json') ?: []);
$check($manifestCount >= 1 && !str_contains($component, 'community_reactions_enabled'),
    "all {$manifestCount} skin manifests are reaction-inert with either global setting value");

$staleHelp = [];
foreach (glob($root . '/skins/*/help.json') ?: [] as $helpPath) {
    if (stripos((string)file_get_contents($helpPath), 'reaction') !== false) {
        $staleHelp[] = basename(dirname($helpPath));
    }
}
$check($staleHelp === [],
    'no skin help panel tells the owner to configure the retired feature');

echo (count($failures) ? 'FAIL' : 'PASS')
    . ': emoji reactions retired; hearts and historical evidence preserved (SS-1537)' . PHP_EOL;
exit(count($failures) ? 1 : 0);
// ===== SNAPSMACK EOF =====
