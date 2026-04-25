<?php
/**
 * SNAPSMACK - Migration 039
 *
 * Adds featured_post_id to snap_categories and snap_albums.
 * The featured image is the hero image from any post on the site —
 * used as the representative thumbnail for the container in gallery
 * views, collection landing pages, and skin renders.
 */

function migration_039_up(PDO $pdo): void {
    // --- snap_categories ---
    $rows = $pdo->query("SHOW COLUMNS FROM `snap_categories` LIKE 'featured_post_id'")->fetchAll();
    if (empty($rows)) {
        $pdo->exec("
            ALTER TABLE `snap_categories`
            ADD COLUMN `featured_post_id` INT UNSIGNED NULL DEFAULT NULL
                COMMENT 'Hero image source for category gallery/landing views'
        ");
    }

    // --- snap_albums ---
    $rows = $pdo->query("SHOW COLUMNS FROM `snap_albums` LIKE 'featured_post_id'")->fetchAll();
    if (empty($rows)) {
        $pdo->exec("
            ALTER TABLE `snap_albums`
            ADD COLUMN `featured_post_id` INT UNSIGNED NULL DEFAULT NULL
                COMMENT 'Hero image source for album gallery/landing views'
        ");
    }
}

function migration_039_down(PDO $pdo): void {
    $pdo->exec("ALTER TABLE `snap_categories` DROP COLUMN IF EXISTS `featured_post_id`");
    $pdo->exec("ALTER TABLE `snap_albums` DROP COLUMN IF EXISTS `featured_post_id`");
}
