<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once __DIR__ . '/../core/photochallenge.php';

$failures = [];
function pc_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

$settings = [
    'photochallenge_enabled' => '1',
    'photochallenge_tag' => '#PhotoFri!',
    'photochallenge_tz' => 'UTC',
];
pc_test(pc_enabled($settings), 'enabled profile was reported disabled');
pc_test(pc_tag($settings) === 'photofri', 'challenge tag normalization failed');

$open = pc_window($settings, strtotime('2026-09-04 12:00:00 UTC'));
$closed = pc_window($settings, strtotime('2026-09-06 02:00:00 UTC'));
pc_test($open['open'] === true, 'Friday challenge window was not open');
pc_test($open['start'] === '2026-09-03 10:00:00', 'global window start was incorrect');
pc_test($open['end'] === '2026-09-05 12:00:00', 'global window end was incorrect');
$closed = pc_window($settings, strtotime('2026-09-05 12:00:00 UTC'));
pc_test($closed['open'] === false, 'window remained open at its exclusive end');

// --- SCHEDULE A PROMPT: hashtag generation ---
$h = pc_hashtag_from_prompt('Belonging');
pc_test($h['display'] === 'PhotoFriBelonging', 'prompt hashtag display form is wrong');
pc_test($h['tag'] === 'photofribelonging', 'prompt hashtag qualifying tag is wrong');
pc_test(pc_hashtag_from_prompt('golden hour')['display'] === 'PhotoFriGoldenHour',
    'multi-word prompt is not CamelCased');
pc_test(pc_hashtag_from_prompt('self-portrait!')['display'] === 'PhotoFriSelfPortrait',
    'prompt punctuation is not stripped');
pc_test(pc_hashtag_from_prompt('Belonging', 'ArtFri')['display'] === 'ArtFriBelonging',
    'prompt hashtag ignores the brand prefix');

// --- SCHEDULE A PROMPT: a specific Friday maps to the same 50-hour window pc_window() derives ---
$fw = pc_window_for_friday('2026-09-04');            // a Friday
$pw = pc_window($settings, strtotime('2026-09-04 12:00:00 UTC'));
pc_test($fw !== null, 'pc_window_for_friday rejected a valid Friday');
pc_test($fw['start'] === '2026-09-03 10:00:00', 'Friday window start is not Thursday 10:00 UTC');
pc_test($fw['end'] === '2026-09-05 12:00:00', 'Friday window end is not Saturday 12:00 UTC');
pc_test($fw['start'] === $pw['start'] && $fw['end'] === $pw['end'] && $fw['week_key'] === $pw['week_key'],
    'pc_window_for_friday disagrees with the live pc_window() weekly math');
pc_test(pc_window_for_friday('2026-09-02')['friday'] === '2026-09-04',
    'a mid-week date does not snap to that week\'s Friday');
pc_test(pc_window_for_friday('not-a-date') === null, 'an unparseable date was not rejected');

$photo = file_get_contents(__DIR__ . '/../core/photochallenge.php');
$sv = file_get_contents(__DIR__ . '/../core/smackverse.php');
$schema = file_get_contents(__DIR__ . '/../database/schema/snapsmack_canonical.sql');
$htaccess = file_get_contents(__DIR__ . '/../core/htaccess-template');
$admin = file_get_contents(__DIR__ . '/../smack-photochallenge.php');
$sidebar = file_get_contents(__DIR__ . '/../core/sidebar-photochallenge.php');
$admin_header = file_get_contents(__DIR__ . '/../core/admin-header.php');
$installer = file_get_contents(__DIR__ . '/../install.php');
$fedup = file_get_contents(__DIR__ . '/../fedup.php');
$packager = file_get_contents(__DIR__ . '/../smack-central/sc-release.php');

foreach (['pc_participants', 'pc_hall_of_fame', 'pc_engagement', 'pc_outbound_boosts', 'pc_window_notices'] as $table) {
    pc_test(str_contains($schema, "CREATE TABLE IF NOT EXISTS `{$table}`"), "{$table} is absent from canonical schema");
}
foreach (['pc_on_follow', 'pc_on_leave', 'pc_record_like', 'pc_record_boost', 'pc_remove_engagement'] as $hook) {
    pc_test(str_contains($sv, $hook), "SMACKVERSE is missing {$hook} integration");
}
pc_test(str_contains($photo, 'SELECT id, week_key'), 'Hall of Fame rows omit the admin toggle id');
pc_test(str_contains($photo, 'tags_json'), 'board does not require structured ActivityPub hashtags');
pc_test(str_contains($photo, '> 5'), 'per-author five-entry cap is missing');
pc_test(str_contains($photo, '$slot > 5')
    && str_contains($photo, 'MAX(admission_number)')
    && str_contains($photo, 'uq_pc_admission_slot'),
    'entry cap must durably retain admission slots 1-5 and stop later boosts');
pc_test(str_contains($photo, "JOIN pc_participants p ON p.actor_url=t.actor_url")
    && str_contains($photo, "p.state='active'"),
    'board admission is not restricted to active participants');
pc_test(str_contains($photo, "(int)\$row['is_boost'] !== 0"), 'admission permits boosted posts as entries');
pc_test(str_contains($photo, 'pc_admissions') && str_contains($photo, "a.status='active'"),
    'board is not driven by the durable admission ledger');
pc_test(str_contains($photo, 'pc_cron_maintain') && str_contains($photo, 'finalized_at IS NULL'),
    'ended rounds are not finalized automatically');
pc_test(str_contains($photo, 'check_failures') && str_contains($photo, '$failures >= 3'),
    'link gardening does not distinguish a transient origin failure from deletion');
pc_test(str_contains($photo, "(int)(\$row['sensitive'] ?? 0) !== 0"),
    'sensitive/CW entries are not rejected');
pc_test(str_contains($photo, 'pc_withdraw_actor_admissions') && str_contains($photo, 'sv_unboost_remote'),
    'leave/block does not withdraw entries and undo challenge boosts');
pc_test(str_contains($photo, 'boost_activity_id') && str_contains($photo, 'pc_entry_object_id'),
    'engagement on the challenge Announce is not normalized to the admitted object');
pc_test(str_contains($photo, 'pc_blocklist') && str_contains($photo, 'pc_is_blocked'),
    'actor/domain moderation blocklist is not enforced at admission');
pc_test(str_contains($photo, 'count($media) !== 1'), 'board does not enforce exactly one image');
pc_test(str_contains($photo, 'sv_boost_remote(')
    && str_contains($sv, 'pc_maybe_boost_entry'),
    'qualified original entries are not automatically boosted');
pc_test(str_contains($photo, 'pc_notice_closed_window')
    && str_contains($photo, 'sv_send_dm(')
    && str_contains($photo, 'uq_pc_window_notice')
    && str_contains($photo, "post wasn't entered or boosted"),
    'closed-window entries do not receive one deduplicated private retry notice');
pc_test(str_contains($admin, 'Thursday 10:00 UTC through Saturday 12:00 UTC'),
    'admin describes a non-canonical challenge window');
foreach (['THE GOOD SHIT', 'FEDIVERSE', 'CHALLENGE ME', 'BORING ASS STUFF'] as $heading) {
    pc_test(str_contains($sidebar, $heading), "photo challenge sidebar is missing {$heading}");
}
foreach (['Categories', 'Albums', 'Collections', 'Blogroll', 'User Manual',
          'Community Forum', 'Big Wheel', 'Pimpmobile'] as $excluded) {
    pc_test(!str_contains($sidebar, $excluded), "photo challenge sidebar exposes {$excluded}");
}
pc_test(str_contains($sidebar, 'Static Pages'), 'photo challenge sidebar is missing static pages');
// The Midnight Lime lock is deliberately gone: a challenge node keeps per-user
// theme selection like every other install. Assert the preference is read
// unconditionally, so the lock cannot be reintroduced unnoticed.
pc_test(str_contains($admin_header, "\$active_theme = \$_SESSION['user_preferred_skin']"),
    'photo challenge admin forces a fixed theme instead of the user preference');
foreach (['smack-stats.php', 'smack-multisite.php'] as $required) {
    pc_test(str_contains($sidebar, $required),
        "photo challenge sidebar is missing {$required}");
}
pc_test(str_contains($installer, "'photo-challenge', 'daily-photo', 'smackcast'"),
    'FEDISTRUCTURE installer profiles are missing');
pc_test(str_contains($fedup, 'latest-fedistructure.json')
    && str_contains($fedup, "FEDUP_RELEASE_PUBKEY"),
    'fedup.php is not bound to the signed FEDISTRUCTURE manifest');
pc_test(str_contains($packager, 'snapsmack-fedistructure-')
    && str_contains($packager, 'latest-fedistructure.json'),
    'Release Packager does not publish the FEDISTRUCTURE sibling artifact');
pc_test(str_contains($htaccess, '^board/?$'), 'pretty board route is missing');
pc_test(str_contains($htaccess, '^hall-of-fame/?$'), 'pretty Hall of Fame route is missing');

// --- SCHEDULE A PROMPT: structure ---
pc_test(str_contains($schema, 'CREATE TABLE IF NOT EXISTS `pc_prompts`'),
    'pc_prompts is absent from the canonical schema');
pc_test(str_contains($photo, 'CREATE TABLE IF NOT EXISTS pc_prompts'),
    'pc_ensure_tables does not create pc_prompts on upgraded installs');
pc_test(str_contains($photo, 'function pc_queue_prompt')
    && str_contains($photo, 'function pc_activate_due_prompts')
    && str_contains($photo, 'function pc_cancel_prompt'),
    'prompt scheduler engine functions are missing');
pc_test(str_contains($photo, 'pc_activate_due_prompts($pdo, $settings);   // drop any scheduled prompt'),
    'the cron (pc_cron_maintain) must activate due prompts');
pc_test(str_contains($photo, "sv_set_setting(\$pdo, \$settings, 'photochallenge_tag', (string)\$p['tag'])"),
    'dropping a prompt must switch the live qualifying hashtag');
pc_test(str_contains($photo, "'status'      => 'draft'") && str_contains($photo, 'snap_ingest_image('),
    'the queued card must be ingested as a hidden draft, not published immediately');
pc_test(str_contains($photo, 'INSERT INTO snap_posts') && str_contains($photo, 'INSERT INTO snap_post_images'),
    'the queued card must be post-backed so it federates on drop');
pc_test(str_contains($admin, "value=\"queue_prompt\"") && str_contains($admin, 'SCHEDULE A PROMPT'),
    'the admin is missing the SCHEDULE A PROMPT panel');
pc_test(str_contains($admin, 'enctype="multipart/form-data"'),
    'the prompt form cannot upload a card image');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: Photo Challenge build regression suite\n";
// ===== SNAPSMACK EOF =====
