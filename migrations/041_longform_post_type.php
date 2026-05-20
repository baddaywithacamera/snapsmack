<?php
/**
 * SNAPSMACK - Migration 041
 *
 * Extends snap_posts to support SmackTalk longform posts:
 *   - Adds content LONGTEXT column to snap_posts (longform body)
 *   - Adds 'longform' to the post_type ENUM
 *   - Adds featured_asset_id column (hero image FK to snap_assets)
 *   - Creates snap_post_cat_map and snap_post_album_map junction tables
 *     for direct post → category/album associations
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


function migration_041_up(PDO $pdo): void {
    // --- content LONGTEXT on snap_posts ---
    $cols = $pdo->query("SHOW COLUMNS FROM `snap_posts` LIKE 'content'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("
            ALTER TABLE `snap_posts`
            ADD COLUMN `content` LONGTEXT COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL
                COMMENT 'Body content for longform (SmackTalk) posts'
        ");
    }

    // --- Add 'longform' to post_type ENUM ---
    $row = $pdo->query("SHOW COLUMNS FROM `snap_posts` WHERE Field = 'post_type'")->fetch(PDO::FETCH_ASSOC);
    if ($row && strpos($row['Type'], 'longform') === false) {
        $pdo->exec("
            ALTER TABLE `snap_posts`
            MODIFY COLUMN `post_type`
                ENUM('single','carousel','panorama','longform')
                COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single'
        ");
    }

    // --- featured_asset_id on snap_posts ---
    $cols = $pdo->query("SHOW COLUMNS FROM `snap_posts` LIKE 'featured_asset_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("
            ALTER TABLE `snap_posts`
            ADD COLUMN `featured_asset_id` INT UNSIGNED NULL DEFAULT NULL
                COMMENT 'Hero image for longform posts — FK to snap_assets.id'
        ");
    }

    // --- snap_post_cat_map ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `snap_post_cat_map` (
            `post_id` INT UNSIGNED NOT NULL,
            `cat_id`  INT UNSIGNED NOT NULL,
            PRIMARY KEY (`post_id`, `cat_id`),
            KEY `idx_pcm_cat` (`cat_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // --- snap_post_album_map ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `snap_post_album_map` (
            `post_id`  INT UNSIGNED NOT NULL,
            `album_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`post_id`, `album_id`),
            KEY `idx_pam_album` (`album_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function migration_041_down(PDO $pdo): void {
    $pdo->exec("DROP TABLE IF EXISTS `snap_post_album_map`");
    $pdo->exec("DROP TABLE IF EXISTS `snap_post_cat_map`");
    $pdo->exec("ALTER TABLE `snap_posts` DROP COLUMN IF EXISTS `featured_asset_id`");
    $pdo->exec("ALTER TABLE `snap_posts` DROP COLUMN IF EXISTS `content`");
    $pdo->exec("
        ALTER TABLE `snap_posts`
        MODIFY COLUMN `post_type`
            ENUM('single','carousel','panorama')
            COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single'
    ");
}
// ===== SNAPSMACK EOF =====
