-- Add per-user UI mode preference column to snap_users.
-- Replaces the old site-wide pimpmobile toggle in snap_settings.
-- Default 'bigwheel' preserves existing behaviour for all current users.

ALTER TABLE `snap_users`
    ADD COLUMN IF NOT EXISTS `ui_mode` VARCHAR(20) NOT NULL DEFAULT 'bigwheel' AFTER `preferred_skin`;

-- ===== SNAPSMACK EOF =====
