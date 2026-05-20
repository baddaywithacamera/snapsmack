<?php
/**
 * SNAPSMACK - Migration 040
 *
 * Creates the snap_collections and snap_collection_items tables.
 * Collections are heterogeneous parent containers — they hold posts,
 * albums, and categories in any combination. Membership is live, not
 * a snapshot: member albums/categories resolve to their current posts
 * at render time.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


function migration_040_up(PDO $pdo): void {
    // snap_collections
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `snap_collections` (
            `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `name`             VARCHAR(150)  NOT NULL,
            `slug`             VARCHAR(150)  NOT NULL,
            `description`      TEXT          NULL,
            `featured_post_id` INT UNSIGNED  NULL DEFAULT NULL
                               COMMENT 'Hero image source; NULL = fall back to most recent member post',
            `sort_order`       INT           NOT NULL DEFAULT 0,
            `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // snap_collection_items
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `snap_collection_items` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `collection_id` INT UNSIGNED NOT NULL,
            `item_type`     ENUM('post','album','category') NOT NULL,
            `item_id`       INT UNSIGNED NOT NULL
                            COMMENT 'ID in snap_posts, snap_albums, or snap_categories respectively',
            `sort_order`    INT          NOT NULL DEFAULT 0,
            `added_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_collection_item` (`collection_id`, `item_type`, `item_id`),
            KEY `idx_collection` (`collection_id`),
            CONSTRAINT `fk_ci_collection`
                FOREIGN KEY (`collection_id`) REFERENCES `snap_collections` (`id`)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function migration_040_down(PDO $pdo): void {
    $pdo->exec("DROP TABLE IF EXISTS `snap_collection_items`");
    $pdo->exec("DROP TABLE IF EXISTS `snap_collections`");
}
// ===== SNAPSMACK EOF =====
