-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
-- Public byline kept separately from the immutable login username.

ALTER TABLE `snap_users`
    ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(100) DEFAULT NULL AFTER `username`;

-- ===== SNAPSMACK EOF =====
