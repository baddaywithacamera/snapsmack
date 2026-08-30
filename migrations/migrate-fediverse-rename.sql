-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
-- De-smackverse: rename every smackverse_* identifier to its new name.
-- Rule (matches the code sweep exactly): smackverse_relay* -> photoblogs_relay* ; all other smackverse_* -> fediverse_*
-- snap_settings key renames are idempotent (0 rows if already migrated). Identity keys (private_key, handle,
-- followers) live here as ROWS, safe from schema drift-drop. Run once per node (hub + every spoke).

-- ── snap_settings rows ─────────────────────────────────────────────
UPDATE snap_settings SET setting_key='fediverse_actor_fp' WHERE setting_key='smackverse_actor_fp';
UPDATE snap_settings SET setting_key='fediverse_avatar' WHERE setting_key='smackverse_avatar';
UPDATE snap_settings SET setting_key='fediverse_backfill_count' WHERE setting_key='smackverse_backfill_count';
UPDATE snap_settings SET setting_key='fediverse_bio' WHERE setting_key='smackverse_bio';
UPDATE snap_settings SET setting_key='fediverse_boosts' WHERE setting_key='smackverse_boosts';
UPDATE snap_settings SET setting_key='fediverse_cron_last_run' WHERE setting_key='smackverse_cron_last_run';
UPDATE snap_settings SET setting_key='fediverse_cron_last_status' WHERE setting_key='smackverse_cron_last_status';
UPDATE snap_settings SET setting_key='fediverse_delete_log_purge' WHERE setting_key='smackverse_delete_log_purge';
UPDATE snap_settings SET setting_key='fediverse_delivery_cadence_secs' WHERE setting_key='smackverse_delivery_cadence_secs';
UPDATE snap_settings SET setting_key='fediverse_display_name' WHERE setting_key='smackverse_display_name';
UPDATE snap_settings SET setting_key='fediverse_enabled' WHERE setting_key='smackverse_enabled';
UPDATE snap_settings SET setting_key='fediverse_fedi_gen' WHERE setting_key='smackverse_fedi_gen';
UPDATE snap_settings SET setting_key='fediverse_followers' WHERE setting_key='smackverse_followers';
UPDATE snap_settings SET setting_key='fediverse_following' WHERE setting_key='smackverse_following';
UPDATE snap_settings SET setting_key='fediverse_handle' WHERE setting_key='smackverse_handle';
UPDATE snap_settings SET setting_key='fediverse_home_instance' WHERE setting_key='smackverse_home_instance';
UPDATE snap_settings SET setting_key='fediverse_inbox' WHERE setting_key='smackverse_inbox';
UPDATE snap_settings SET setting_key='fediverse_last_img_federated_at' WHERE setting_key='smackverse_last_img_federated_at';
UPDATE snap_settings SET setting_key='fediverse_last_img_federated_id' WHERE setting_key='smackverse_last_img_federated_id';
UPDATE snap_settings SET setting_key='fediverse_last_post_federated_at' WHERE setting_key='smackverse_last_post_federated_at';
UPDATE snap_settings SET setting_key='fediverse_last_post_federated_id' WHERE setting_key='smackverse_last_post_federated_id';
UPDATE snap_settings SET setting_key='fediverse_layer_cadence_secs' WHERE setting_key='smackverse_layer_cadence_secs';
UPDATE snap_settings SET setting_key='fediverse_likes' WHERE setting_key='smackverse_likes';
UPDATE snap_settings SET setting_key='fediverse_participation_ack' WHERE setting_key='smackverse_participation_ack';
UPDATE snap_settings SET setting_key='fediverse_permalink_like_backfill' WHERE setting_key='smackverse_permalink_like_backfill';
UPDATE snap_settings SET setting_key='fediverse_private_key' WHERE setting_key='smackverse_private_key';
UPDATE snap_settings SET setting_key='fediverse_pronouns' WHERE setting_key='smackverse_pronouns';
UPDATE snap_settings SET setting_key='fediverse_public_key' WHERE setting_key='smackverse_public_key';
UPDATE snap_settings SET setting_key='fediverse_push_mode' WHERE setting_key='smackverse_push_mode';
UPDATE snap_settings SET setting_key='photoblogs_relay' WHERE setting_key='smackverse_relay';
UPDATE snap_settings SET setting_key='photoblogs_relay_joined' WHERE setting_key='smackverse_relay_joined';
UPDATE snap_settings SET setting_key='photoblogs_relay_url' WHERE setting_key='smackverse_relay_url';
UPDATE snap_settings SET setting_key='fediverse_replies' WHERE setting_key='smackverse_replies';
UPDATE snap_settings SET setting_key='fediverse_rollcall' WHERE setting_key='smackverse_rollcall';
UPDATE snap_settings SET setting_key='fediverse_rollcall_topics' WHERE setting_key='smackverse_rollcall_topics';
UPDATE snap_settings SET setting_key='fediverse_search_key' WHERE setting_key='smackverse_search_key';
UPDATE snap_settings SET setting_key='fediverse_single_actor' WHERE setting_key='smackverse_single_actor';
UPDATE snap_settings SET setting_key='fediverse_username' WHERE setting_key='smackverse_username';
UPDATE snap_settings SET setting_key='fediverse_webcron_enabled' WHERE setting_key='smackverse_webcron_enabled';
UPDATE snap_settings SET setting_key='fediverse_website' WHERE setting_key='smackverse_website';

-- ── snap_multisite_nodes columns ───────────────────────────────────
-- These are the HUB's CACHED view of each spoke's fediverse tallies; they
-- refresh from the next heartbeat, so we add the new columns and let schema
-- drift-cleanup drop the old smackverse_* ones. No fragile data-copy needed.
ALTER TABLE snap_multisite_nodes ADD COLUMN IF NOT EXISTS `fediverse_enabled`   TINYINT(1)   NOT NULL DEFAULT 0;
ALTER TABLE snap_multisite_nodes ADD COLUMN IF NOT EXISTS `fediverse_followers` INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE snap_multisite_nodes ADD COLUMN IF NOT EXISTS `fediverse_following` INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE snap_multisite_nodes ADD COLUMN IF NOT EXISTS `fediverse_likes`     INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE snap_multisite_nodes ADD COLUMN IF NOT EXISTS `fediverse_boosts`    INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE snap_multisite_nodes ADD COLUMN IF NOT EXISTS `fediverse_replies`   INT UNSIGNED NOT NULL DEFAULT 0;

-- ===== SNAPSMACK EOF =====
