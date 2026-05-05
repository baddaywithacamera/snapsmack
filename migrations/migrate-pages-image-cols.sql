-- SNAPSMACK_EOF_HEADER
--     -- ===== SNAPSMACK EOF =====
-- Last non-empty line of this file MUST match the line above.
-- Missing or different = truncated/corrupted. Restore before saving.


-- Migration: Add image display columns to snap_pages
-- Introduced: Alpha 0.7.8 (missed at the time), backfilled in 0.7.9b
-- Safe to run multiple times (updater ignores ER_DUP_FIELDNAME / errno 1060)

ALTER TABLE `snap_pages` ADD COLUMN `image_size`   varchar(20) NOT NULL DEFAULT 'full'   AFTER `image_asset`;
ALTER TABLE `snap_pages` ADD COLUMN `image_align`  varchar(20) NOT NULL DEFAULT 'center' AFTER `image_size`;
ALTER TABLE `snap_pages` ADD COLUMN `image_shadow` tinyint(1)  NOT NULL DEFAULT 0        AFTER `image_align`;
-- ===== SNAPSMACK EOF =====
