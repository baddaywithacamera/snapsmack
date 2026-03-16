-- Migration: 0.7.4b — Add guest_hash column to snap_reactions for anonymous reactions
-- Allows visitors to react to posts without creating a community account.
-- Reactions are tracked by hashed IP to prevent duplicates without storing PII.
-- Idempotent: ADD COLUMN caught by errno 1060 if column already exists.

ALTER TABLE snap_reactions
    ADD COLUMN guest_hash VARCHAR(64) DEFAULT NULL AFTER user_id;

ALTER TABLE snap_reactions
    ADD INDEX idx_reactions_guest (post_id, guest_hash);
