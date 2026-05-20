<?php
/**
 * SNAPSMACK - Migration 053: deduplicate hub-synced blogroll entries
 *
 * 0.7.67 introduced source_hub_url to track which spoke entries came from
 * which hub. Migration 052 stamped source_hub_url with hostname-only form
 * (e.g. "foundtextures.ca") parsed out of the legacy "Hub: <url>" category
 * names. But the 0.7.67 sync code stored source_hub_url as the FULL URL
 * the hub sent (e.g. "https://foundtextures.ca/"). So when the hub
 * re-pushed, the DELETE-by-source_hub_url didn't match the migration-052
 * rows, and fresh rows were inserted alongside the old ones. Spokes ended
 * up showing duplicate peers under both the old "Hub:" category (where
 * those rows had been migrated to cat_id=0 / uncategorized) and the new
 * proper categories.
 *
 * Fix in 0.7.68 (a) normalizes hub_url to hostname at sync time, and
 * (b) this migration which wipes ALL hub-synced entries plus any leftover
 * "Hub: <url>" categories. Locally-added peers (source_hub_url IS NULL,
 * not in any Hub: category) are untouched. After running, ask the hub to
 * re-push the blogroll — entries come back cleanly with proper categories
 * and consistent hostname-form source_hub_url.
 *
 * Idempotent: safe to re-run — second run finds nothing to delete.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$migration_name = '053_blogroll_dedupe_hub_synced';

try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM snap_migrations WHERE migration = ?");
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    // 1) Drop every entry that was hub-synced — by source_hub_url marker
    //    (post-052) OR by being in a legacy "Hub: <url>" category (pre-052
    //    if migration 052 did not run yet for any reason). Locally-added
    //    peers (source_hub_url IS NULL and not in Hub: %) are untouched.
    $pdo->exec(
        "DELETE FROM snap_blogroll
         WHERE source_hub_url IS NOT NULL
            OR cat_id IN (
                SELECT id FROM (
                    SELECT id FROM snap_blogroll_cats WHERE cat_name LIKE 'Hub: %'
                ) AS x
            )"
    );

    // 2) Drop any leftover Hub: ... categories — they have no entries now.
    $pdo->exec("DELETE FROM snap_blogroll_cats WHERE cat_name LIKE 'Hub: %'");

    $pdo->prepare("INSERT INTO snap_migrations (migration, applied_at) VALUES (?, NOW())")
        ->execute([$migration_name]);

    return [
        'status'  => 'ok',
        'message' => 'Hub-synced blogroll entries cleared. Trigger a hub push to repopulate.',
    ];

} catch (PDOException $e) {
    return ['status' => 'error', 'message' => $e->getMessage()];
}
// ===== SNAPSMACK EOF =====
