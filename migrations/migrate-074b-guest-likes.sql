-- Migration: 0.7.4b — Add guest_hash column to snap_likes for anonymous likes
-- Allows visitors to like posts without creating a community account.
-- Likes are tracked by hashed IP to prevent double-likes without storing PII.
-- Idempotent: ADD COLUMN caught by errno 1060 if column already exists.

ALTER TABLE snap_likes
    ADD COLUMN guest_hash VARCHAR(64) DEFAULT NULL AFTER user_id;

-- Allow lookups by guest_hash for toggle checks
ALTER TABLE snap_likes
    ADD INDEX idx_likes_guest (post_id, guest_hash);
