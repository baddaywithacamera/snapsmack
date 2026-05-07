<?php
/**
 * SNAPSMACK - Migration 052: blogroll source_hub_url + Hub-cat cleanup
 *
 * Adds `source_hub_url` to snap_blogroll so hub-synced entries can be
 * identified and re-synced without disturbing the spoke's own additions.
 *
 * Migrates existing entries that landed in legacy "Hub: <url>" categories:
 *   - Stamps source_hub_url with the hub URL parsed out of the category name.
 *   - Sets cat_id=0 (uncategorized) on those rows so the next hub push can
 *     re-bucket them into the hub's actual category structure.
 *   - Deletes the now-empty "Hub: <url>" categories themselves.
 *
 * Idempotent: column add and updates are guarded.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$migration_name = '052_blogroll_source_hub_url';

try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM snap_migrations WHERE migration = ?");
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    // 1) Add source_hub_url column if missing.
    $col_check = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'snap_blogroll'
           AND COLUMN_NAME  = 'source_hub_url'"
    );
    $col_check->execute();
    if ((int)$col_check->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE `snap_blogroll`
             ADD COLUMN `source_hub_url` varchar(255)
             COLLATE utf8mb4_unicode_ci DEFAULT NULL,
             ADD KEY `idx_source_hub_url` (`source_hub_url`)"
        );
    }

    // 2) Stamp source_hub_url on entries currently in any 'Hub: ...' category
    //    and uncategorize them so the next sync can re-bucket properly.
    $pdo->exec(
        "UPDATE snap_blogroll b
         INNER JOIN snap_blogroll_cats c ON b.cat_id = c.id
         SET b.source_hub_url = TRIM(SUBSTRING(c.cat_name, 6)),
             b.cat_id = 0
         WHERE c.cat_name LIKE 'Hub: %'"
    );

    // 3) Delete legacy 'Hub: <url>' categories — they no longer have entries.
    $pdo->exec("DELETE FROM snap_blogroll_cats WHERE cat_name LIKE 'Hub: %'");

    $pdo->prepare("INSERT INTO snap_migrations (migration, applied_at) VALUES (?, NOW())")
        ->execute([$migration_name]);

    return ['status' => 'ok', 'message' => 'snap_blogroll.source_hub_url added; legacy Hub: categories migrated.'];

} catch (PDOException $e) {
    return ['status' => 'error', 'message' => $e->getMessage()];
}
// ===== SNAPSMACK EOF =====
