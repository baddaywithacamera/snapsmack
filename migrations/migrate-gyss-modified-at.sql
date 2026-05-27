-- SnapSmack Migration: migrate-gyss-modified-at.sql
-- Adds modified_at column to snap_images for GYSS conflict detection.
-- GYSS (GET YOUR SHIT SORTED) compares expected_modified_at on batch-update
-- to detect concurrent edits. Column auto-updates on any row change.
--
-- SNAPSMACK_EOF_HEADER
--     -- ===== SNAPSMACK EOF =====
-- Last non-empty line of this file MUST match the line above.

ALTER TABLE `snap_images`
    ADD COLUMN IF NOT EXISTS `modified_at` datetime NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
        COMMENT 'Auto-updated on any row change. Used by GYSS for conflict detection.'
    AFTER `sort_order`;

-- Back-fill: set modified_at = img_date for all existing rows so they have
-- a sensible baseline rather than all sharing the migration timestamp.
UPDATE `snap_images` SET `modified_at` = `img_date`;

-- ===== SNAPSMACK EOF =====
