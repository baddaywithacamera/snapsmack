-- Migration: snap_collection_items polymorphic schema
-- Adds item_type, item_id, sort_order columns required for polymorphic
-- collection membership (posts, albums, categories).
-- Existing rows are left in place; new columns get safe defaults.
-- ===== SNAPSMACK EOF =====

ALTER TABLE `snap_collection_items`
    ADD COLUMN IF NOT EXISTS `item_type`  ENUM('post','album','category') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'post' AFTER `collection_id`,
    ADD COLUMN IF NOT EXISTS `item_id`    INT UNSIGNED NOT NULL DEFAULT 0 AFTER `item_type`,
    ADD COLUMN IF NOT EXISTS `sort_order` INT NOT NULL DEFAULT 0 AFTER `item_id`;
