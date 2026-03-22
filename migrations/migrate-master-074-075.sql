-- ============================================================
-- SNAPSMACK — Master Migration Script
-- Covers: 0.7.4b · 0.7.4c · 0.7.4d · mosaic · 0.7.5a
--
-- Safe to run against any install regardless of current state.
-- Columns and indexes that already exist are skipped silently.
-- Tables that already exist are left untouched.
--
-- Run via phpMyAdmin → SQL tab, or cPanel → MySQL Databases → SQL.
-- ============================================================


-- ============================================================
-- HELPER: conditional column + index addition
-- Dropped and recreated so re-runs are always clean.
-- ============================================================

DROP PROCEDURE IF EXISTS _ss_add_column;
DROP PROCEDURE IF EXISTS _ss_add_index;

DELIMITER //

CREATE PROCEDURE _ss_add_column(
    IN p_table VARCHAR(64),
    IN p_col   VARCHAR(64),
    IN p_def   TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_col
    ) THEN
        SET @_sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE _stmt FROM @_sql;
        EXECUTE _stmt;
        DEALLOCATE PREPARE _stmt;
    END IF;
END //

CREATE PROCEDURE _ss_add_index(
    IN p_table VARCHAR(64),
    IN p_index VARCHAR(64),
    IN p_def   TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND INDEX_NAME   = p_index
    ) THEN
        SET @_sql = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX `', p_index, '` ', p_def);
        PREPARE _stmt FROM @_sql;
        EXECUTE _stmt;
        DEALLOCATE PREPARE _stmt;
    END IF;
END //

DELIMITER ;


-- ============================================================
-- 0.7.4b — Community engagement enhancements
-- ============================================================

CALL _ss_add_column('snap_community_comments', 'edited_at',
    'DATETIME DEFAULT NULL');

CALL _ss_add_column('snap_likes', 'guest_hash',
    'VARCHAR(64) DEFAULT NULL');
CALL _ss_add_index('snap_likes', 'idx_likes_guest',
    '(post_id, guest_hash)');

CALL _ss_add_column('snap_reactions', 'guest_hash',
    'VARCHAR(64) DEFAULT NULL');
CALL _ss_add_index('snap_reactions', 'idx_reactions_guest',
    '(post_id, guest_hash)');


-- ============================================================
-- 0.7.4c — Hex colour tag support
-- ============================================================

CALL _ss_add_column('snap_tags', 'color_family',
    'VARCHAR(20) DEFAULT NULL');
CALL _ss_add_index('snap_tags', 'idx_tags_color_family',
    '(color_family)');


-- ============================================================
-- 0.7.4d — Manual image sort order
-- ============================================================

CALL _ss_add_column('snap_images', 'sort_order',
    'INT NOT NULL DEFAULT 0');

-- Seed sort_order from current date order only on rows still at 0
SET @row := 0;
UPDATE snap_images
SET    sort_order = (@row := @row + 1)
WHERE  sort_order = 0
ORDER  BY img_date DESC;


-- ============================================================
-- Mosaic albums
-- ============================================================

CREATE TABLE IF NOT EXISTS `snap_mosaics` (
    `id`         INT                NOT NULL AUTO_INCREMENT,
    `title`      VARCHAR(255)       NOT NULL DEFAULT 'Untitled Mosaic',
    `asset_ids`  JSON               NOT NULL,
    `gap`        TINYINT UNSIGNED   NOT NULL DEFAULT 4,
    `max_width`  SMALLINT UNSIGNED  NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 0.7.5a — Blogroll categories
-- ============================================================

CREATE TABLE IF NOT EXISTS `snap_blogroll_cats` (
    `id`       INT          NOT NULL AUTO_INCREMENT,
    `cat_name` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- CLEANUP: remove helper procedures
-- ============================================================

DROP PROCEDURE IF EXISTS _ss_add_column;
DROP PROCEDURE IF EXISTS _ss_add_index;
