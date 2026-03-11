-- SnapSmack: Comment Identity Migration
-- Allows guest (unauthenticated) comments when the blog owner enables them.
-- Makes user_id nullable and adds guest_name / guest_email columns.
-- Safe to re-run: uses MODIFY/ADD only if column does not already exist.

-- Make user_id nullable so rows can exist without a community account.
ALTER TABLE snap_community_comments
    MODIFY COLUMN user_id INT UNSIGNED NULL DEFAULT NULL;

-- Add guest identity columns. Populated only when user_id IS NULL.
ALTER TABLE snap_community_comments
    ADD COLUMN IF NOT EXISTS guest_name  VARCHAR(100) NULL DEFAULT NULL AFTER user_id,
    ADD COLUMN IF NOT EXISTS guest_email VARCHAR(200) NULL DEFAULT NULL AFTER guest_name;
