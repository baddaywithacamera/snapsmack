<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * An index must be in BOTH hand-maintained lists, or in neither.
 *
 * THE FAILURE THIS EXISTS TO CATCH IS SILENT AND ASYMMETRIC, which is why it
 * needs a test rather than care:
 *
 *   canonical CREATE TABLE only  ->  reaches FRESH INSTALLS and never one of
 *                                    the 25 live sites. Nothing errors. The
 *                                    index simply is not there, and the query
 *                                    it was added for stays slow forever on
 *                                    every site that matters.
 *   $index_additions only        ->  reaches the LIVE SITES and never a fresh
 *                                    install. Also silent. A new site is slow
 *                                    in a way no existing site is, so it looks
 *                                    like something else.
 *
 * Neither direction produces an error, a warning or a failing page. The only
 * thing that can notice is a comparison of the two lists, so here it is.
 *
 * IT CALLS snap_schema_index_additions() RATHER THAN READING ITS SOURCE. This
 * repository has had to make that same fix three times now -- rel_is_dev_path()
 * in the release builder, snap_backfill_album_slugs() in core/slug.php, and
 * this list -- because a test that greps for a string proves the string is
 * present, not that the decision behind it is right. The canonical SQL is
 * parsed because it IS data: snap_parse_canonical_schema() is the product's own
 * parser, not a second one written for the test.
 *
 * Run: php tests/schema-index-drift-regression.php    (no database needed)
 */

require_once dirname(__DIR__) . '/core/schema-sync.php';

$fail = 0;
function cx(bool $ok, string $what): void {
    global $fail;
    if ($ok) { echo "  ok    $what\n"; return; }
    $fail++; echo "  FAIL  $what\n";
}

/**
 * Index names declared in a CREATE TABLE's DDL.
 *
 * KEY, INDEX and UNIQUE KEY all count, because all three are things that could
 * be added to one list and forgotten in the other. PRIMARY KEY does not: it has
 * no name to match on and cannot be added by an ALTER in $index_additions.
 */
function indexes_in_ddl(string $ddl): array {
    preg_match_all('/(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`([^`]+)`/i', $ddl, $m);
    return array_values(array_unique($m[1]));
}

$canonical = snap_parse_canonical_schema();
$additions = snap_schema_index_additions();

cx($canonical !== [], 'the canonical schema parsed (' . count($canonical) . ' tables)');
cx($additions !== [], 'the additions list is not empty (' . count($additions) . ' indexes)');

// ── DIRECTION ONE: in the additions list, missing from the canonical table ──
// A live site gets it; a fresh install never does.

echo "\nEVERY ADDITION IS ALSO IN ITS CANONICAL CREATE TABLE\n";

foreach ($additions as [$table, $index, $ddl]) {
    if (!isset($canonical[$table])) {
        cx(false, "$index names table $table, which is NOT in the canonical schema at all");
        continue;
    }
    cx(in_array($index, indexes_in_ddl($canonical[$table]), true),
       "$table.$index is in the canonical CREATE TABLE (so a fresh install gets it)");

    // The DDL has to name the table it claims to alter, or the entry is wired
    // to the wrong table and the INFORMATION_SCHEMA idempotency check looks at
    // one table while the ALTER touches another.
    cx(str_contains($ddl, "`{$table}`") && str_contains($ddl, "`{$index}`"),
       "and its ALTER names both $table and $index");
}

// ── DIRECTION TWO: in a canonical table, missing from the additions list ──
// A fresh install gets it; not one of the 25 live sites ever will. This is the
// half a careless version of this test would leave out, because the additions
// list is the thing you are looking at when you add an index.

echo "\nEVERY CANONICAL INDEX IS ALSO IN THE ADDITIONS LIST\n";

$declared = [];
foreach ($additions as [$table, $index]) $declared["{$table}.{$index}"] = true;

// EXEMPTIONS, each with its reason stated. An exemption without a reason is a
// hole; these are the only two, and they are checked below to still be true.
$exempt = [
    // Appended conditionally by snap_schema_sync() after the album_slug
    // backfill pre-flight, so it cannot be in the unconditional list. It is
    // ALSO not in the canonical CREATE TABLE -- see the assertion below, which
    // says plainly that this is an open question rather than a settled design.
    'snap_albums.uq_album_slug' => 'conditional on the backfill pre-flight',
];

$oneSided = [];
foreach ($canonical as $table => $ddl) {
    foreach (indexes_in_ddl($ddl) as $index) {
        $key = "{$table}.{$index}";
        if (isset($declared[$key]) || isset($exempt[$key])) continue;
        $oneSided[] = $key;
    }
}

// THE MEASURED BASELINE, AND WHY IT IS A SET OF NAMES RATHER THAN A NUMBER.
// I first wrote this as "must be zero". It is 161, so zero was a guess rather
// than a measurement, and a guess inside a test is worse than no test.
//
// MOST OF THESE ARE NOT REAL GAPS, and saying otherwise would be alarming and
// wrong. Nearly every table listed is itself created by CREATE TABLE IF NOT
// EXISTS in the sync pass, and a table created that way arrives WITH its
// indexes. So a canonical-only index matters only where the index was added to
// a table that ALREADY existed on the live sites -- and these two files cannot
// tell those cases apart, because neither records when a table first shipped.
// 161 is therefore an UPPER BOUND on the divergence, not a count of it. Finding
// the real number means asking a live database what indexes it has. I cannot do
// that from here and have NOT done it.
//
// A COUNT WOULD BE THE WRONG PIN: 161 stays 161 if someone adds one index and
// retrofits another, and the new drift passes. So the SET is pinned. It may
// shrink freely as indexes are retrofitted. A NEW canonical-only index is not
// in this list and fails here, which is the drift the ruling asked to prevent.
$BASELINE = [
    'pc_admissions.idx_pc_admission_board', 'pc_admissions.uq_pc_admission_object',
    'pc_admissions.uq_pc_admission_slot', 'pc_admissions.uq_pc_boost_activity',
    'pc_blocklist.uq_pc_block', 'pc_engagement.idx_pc_engagement_object',
    'pc_engagement.uq_pc_engagement', 'pc_entry_failures.idx_pc_failure_state',
    'pc_entry_failures.uq_pc_failure_object', 'pc_hall_of_fame.uq_pc_hof_place',
    'pc_outbound_boosts.idx_pc_boost_state', 'pc_participants.idx_pc_participant_state',
    'pc_prompts.idx_pc_prompt_due', 'pc_prompts.uq_pc_prompt_week',
    'pc_rounds.idx_pc_round_finalize', 'pc_window_notices.idx_pc_window_notice_state',
    'pc_window_notices.uq_pc_window_notice', 'snap_ai_acceptance_audit.idx_accepted_at',
    'snap_ai_enrichment_cache.idx_ai_enrichment_lookup',
    'snap_ai_enrichment_cache.uq_ai_enrichment_cache_key', 'snap_ap_actors.uq_ap_actor_cache',
    'snap_ap_blocks.uq_ap_block', 'snap_ap_deliveries.idx_ap_due',
    'snap_ap_deliveries.uq_ap_delivery_dedupe', 'snap_ap_dms.idx_dm_thread',
    'snap_ap_dms.uq_dm_note', 'snap_ap_followers.idx_ap_active',
    'snap_ap_followers.uq_ap_actor', 'snap_ap_following.uq_ap_following',
    'snap_ap_inbox_log.idx_inbox_log_time', 'snap_ap_likes.idx_ap_like_target',
    'snap_ap_likes.uq_ap_like', 'snap_ap_notifications.idx_ap_notif_read',
    'snap_ap_notifications.uq_ap_notif', 'snap_ap_outbound_likes.uq_ap_out_like',
    'snap_ap_outbound_replies.uq_ap_reply_token', 'snap_ap_replays.idx_ap_replay_expiry',
    'snap_ap_search_accounts.idx_search_active', 'snap_ap_search_accounts.uq_search_host',
    'snap_ap_timeline.idx_ap_tl_src', 'snap_ap_timeline.uq_ap_tl',
    'snap_ap_timeline_membership.idx_ap_membership_feed', 'snap_backup_log.idx_created_at',
    'snap_ban_list.idx_type_val', 'snap_ban_list.uq_ban', 'snap_blogroll.idx_source_hub_url',
    'snap_bucket_items.idx_bucket_post_position', 'snap_bucket_items.uq_bucket_post_image',
    'snap_cats.uq_name', 'snap_collection_items.idx_collection',
    'snap_collection_items.idx_item', 'snap_collection_items.uq_collection_item',
    'snap_collections.uq_slug', 'snap_comments.idx_fp_hash', 'snap_comments.img_id',
    'snap_comments.post_id', 'snap_comments.uq_ap_object', 'snap_comments_semantic.idx_created',
    'snap_comments_semantic.idx_fingerprint', 'snap_community_comments.idx_fp_hash',
    'snap_community_comments.idx_post_status', 'snap_community_sessions.idx_expires_at',
    'snap_community_sessions.idx_user_id', 'snap_community_sessions.uq_token',
    'snap_community_tokens.idx_user_type', 'snap_community_tokens.uq_token',
    'snap_community_users.idx_status', 'snap_community_users.uq_email',
    'snap_community_users.uq_username',
    'snap_desktop_activation_keys.uq_desktop_activation_hash',
    'snap_desktop_devices.idx_desktop_device_last_used',
    'snap_desktop_devices.idx_desktop_device_status',
    'snap_desktop_devices.uq_desktop_device_fingerprint',
    'snap_desktop_nonces.idx_desktop_nonce_seen', 'snap_enrollment_audit.idx_enroll_created',
    'snap_enrollment_audit.idx_enroll_digest', 'snap_file_manifest.uq_path',
    'snap_game_on_scores.idx_go_fastest', 'snap_game_on_scores.idx_go_most',
    'snap_game_scores.idx_game_metric_period', 'snap_game_scores.idx_game_session',
    'snap_group_discussion.idx_gd_group_thread', 'snap_group_members.idx_gm_group_status',
    'snap_group_members.idx_gm_user', 'snap_group_members.uq_group_user',
    'snap_group_pool.idx_gp_group', 'snap_group_pool.uq_group_image',
    'snap_groups.idx_group_pub', 'snap_groups.uq_group_slug',
    'snap_gyss_delete_audit.idx_gyss_delete_image',
    'snap_gyss_delete_audit.idx_gyss_delete_when', 'snap_hub_shared_bans.idx_last_seen',
    'snap_hub_shared_bans.idx_removed', 'snap_hub_shared_bans.uq_type_val',
    'snap_image_album_map.idx_iam_album', 'snap_image_tags.idx_image_tags_image_id',
    'snap_image_tags.idx_image_tags_tag_id', 'snap_image_tags.uq_image_tag',
    'snap_import_map.idx_import_destination', 'snap_import_map.uq_import_source',
    'snap_ip_bans.idx_expires', 'snap_ip_bans.uq_ip', 'snap_keywords.idx_keyword',
    'snap_keywords.uq_keyword', 'snap_likes.idx_likes_guest', 'snap_likes.idx_post_id',
    'snap_likes.idx_user_id', 'snap_likes.uq_post_user', 'snap_multisite_nodes.uq_site_url',
    'snap_multisite_queue.idx_node_status', 'snap_multisite_sso_audit.ix_sso_audit_created',
    'snap_multisite_sso_tokens.ix_sso_token_expiry',
    'snap_multisite_sso_tokens.uq_sso_token_hash', 'snap_oauth_apps.uq_client_id',
    'snap_oauth_media.idx_oauth_media_token', 'snap_oauth_tokens.idx_app',
    'snap_oauth_tokens.idx_code', 'snap_oauth_tokens.idx_refresh',
    'snap_oauth_tokens.idx_token', 'snap_ohsnap_keys.uq_key_hash', 'snap_pages.slug',
    'snap_password_resets.idx_password_resets_email',
    'snap_password_resets.idx_password_resets_token', 'snap_post_album_map.idx_album_id',
    'snap_post_cat_map.idx_cat_id', 'snap_post_images.idx_post_id', 'snap_post_images.idx_sort',
    'snap_post_images.uq_image', 'snap_posts.idx_created_at', 'snap_posts.idx_post_type',
    'snap_posts.idx_status', 'snap_posts.idx_trigram', 'snap_posts.uq_slug',
    'snap_rate_limits.idx_window', 'snap_rate_limits.uq_ip_action',
    'snap_reactions.idx_post_id', 'snap_reactions.idx_reactions_guest',
    'snap_reactions.idx_user_id', 'snap_reactions.uq_post_user',
    'snap_relay_ingest_jobs.idx_relay_ingest_due', 'snap_relay_ingest_jobs.uq_relay_ingest',
    'snap_relay_intake.uq_relay_activity', 'snap_relay_intake.uq_relay_object',
    'snap_relay_subscribers.idx_relay_sub_domain', 'snap_relay_subscribers.idx_relay_sub_state',
    'snap_relay_subscribers.uq_relay_sub_actor', 'snap_relay_subscribers.uq_relay_sub_alias',
    'snap_skin_presets.idx_skin', 'snap_stats.idx_hit_at', 'snap_stats.idx_image_id',
    'snap_stats.idx_is_bot', 'snap_stats.idx_stats_enriched', 'snap_stats_daily.uq_stat_date',
    'snap_ste_scores.idx_colour', 'snap_tags.uq_tag_slug', 'snap_totp_devices.idx_user_expires',
    'snap_totp_devices.uq_token_hash', 'snap_trigrams.idx_post_1', 'snap_trigrams.idx_post_2',
    'snap_trigrams.idx_post_3', 'snap_users.username',
];

sort($oneSided);
$unexpected = array_values(array_diff($oneSided, $BASELINE));
cx($unexpected === [],
   'no NEW canonical-only index has appeared'
   . ($unexpected === [] ? ' (' . count($oneSided) . ' known)'
     : ': ' . implode(', ', $unexpected)));

// The other half of pinning a set: a name that has LEFT the baseline has been
// retrofitted, which is good news, and the list should be trimmed so it cannot
// go stale enough to hide that index reappearing later.
$retrofitted = array_values(array_diff($BASELINE, $oneSided));
if ($retrofitted !== []) {
    echo '        NOTE: ' . count($retrofitted) . ' baseline entr(ies) now retrofitted; '
       . 'trim them from $BASELINE: ' . implode(', ', array_slice($retrofitted, 0, 8)) . "\n";
}

echo "\nTHE INDEX THIS COMMIT IS ABOUT\n";

cx(isset($declared['snap_image_cat_map.idx_icm_cat']),
   'idx_icm_cat is in the additions list, so the 25 live sites get it');
cx(isset($canonical['snap_image_cat_map'])
   && in_array('idx_icm_cat', indexes_in_ddl($canonical['snap_image_cat_map']), true),
   'and in the canonical CREATE TABLE, so a fresh install gets it');
cx(isset($canonical['snap_image_album_map'])
   && in_array('idx_iam_album', indexes_in_ddl($canonical['snap_image_album_map']), true),
   'and the album pivot still has the matching index it was always missing from the other side');

echo "\nTHE EXEMPTIONS ARE STILL TRUE\n";

// An exemption that has quietly become wrong is worse than no exemption, so
// each one is asserted rather than trusted.
cx(!isset($canonical['snap_albums'])
   || !in_array('uq_album_slug', indexes_in_ddl($canonical['snap_albums']), true),
   'uq_album_slug is still absent from the canonical CREATE TABLE');
// SAID OUT LOUD BECAUSE IT IS A REAL GAP, not a passing detail: a fresh install
// gets the UNIQUE key only because its backfill finds no rows and the pre-flight
// comes back clean. That works, but it means the key arrives through the retrofit
// path rather than being declared. Putting it in the canonical table would be
// safe -- a fresh install has no rows to collide -- but adding a UNIQUE key to
// the canonical schema is outside the scope this commit was given, so it is
// raised, not done.
echo "        NOTE: a fresh install gets uq_album_slug only via the sync pass.\n";
echo "        Declaring it in the canonical table is safe and NOT done here.\n";

echo $fail === 0
    ? "\nPASS - the two hand-maintained lists agree\n"
    : "\n$fail CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
