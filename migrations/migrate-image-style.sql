-- =============================================================================
-- SNAPSMACK - Migration: Image Frame Style Columns
-- Alpha v0.7.1
--
-- Adds per-image and per-carousel frame styling columns to support The Grid's
-- IMAGE FRAME customisation feature (tg_customize_level skin setting).
--
-- snap_post_images: img_size_pct, img_border_px, img_border_color,
--                   img_bg_color, img_shadow
-- snap_posts:       post_img_size_pct, post_border_px, post_border_color,
--                   post_bg_color, post_shadow
--
-- Safe to run multiple times — each stored procedure checks information_schema
-- before issuing the ALTER TABLE (MySQL and MariaDB compatible).
-- =============================================================================

DELIMITER //

CREATE PROCEDURE snap_add_pi_style_cols()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE  TABLE_SCHEMA = DATABASE()
          AND  TABLE_NAME   = 'snap_post_images'
          AND  COLUMN_NAME  = 'img_size_pct'
    ) THEN
        ALTER TABLE snap_post_images
            ADD COLUMN img_size_pct     TINYINT UNSIGNED NOT NULL DEFAULT 100
                COMMENT 'Image size within tile/slide (75–100 in 5% steps)',
            ADD COLUMN img_border_px    TINYINT UNSIGNED NOT NULL DEFAULT 0
                COMMENT 'Border thickness in px around the image (0–20)',
            ADD COLUMN img_border_color CHAR(7)          NOT NULL DEFAULT '#000000'
                COMMENT 'CSS hex colour for the image border',
            ADD COLUMN img_bg_color     CHAR(7)          NOT NULL DEFAULT '#ffffff'
                COMMENT 'Background colour shown behind the image when size < 100%',
            ADD COLUMN img_shadow       TINYINT UNSIGNED NOT NULL DEFAULT 0
                COMMENT '0=none  1=soft  2=medium  3=heavy drop shadow on image';
    END IF;
END //

CREATE PROCEDURE snap_add_post_style_cols()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE  TABLE_SCHEMA = DATABASE()
          AND  TABLE_NAME   = 'snap_posts'
          AND  COLUMN_NAME  = 'post_img_size_pct'
    ) THEN
        ALTER TABLE snap_posts
            ADD COLUMN post_img_size_pct  TINYINT UNSIGNED NOT NULL DEFAULT 100
                COMMENT 'Per-carousel default image size (per_carousel level)',
            ADD COLUMN post_border_px     TINYINT UNSIGNED NOT NULL DEFAULT 0
                COMMENT 'Per-carousel default border thickness in px',
            ADD COLUMN post_border_color  CHAR(7)          NOT NULL DEFAULT '#000000'
                COMMENT 'Per-carousel default border CSS hex colour',
            ADD COLUMN post_bg_color      CHAR(7)          NOT NULL DEFAULT '#ffffff'
                COMMENT 'Per-carousel default background colour',
            ADD COLUMN post_shadow        TINYINT UNSIGNED NOT NULL DEFAULT 0
                COMMENT '0=none  1=soft  2=medium  3=heavy (per-carousel)';
    END IF;
END //

DELIMITER ;

CALL snap_add_pi_style_cols();
CALL snap_add_post_style_cols();

DROP PROCEDURE IF EXISTS snap_add_pi_style_cols;
DROP PROCEDURE IF EXISTS snap_add_post_style_cols;
