-- ============================================================
-- SnapSmack Migration: 0.7.6 "Poäng Thang"
-- ============================================================
-- Compatible with MySQL 5.7+ and MariaDB 10.3+.
-- All statements are idempotent — safe to re-run.
-- Conditional column/index additions use the SET @q = IF()
-- + PREPARE pattern to avoid MySQL 8.0-only IF NOT EXISTS
-- ALTER TABLE syntax.
-- ============================================================


-- ------------------------------------------------------------
-- 1. DROP MOSAIC TABLES
-- Mosaic tooling removed from core in 0.7.6.
-- ------------------------------------------------------------

DROP TABLE IF EXISTS `snap_mosaic_media`;
DROP TABLE IF EXISTS `snap_mosaics`;


-- ------------------------------------------------------------
-- 2. snap_posts TABLE
-- Required for Carousel post type. Missing on 0.7.0-alpha.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `snap_posts` (
  `id`                int NOT NULL AUTO_INCREMENT,
  `title`             varchar(500)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`              varchar(600)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `description`       text          COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `post_type`         enum('single','carousel','panorama') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single',
  `status`            varchar(20)   COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
  `created_at`        datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `allow_comments`    tinyint(1)    NOT NULL DEFAULT '1',
  `allow_download`    tinyint(1)    NOT NULL DEFAULT '0',
  `download_url`      varchar(500)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `download_count`    int           NOT NULL DEFAULT '0',
  `panorama_rows`     tinyint       NOT NULL DEFAULT '1',
  `import_source`     varchar(50)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_id`         varchar(200)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `post_img_size_pct` tinyint UNSIGNED NOT NULL DEFAULT '100',
  `post_border_px`    tinyint UNSIGNED NOT NULL DEFAULT '0',
  `post_border_color` char(7)       COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#000000',
  `post_bg_color`     char(7)       COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#ffffff',
  `post_shadow`       tinyint UNSIGNED NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 3. snap_image_tags TABLE
-- Junction table linking snap_images to snap_tags.
-- Missing on very old installs.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `snap_image_tags` (
  `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
  `image_id`   int UNSIGNED NOT NULL,
  `tag_id`     int UNSIGNED NOT NULL,
  `created_at` timestamp    NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_image_tag` (`image_id`, `tag_id`),
  KEY `idx_image_tags_image_id` (`image_id`),
  KEY `idx_image_tags_tag_id`   (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 4. snap_tags TABLE
-- Core tagging table. Missing on very old installs (pre-0.7.2).
-- Created with color_family already included so section 5
-- only needs to run on installs that have the table but lack
-- the column.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `snap_tags` (
  `id`           int UNSIGNED  NOT NULL AUTO_INCREMENT,
  `tag`          varchar(100)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`         varchar(100)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `use_count`    int UNSIGNED  DEFAULT '0',
  `color_family` varchar(20)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at`   timestamp     NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tag_slug` (`slug`),
  KEY `idx_tags_color_family` (`color_family`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 5. snap_password_resets TABLE
-- Required for the "forgot password" flow in community auth.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `snap_password_resets` (
  `id`     int         NOT NULL AUTO_INCREMENT,
  `email`  varchar(255) NOT NULL,
  `token`  varchar(64)  NOT NULL,
  `expiry` datetime     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_password_resets_email` (`email`),
  KEY `idx_password_resets_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 6. color_family COLUMN on snap_tags
-- Added in 0.7.4c. Uses prepared statement pattern for
-- MySQL 5.7 compatibility (no ADD COLUMN IF NOT EXISTS).
-- ------------------------------------------------------------

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'snap_tags'
    AND COLUMN_NAME  = 'color_family'
);
SET @q = IF(
  @col_exists = 0,
  'ALTER TABLE `snap_tags` ADD COLUMN `color_family` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `use_count`',
  'SELECT 1'
);
PREPARE _stmt FROM @q; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'snap_tags'
    AND INDEX_NAME   = 'idx_tags_color_family'
);
SET @q = IF(
  @idx_exists = 0,
  'ALTER TABLE `snap_tags` ADD INDEX `idx_tags_color_family` (`color_family`)',
  'SELECT 1'
);
PREPARE _stmt FROM @q; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;


-- ------------------------------------------------------------
-- 7. GUEST SUPPORT COLUMNS on snap_community_comments
-- Makes user_id nullable; adds guest_name / guest_email.
-- ------------------------------------------------------------

-- Make user_id nullable (safe to run even if already nullable)
ALTER TABLE `snap_community_comments`
  MODIFY COLUMN `user_id` int DEFAULT NULL;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'snap_community_comments'
    AND COLUMN_NAME  = 'guest_name'
);
SET @q = IF(
  @col_exists = 0,
  'ALTER TABLE `snap_community_comments` ADD COLUMN `guest_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `user_id`',
  'SELECT 1'
);
PREPARE _stmt FROM @q; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'snap_community_comments'
    AND COLUMN_NAME  = 'guest_email'
);
SET @q = IF(
  @col_exists = 0,
  'ALTER TABLE `snap_community_comments` ADD COLUMN `guest_email` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `guest_name`',
  'SELECT 1'
);
PREPARE _stmt FROM @q; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;


-- ------------------------------------------------------------
-- 8. SEED NEW SETTINGS
-- site_mode: existing installs default to 'photoblog'.
-- ------------------------------------------------------------

INSERT IGNORE INTO `snap_settings` (`setting_key`, `setting_val`)
VALUES ('site_mode', 'photoblog');


-- ------------------------------------------------------------
-- 9. UPDATE INSTALLED VERSION
-- ------------------------------------------------------------

INSERT INTO `snap_settings` (`setting_key`, `setting_val`)
VALUES ('installed_version', '0.7.6')
ON DUPLICATE KEY UPDATE `setting_val` = '0.7.6';


-- ------------------------------------------------------------
-- 10. RECORD MIGRATION
-- ------------------------------------------------------------

INSERT IGNORE INTO `snap_migrations` (`migration`, `applied_at`)
VALUES ('migrate-076.sql', NOW());
