-- Migration: 0.7.4b — Community engagement enhancements
--
-- 1. Add edited_at column to snap_community_comments (future "edited" indicator).
-- 2. Add guest_hash column + index to snap_likes (anonymous likes via IP hash).
-- 3. Add guest_hash column + index to snap_reactions (anonymous reactions via IP hash).
--
-- Idempotent: uses IF NOT EXISTS checks so this is safe to re-run.

-- --- Comments: edited timestamp ---
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'snap_community_comments' AND COLUMN_NAME = 'edited_at');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE snap_community_comments ADD COLUMN edited_at DATETIME DEFAULT NULL AFTER created_at',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --- Likes: anonymous guest support ---
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'snap_likes' AND COLUMN_NAME = 'guest_hash');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE snap_likes ADD COLUMN guest_hash VARCHAR(64) DEFAULT NULL AFTER user_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'snap_likes' AND INDEX_NAME = 'idx_likes_guest');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE snap_likes ADD INDEX idx_likes_guest (post_id, guest_hash)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- --- Reactions: anonymous guest support ---
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'snap_reactions' AND COLUMN_NAME = 'guest_hash');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE snap_reactions ADD COLUMN guest_hash VARCHAR(64) DEFAULT NULL AFTER user_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'snap_reactions' AND INDEX_NAME = 'idx_reactions_guest');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE snap_reactions ADD INDEX idx_reactions_guest (post_id, guest_hash)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
