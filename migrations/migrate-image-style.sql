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
-- Safe to run multiple times -- migration runner skips 1060 (ER_DUP_FIELDNAME)
-- so duplicate ADD COLUMN attempts are treated as no-ops.
-- =============================================================================

ALTER TABLE snap_post_images
    ADD COLUMN img_size_pct     TINYINT UNSIGNED NOT NULL DEFAULT 100;

ALTER TABLE snap_post_images
    ADD COLUMN img_border_px    TINYINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE snap_post_images
    ADD COLUMN img_border_color CHAR(7) NOT NULL DEFAULT '#000000';

ALTER TABLE snap_post_images
    ADD COLUMN img_bg_color     CHAR(7) NOT NULL DEFAULT '#ffffff';

ALTER TABLE snap_post_images
    ADD COLUMN img_shadow       TINYINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE snap_posts
    ADD COLUMN post_img_size_pct  TINYINT UNSIGNED NOT NULL DEFAULT 100;

ALTER TABLE snap_posts
    ADD COLUMN post_border_px     TINYINT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE snap_posts
    ADD COLUMN post_border_color  CHAR(7) NOT NULL DEFAULT '#000000';

ALTER TABLE snap_posts
    ADD COLUMN post_bg_color      CHAR(7) NOT NULL DEFAULT '#ffffff';

ALTER TABLE snap_posts
    ADD COLUMN post_shadow        TINYINT UNSIGNED NOT NULL DEFAULT 0;
