-- Migration: 0.7.4b — Community engagement enhancements
--
-- 1. Add edited_at column to snap_community_comments (future "edited" indicator).
-- 2. Add guest_hash column + index to snap_likes (anonymous likes via IP hash).
-- 3. Add guest_hash column + index to snap_reactions (anonymous reactions via IP hash).
--
-- Idempotent: ADD COLUMN caught by errno 1060 if column already exists;
--             ADD INDEX caught by errno 1061 if index already exists.

-- --- Comments: edited timestamp ---
ALTER TABLE snap_community_comments
    ADD COLUMN edited_at DATETIME DEFAULT NULL AFTER created_at;

-- --- Likes: anonymous guest support ---
ALTER TABLE snap_likes
    ADD COLUMN guest_hash VARCHAR(64) DEFAULT NULL AFTER user_id;

ALTER TABLE snap_likes
    ADD INDEX idx_likes_guest (post_id, guest_hash);

-- --- Reactions: anonymous guest support ---
ALTER TABLE snap_reactions
    ADD COLUMN guest_hash VARCHAR(64) DEFAULT NULL AFTER user_id;

ALTER TABLE snap_reactions
    ADD INDEX idx_reactions_guest (post_id, guest_hash);
