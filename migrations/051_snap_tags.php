<?php
/**
 * SNAPSMACK - Migration 051: snap_tags table
 *
 * Creates the snap_tags table on installs that pre-date its addition to
 * the canonical schema. SYBU's sybu-data.php endpoint queries this table
 * for tag enrichment — without it, the endpoint 500s after auth.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, plus column-level guards for
 * created_at / color_family on installs that have an older snap_tags
 * shape from the legacy catch-up SQL.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$migration_name = '051_snap_tags';

try {
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM snap_migrations WHERE migration = ?"
    );
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    // Create table if it doesn't exist (canonical shape).
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `snap_tags` (
            `id`           int unsigned NOT NULL AUTO_INCREMENT,
            `tag`          varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
            `slug`         varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
            `use_count`    int unsigned DEFAULT 0,
            `color_family` varchar(20)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `created_at`   timestamp    DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_tag_slug` (`slug`),
            KEY `idx_tags_color_family` (`color_family`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // If table existed without created_at (legacy shape), add it.
    $col_check = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'snap_tags'
           AND COLUMN_NAME  = 'created_at'"
    );
    $col_check->execute();
    if ((int)$col_check->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE `snap_tags`
             ADD COLUMN `created_at` timestamp DEFAULT CURRENT_TIMESTAMP"
        );
    }

    // Same guard for color_family (added 0.7.4/0.7.6).
    $col_check->execute();
    $col_check2 = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'snap_tags'
           AND COLUMN_NAME  = 'color_family'"
    );
    $col_check2->execute();
    if ((int)$col_check2->fetchColumn() === 0) {
        $pdo->exec(
            "ALTER TABLE `snap_tags`
             ADD COLUMN `color_family` varchar(20)
             COLLATE utf8mb4_unicode_ci DEFAULT NULL,
             ADD KEY `idx_tags_color_family` (`color_family`)"
        );
    }

    $pdo->prepare(
        "INSERT INTO snap_migrations (migration, applied_at) VALUES (?, NOW())"
    )->execute([$migration_name]);

    return ['status' => 'ok', 'message' => 'snap_tags table verified / created.'];

} catch (PDOException $e) {
    return ['status' => 'error', 'message' => $e->getMessage()];
}
// ===== SNAPSMACK EOF =====
