-- Migration: 0.7.4b — Add edited_at column to snap_community_comments
-- Supports future "edited" indicator on community comments.
-- Idempotent: ALTER TABLE ADD COLUMN will be caught by errno 1060 if
-- the column already exists.

ALTER TABLE snap_community_comments
    ADD COLUMN edited_at DATETIME DEFAULT NULL AFTER created_at;
