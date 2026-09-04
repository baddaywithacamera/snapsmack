<?php
/**
 * SMACKCAST 0.7.545D relay architecture regression.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
$relay = file_get_contents($root . '/core/smackcast-relay.php');
$sv = file_get_contents($root . '/core/fediverse.php');
$schema = file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
$installer = file_get_contents($root . '/install.php');
$cron = file_get_contents($root . '/cron-fediverse.php');
$admin = file_get_contents($root . '/core/fediverse-admin-shared.php');
$fail = 0;
function sc_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

sc_test(str_contains($installer, "\$_SESSION['site_mode'] = 'fedistructure'"), 'FEDISTRUCTURE is durable install mode 4.0');
sc_test(str_contains($relay, "\$settings['site_mode'] ?? '') === 'fedistructure'"), 'hub policy is install-mode gated');
sc_test(str_contains($relay, "\$settings['node_role'] ?? '') === 'hub'"), 'hub policy is role gated');
sc_test(str_contains($relay, "\$settings['smackcast_relay_enabled'] ?? '0') === '1'"), 'relay defaults fail closed');
sc_test(str_contains($schema, 'snap_ap_timeline_membership'), 'normalized feed membership schema exists');
sc_test(str_contains($schema, 'PRIMARY KEY (`timeline_id`, `feed`)'), 'one object can have HOME and LOCAL exactly once');
sc_test(str_contains($sv, "sc_relay_add_membership(\$pdo, \$object_id, \$feed"), 'timeline ingestion records membership independent of source enum');
sc_test(str_contains($relay, "TABLE_NAME='snap_ap_timeline_membership'")
    && str_contains($relay, 'if (!$has_membership_table) return;'),
    'ordinary sites never write the hub-only timeline membership table');
sc_test(str_contains($sv, 'SMACKCAST optional membership skipped:'),
    'optional relay membership failure cannot reject an accepted inbox post');
sc_test(str_contains($sv, "\$dedupe_key = 'delivery:' . hash('sha256'"),
    'ordinary delivery queue rows are destination/activity idempotent');
sc_test(str_contains($sv, "sc_relay_receive_announce(\$pdo, \$settings, \$actor_id, \$obj_id)"), 'relay Announce has a distinct receiver path');
sc_test(str_contains($relay, 'snap_relay_ingest_jobs'), 'origin fetch failure is durable receiver work');
sc_test(str_contains($relay, "\$shelve ? 'shelved' : 'queued'"), 'bounded retry has an observable shelved terminal state');
sc_test(str_contains($schema, 'snap_relay_intake'), 'hub intake has durable object/activity deduplication');
sc_test(str_contains($schema, 'uq_ap_delivery_dedupe'), 'fan-out delivery is durable and destination-idempotent');
sc_test(str_contains($relay, "WHERE state='active'"), 'fan-out targets active subscribers only');
sc_test(str_contains($relay, "=== \$origin_actor) continue"), 'fan-out excludes the origin actor');
sc_test(str_contains($relay, 'sc_relay_is_discoverable'), 'unlisted cc:Public posts are excluded from discovery relay');
sc_test(str_contains($relay, 'sc_relay_refresh'), 'verified origin Updates refresh relayed objects');
sc_test(str_contains($relay, 'sc_relay_retract'), 'verified origin Deletes retract prior Announces');
sc_test(str_contains($relay, 'sc_relay_actor_blocked'), 'receiver-local actor/domain blocks gate relay ingestion');
sc_test(str_contains($relay, "(string)(\$object['id'] ?? '') !== \$object_id"), 'dereferenced object id must equal announced id');
sc_test(str_contains($relay, 'sc_relay_actor_owns_object'), 'origin actor and relayed object ownership are bound');
sc_test(str_contains($sv, "in_array('date', \$signed_names, true)"), 'inbox Date must be cryptographically signed');
sc_test(str_contains($sv, 'sv_inbox_replay_first_seen'), 'verified inbox requests have durable replay suppression');
sc_test(str_contains($sv, "if (\$relay_inbox !== ''"), 'publisher queues a separate best-effort relay notify');
sc_test(str_contains($cron, 'sc_relay_process_ingest_jobs'), 'cron recovers receiver-side origin fetch failures');
sc_test(str_contains($relay, 'if (!sc_relay_is_hub($settings)) return [0, 0]'),
    'ordinary blogs can enter relay-only ingest maintenance');
sc_test(str_contains($cron, 'Optional relay ingest maintenance failed; ordinary delivery will continue')
    && str_contains($cron, 'Optional relay outbox recovery failed; ordinary delivery will continue'),
    'optional relay maintenance can still kill ordinary follower delivery');
sc_test(str_contains($cron, 'sc_relay_recover_member_outboxes'), 'cron separately recovers hub-missed publication notifications');
sc_test(str_contains($relay, 'time() - 604800'), 'hub outbox recovery is bounded to seven days');
sc_test(str_contains($admin, "['smackcast_toggle','smackcast_member']"), 'CMS-native hub controls exist');
sc_test(str_contains($admin, 'reauth_verify'), 'hub consequential controls require password/TOTP step-up');
sc_test(str_contains($admin, "if (\$state === 'active')"), 'manual approval atomically queues its Accept');
sc_test(!str_contains($relay, 'notes_index') && !str_contains($relay, 'search_observation'), 'search is absent from relay slice');

// ── Mode-4.0 re-stamp migration: installs done before 545D recorded the old
// site_mode='photoblog' disguise, so the relay/hub gates stay dormant on them.
// The migration flips them to 'fedistructure' — but ONLY genuine network installs
// (guarded by the distribution marker), never a normal blog.
$mig = @file_get_contents($root . '/migrations/migrate-fedistructure-site-mode.sql') ?: '';
$upd = file_get_contents($root . '/core/updater.php');
sc_test($mig !== '', 'mode-4.0 re-stamp migration file exists');
sc_test(str_contains($mig, "SET s.setting_val = 'fedistructure'") && str_contains($mig, "s.setting_key = 'site_mode'"), 're-stamp sets site_mode to fedistructure');
sc_test(str_contains($mig, "s.setting_val = 'photoblog'"), 're-stamp only touches the old photoblog disguise (idempotent)');
sc_test(str_contains($mig, "d.setting_key = 'distribution'") && str_contains($mig, "d.setting_val = 'fedistructure'"), 're-stamp is guarded by the distribution marker (never touches a normal blog)');
sc_test(str_contains($upd, "'migrate-fedistructure-site-mode.sql'"), 're-stamp migration is registered so the updater runs it fleet-wide');

exit($fail === 0 ? 0 : 1);
// ===== SNAPSMACK EOF =====
