-- ============================================================
-- SnapSmack Migration: 0.7.7
-- ============================================================
-- Compatible with MySQL 5.7+ and MariaDB 10.3+.
-- All statements are idempotent — safe to re-run.
-- ============================================================


-- ------------------------------------------------------------
-- 1. img_source_file COLUMN on snap_images
-- Stores the original filename from the local machine at post
-- time (e.g. 20260318_153727.jpg). Allows matching server
-- records back to source files for Drive backfill etc.
-- ------------------------------------------------------------

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'snap_images'
    AND COLUMN_NAME  = 'img_source_file'
);
SET @q = IF(
  @col_exists = 0,
  'ALTER TABLE `snap_images` ADD COLUMN `img_source_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT \'Original filename on the posting machine at upload time\' AFTER `img_file`',
  'SELECT 1'
);
PREPARE _stmt FROM @q; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;


-- ------------------------------------------------------------
-- 2. UPDATE INSTALLED VERSION
-- ------------------------------------------------------------

INSERT INTO `snap_settings` (`setting_key`, `setting_val`)
VALUES ('installed_version', '0.7.7')
ON DUPLICATE KEY UPDATE `setting_val` = '0.7.7';


-- ------------------------------------------------------------
-- 3. RECORD MIGRATION
-- ------------------------------------------------------------

INSERT IGNORE INTO `snap_migrations` (`migration`, `applie