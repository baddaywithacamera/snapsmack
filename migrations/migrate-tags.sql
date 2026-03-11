-- ============================================================
-- SNAPSMACK - Hashtag Tables Migration
-- Alpha v0.7.1
--
-- Creates snap_tags (global tag registry) and snap_image_tags
-- (image ↔ tag junction). MySQL-safe: checks information_schema
-- before creating. Safe to re-run.
-- ============================================================

DROP PROCEDURE IF EXISTS snap_create_tag_tables;

DELIMITER ;;

CREATE PROCEDURE snap_create_tag_tables()
BEGIN

    -- snap_tags: one row per unique tag slug
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'snap_tags'
    ) THEN
        CREATE TABLE snap_tags (
            id         INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
            tag        VARCHAR(100)     NOT NULL,           -- display form (lowercase for now)
            slug       VARCHAR(100)     NOT NULL,           -- normalised, indexed
            use_count  INT UNSIGNED     DEFAULT 0,          -- published image count, maintained by snap_sync_tags()
            created_at TIMESTAMP        DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    END IF;

    -- snap_image_tags: junction between snap_images and snap_tags
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'snap_image_tags'
    ) THEN
        CREATE TABLE snap_image_tags (
            id         INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
            image_id   INT UNSIGNED     NOT NULL,
            tag_id     INT UNSIGNED     NOT NULL,
            created_at TIMESTAMP        DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_image_tag (image_id, tag_id),
            KEY idx_tag_id   (tag_id),
            KEY idx_image_id (image_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    END IF;

END;;

DELIMITER ;

CALL snap_create_tag_tables();
DROP PROCEDURE IF EXISTS snap_create_tag_tables;
