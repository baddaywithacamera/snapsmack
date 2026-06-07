-- SnapSmack migration: add key_type to snap_ohsnap_keys
-- Allows the key table to serve both Oh Snap! and SmackPress keys.
-- Safe to run multiple times (IF NOT EXISTS pattern via ALTER IGNORE / check).

ALTER TABLE `snap_ohsnap_keys`
    ADD COLUMN IF NOT EXISTS `key_type` VARCHAR(20)
        NOT NULL DEFAULT 'ohsnap'
        COMMENT 'ohsnap | smackpress | unzucker'
        AFTER `label`;

-- ===== SNAPSMACK EOF =====
