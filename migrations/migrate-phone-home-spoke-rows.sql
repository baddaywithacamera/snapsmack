-- SnapSmack migration: add role + hub_uid to sc_phone_home
-- Allows spokes to send a slim ping without inflating the fleet count.
-- role:    'hub' (default) for hub/standalone installs, 'spoke' for spokes
-- hub_uid: for spoke rows, the uid of their hub; NULL for hub/standalone rows
--
-- Fleet count query must use WHERE role = 'hub' after this migration.

ALTER TABLE sc_phone_home
    ADD COLUMN IF NOT EXISTS `role`    VARCHAR(10) NOT NULL DEFAULT 'hub' AFTER `spoke_count`,
    ADD COLUMN IF NOT EXISTS `hub_uid` CHAR(32)    NULL     DEFAULT NULL  AFTER `role`;

ALTER TABLE sc_phone_home ADD INDEX idx_hub_uid (`hub_uid`);
-- ===== SNAPSMACK EOF =====
