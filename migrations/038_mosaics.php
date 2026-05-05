<?php
/**
 * SNAPSMACK - Migration 038
 *
 * Adds the snap_mosaics table for inline tiled image panels
 * embedded via [mosaic:ID] shortcode in post and page content.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


function migration_038_up(PDO $pdo): void {
    // snap_mosaics — stores image ID lists and gap setting for each mosaic
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `snap_mosaics` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title`      VARCHAR(150) NOT NULL DEFAULT 'Untitled Mosaic',
            `asset_ids`  LONGTEXT     NOT NULL COMMENT 'JSON array of snap_assets IDs in display order',
            `gap`        TINYINT      NOT NULL DEFAULT 4 COMMENT 'Gap between mosaic tiles in pixels (0–20)',
            `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function migration_038_down(PDO $pdo): void {
    $pdo->exec("DROP TABLE IF EXISTS `snap_mosaics`");
}
// ===== SNAPSMACK EOF =====
