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
pc_test(pc_feed_enabled($settings), 'existing challenge feed disappeared before its new switch was saved');
$feed_off_settings = $settings;
$feed_off_settings['photochallenge_feed_enabled'] = '0';
pc_test(!pc_feed_enabled($feed_off_settings), 'explicitly disabled challenge feed remained available');
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
$sv = file_get_contents(__DIR__ . '/../core/fediverse.php');
$schema = file_get_contents(__DIR__ . '/../database/schema/snapsmack_canonical.sql');
$htaccess = file_get_contents(__DIR__ . '/../core/htaccess-template');
$admin = file_get_contents(__DIR__ . '/../smack-photochallenge.php');
$sidebar = file_get_contents(__DIR__ . '/../core/sidebar-photochallenge.php');
$admin_header = file_get_contents(__DIR__ . '/../core/admin-header.php');
$admin_geometry = file_get_contents(__DIR__ . '/../assets/css/admin-theme-geometry-master.css');
$prompt_js = file_get_contents(__DIR__ . '/../assets/js/smack-prompt-schedule.js');
$menu = file_get_contents(__DIR__ . '/../smack-menu.php');
$header = file_get_contents(__DIR__ . '/../core/header.php');
$board = file_get_contents(__DIR__ . '/../photochallenge-board.php');
$board_layout_css = file_get_contents(__DIR__ . '/../assets/css/photochallenge-board-layouts.css');
$installer = file_get_contents(__DIR__ . '/../install.php');
$fedup = file_get_contents(__DIR__ . '/../fedup.php');
$packager = file_get_contents(__DIR__ . '/../smack-central/sc-release.php');

foreach (['pc_participants', 'pc_hall_of_fame', 'pc_engagement', 'pc_outbound_boosts', 'pc_window_notices'] as $table) {
    pc_test(str_contains($schema, "CREATE TABLE IF NOT EXISTS `{$table}`"), "{$table} is absent from canonical schema");
}
foreach (['pc_on_follow', 'pc_on_leave', 'pc_record_like', 'pc_record_boost', 'pc_remove_engagement'] as $hook) {
    pc_test(str_contains($sv, $hook), "FEDIVERSE is missing {$hook} integration");
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
// 0.7.602D: the drop MUST re-stamp created_at to NOW. The AUTO fediverse sweep
// federates grouped posts by created_at > last-federated marker; a card carrying
// its old queue-time date slips past the marker and never reaches followers
// ("staged, never pushed"). Re-stamping on publish makes the next sweep send it.
pc_test(str_contains($photo, "SET status='published', created_at=NOW(), updated_at=NOW() WHERE id=?"),
    'dropping a prompt must re-stamp snap_posts.created_at to NOW so the fediverse sweep federates the card');
pc_test(str_contains($admin, "'queue_prompt'") && str_contains($admin, 'SCHEDULE A PROMPT'),
    'the admin is missing the SCHEDULE A PROMPT panel');
pc_test(str_contains($admin, 'name="pc_caption"')
    && str_contains($admin, 'name="pc_alt"')
    && str_contains($admin, "'caption' => (string)(\$_POST['pc_caption'] ?? '')"),
    'the prompt form must include ordinary post caption and ALT fields');
pc_test(str_contains($photo, "\$caption = trim((string)(\$data['caption'] ?? ''))")
    && str_contains($photo, "if (\$caption !== '') \$parts[] = \$caption;"),
    'the additional caption must be included in the published card body');
// The card body must NOT lead with the bare prompt word or stuff the hashtag
// inline (the card image shows both; the fediverse layer appends the tag once).
pc_test(!str_contains($photo, "return \$prompt\n"), 'card body must not lead with the bare prompt word');
pc_test(str_contains($photo, "'tags'        => \$hash['display']"),
    'the card must be tagged via ingest opts so discovery works without an inline hashtag');
pc_test(str_contains($sidebar, '>Contest &amp; Feed</a>')
    && str_contains($sidebar, 'smack-photochallenge-queue.php">Queue Contest Post</a>')
    && str_contains($sidebar, 'smack-photochallenge-queued.php">Queued Posts</a>')
    && str_contains($admin, 'id="queue-contest-post"'),
    'CHALLENGE ME must expose three distinct task pages');
pc_test(is_file(__DIR__ . '/../smack-photochallenge-queue.php')
    && is_file(__DIR__ . '/../smack-photochallenge-queued.php'),
    'queue composer and queued-post management must be separate pages');
pc_test(str_contains($admin, 'id="queued-contest-posts"')
    && str_contains($admin, 'No contest posts are queued yet.'),
    'queued-post area must remain visible before the first prompt is queued');
pc_test(str_contains($admin, 'smack-photochallenge-queue.php?edit=')
    && str_contains($admin, "'update_prompt'")
    && str_contains($photo, 'function pc_update_prompt')
    && str_contains($photo, "WHERE id=? AND status='queued'")
    && str_contains($schema, '`caption` text')
    && str_contains($schema, '`alt` varchar(500)'),
    'queued posts must be editable while keeping challenge and post records synchronized');
pc_test(str_contains($admin, 'enctype="multipart/form-data"'),
    'the prompt form cannot upload a card image');
pc_test(str_contains($admin, 'id="pc_drop_at" value="<?php echo $esc($def_drop_value); ?>"')
    && str_contains($prompt_js, 'boost = new Date(promptAt + 7 * 24 * 3600 * 1000)')
    && str_contains($prompt_js, 'windowEl.value = fmtLocal(boost)'),
    'PROMPT POST is primary: setting its date fills the boosting window exactly one week later');
pc_test(str_contains($admin, '1. PROMPT POST')
    && str_contains($admin, '2. BOOSTING WINDOW STARTS AT')
    && str_contains($admin, 'id="pc_window_start"')
    && str_contains($admin, 'name="pc_friday" id="pc_friday"')
    && str_contains($admin, 'CHOOSE WHEN THE CARD PUBLISHES')
    && str_contains($admin, 'exactly one week later')
    && str_contains($photo, 'The target challenge date must be a Friday.')
    && str_contains($prompt_js, 'toFri')
    && str_contains($admin, 'contest Friday is stored automatically'),
    'PROMPT is first, BOOSTING WINDOW second; the contest Friday is derived and Friday-only');
pc_test(str_contains($admin, 'class="pc-file-picker__button"')
    && str_contains($admin, 'class="pc-file-picker__name"')
    && str_contains($admin, 'class="file-input-hidden"')
    && str_contains($admin_geometry, '.pc-file-picker__button')
    && str_contains($prompt_js, "imageNameEl.textContent"),
    'card chooser must use an external-CSS button beside a live filename field');
pc_test(str_contains($admin, 'name="pc_feed_enabled"')
    && str_contains($photo, 'pc_sync_feed_menu')
    && str_contains($menu, "'type' => 'challenge_feed'")
    && str_contains($header, "case 'challenge_feed'")
    && str_contains($board, '!pc_feed_enabled($settings)'),
    'feed-page switch must control /board and its built-in Menu Manager item');
pc_test(str_contains($admin, 'name="pc_feed_layout"')
    && str_contains($board, 'grid--<?php echo $esc($feed_layout); ?>')
    && str_contains($board, 'photochallenge-board-layouts.css')
    && str_contains($board_layout_css, '.grid--masonry'),
    'challenge feed must offer three-across and external-CSS masonry layouts');

// Admission owns the window gate. The outbound boost function must obtain an
// active admission before its only sv_boost_remote() call can run.
$admit_pos = strpos($photo, 'function pc_admit_object');
$closed_pos = strpos($photo, "if (!\$win['open'])", $admit_pos);
$notice_pos = strpos($photo, 'pc_notice_closed_window($pdo,$settings,$row,$win);', $closed_pos);
$closed_return_pos = strpos($photo, 'return null;', $notice_pos);
$boost_fn_pos = strpos($photo, 'function pc_maybe_boost_entry');
$admit_call_pos = strpos($photo, 'pc_admit_object($pdo, $settings, $object_id)', $boost_fn_pos);
$boost_call_pos = strpos($photo, 'sv_boost_remote($pdo, $settings, $object_id)', $admit_call_pos);
pc_test($admit_pos !== false && $closed_pos !== false && $notice_pos !== false
    && $closed_return_pos !== false && $closed_return_pos < $boost_fn_pos
    && $admit_call_pos !== false && $boost_call_pos !== false && $admit_call_pos < $boost_call_pos,
    'an outside-window hashtag post can reach boosting before decline and courtesy notification');
pc_test(str_contains($photo, 'INSERT IGNORE INTO pc_window_notices(actor_url,week_key,object_id)')
    && str_contains($photo, 'if ($reserve->rowCount() !== 1) return;')
    && str_contains($photo, 'sv_send_dm($pdo,$settings,$actor,$body)')
    && str_contains($photo, "post wasn't entered or boosted"),
    'closed-window courtesy DM is not private, deduplicated, and explicit about no boost');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: Photo Challenge build regression suite\n";
// ===== SNAPSMACK EOF =====
