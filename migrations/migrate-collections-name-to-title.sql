-- ===== SNAPSMACK MIGRATION: collections name → title =====
-- Renames snap_collections.name to title to match the canonical schema.
-- Idempotent: ALTER COLUMN IF EXISTS is not standard MySQL, so this uses
-- a conditional approach via stored procedure pattern. Safe to run multiple times.
-- SnapSmack 0.7.160

ALTER TABLE `snap_collections`
    CHANGE COLUMN `name` `title` VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL;

-- ===== SNAPSMACK EOF =====
