-- ============================================================
-- SNAPSMACK FORUM — Add moderator role + hub install
-- Target DB: squir871_smackforum
-- Run via phpMyAdmin on snapsmack.ca
-- ============================================================

-- Add is_moderator flag to installs
ALTER TABLE ss_forum_installs
    ADD COLUMN is_moderator TINYINT(1) NOT NULL DEFAULT 0 AFTER is_banned;

-- Register snapsmack.ca itself as the hub install so Smack Central
-- can post threads and replies with a proper author identity.
-- The api_key 'hub_internal' is never used over the wire — sc-forum.php
-- writes directly to the DB — but it satisfies the UNIQUE constraint.
INSERT IGNORE INTO ss_forum_installs
    (api_key, domain, display_name, ss_version, is_moderator)
VALUES
    ('hub_internal_reserved', 'snapsmack.ca', 'SnapSmack HQ', '0.7.3', 1);
