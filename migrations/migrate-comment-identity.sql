-- SnapSmack: Comment Identity Migration
-- Allows guest (unauthenticated) comments when the blog owner enables them.
-- Makes user_id nullable and adds guest_name / guest_email columns.
--
-- Safe to re-run: the PHP migration runner catches MySQL error 1060
-- (ER_DUP_FIELDNAME) and treats it as a no-op, so these plain ADD COLUMN
-- statements work on MySQL 5.6+ without IF NOT EXISTS syntax.

-- Make user_id nullable so rows can exist without a community account.
ALTER TABLE snap_community_comments
    MODIFY COLUMN user_id INT UNSIGNED NULL DEFAULT NULL;

-- Add guest name column (required for guest comments; NULL for account comments).
ALTER TABLE snap_community_comments
    ADD COLUMN guest_name VARCHAR(100) NULL DEFAULT NULL AFTER user_id;

-- Add guest email column (optional; never displayed publicly).
ALTER TABLE snap_community_comments
    ADD COLUMN guest_email VARCHAR(200) NULL DEFAULT NULL AFTER guest_name;
