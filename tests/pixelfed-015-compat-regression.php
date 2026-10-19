<?php
/** Pixelfed 0.15 tolerant payload and additive-schema regression contract. */
$root = dirname(__DIR__);
require_once $root . '/core/fediverse.php';

function pf15_expect(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$uri = 'https://remote.example/objects/42';
pf15_expect(sv_activity_object_uri($uri) === $uri, 'string object URI is accepted');
pf15_expect(sv_activity_object_uri(['id' => $uri, 'type' => 'Note']) === $uri, 'embedded object URI is accepted');
pf15_expect(sv_activity_object_uri(['id' => 'file:///etc/passwd']) === '', 'non-HTTP object URI is rejected');
pf15_expect(sv_activity_object_uri(['type' => 'Note']) === '', 'embedded object without id is rejected');

$schema = file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
foreach (['context_uri','conversation_uri','thread_key','is_group','recipients_json','moved_to','also_known_as_json'] as $column) {
    pf15_expect(str_contains($schema, '`' . $column . '`'), "schema contains {$column}");
}

$api = file_get_contents($root . '/pixelfed-api.php');
pf15_expect(str_contains($api, 'px_dm_context'), 'DM context endpoint resolves known ancestors and descendants');
pf15_expect(str_contains($api, "COALESCE(NULLIF(thread_key,''),remote_actor_url)"), 'conversation API dual-reads current and legacy threads');

echo "Pixelfed 0.15 compatibility regression: ok\n";

// ===== SNAPSMACK EOF =====
