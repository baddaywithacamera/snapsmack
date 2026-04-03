-- ============================================================
-- SnapSmack Migration: 0.7.8
-- ============================================================
-- Compatible with MySQL 5.7+ and MariaDB 10.3+.
-- All statements are idempotent — safe to re-run.
-- ============================================================


-- ------------------------------------------------------------
-- 1. Per-page header image options on snap_pages
--    image_size:   full | medium | small
--    image_align:  center | left | right
--    image_shadow: 0 (none) | 1 (subtle drop shadow)
-- ------------------------------------------------------------

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'snap_pages'
    AND COLUMN_NAME  = 'image_size'
);
SET @q = IF(
  @col_exists = 0,
  'ALTER TABLE `snap_pages` ADD COLUMN `image_size` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'full\' AFTER `image_asset`',
  'SELECT 1'
);
PREPARE _stmt FROM @q; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'snap_pages'
    AND COLUMN_NAME  = 'image_align'
);
SET @q = IF(
  @col_exists = 0,
  'ALTER TABLE `snap_pages` ADD COLUMN `image_align` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT \'center\' AFTER `image_size`',
  'SELECT 1'
);
PREPARE _stmt FROM @q; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'snap_pages'
    AND COLUMN_NAME  = 'image_shadow'
);
SET @q = IF(
  @col_exists = 0,
  'ALTER TABLE `snap_pages` ADD COLUMN `image_shadow` tinyint(1) NOT NULL DEFAULT 0 AFTER `image_align`',
  'SELECT 1'
);
PREPARE _stmt FROM @q; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;


-- ------------------------------------------------------------
-- 2. UPDATE INSTALLED VERSION
-- ------------------------------------------------------------

INSERT INTO `snap_settings` (`setting_key`, `setting_val`)
VALUES ('installed_version', '0.7.8')
ON DUPLICATE KEY UPDATE `setting_val` = '0.7.8';


-- ------------------------------------------------------------
-- 3. RECORD MIGRATION
-- ------------------------------------------------------------

INSERT IGNORE INTO `snap_migrations` (`migration`, `applied_at`)
VALUES ('migrate-078.sql', NOW());
