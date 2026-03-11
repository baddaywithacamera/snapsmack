-- ============================================================================
-- SNAPSMACK Migration: Community Infrastructure
-- Target: Alpha v0.8
--
-- Adds the community backbone: visitor accounts, server-side sessions,
-- comments (account-required), likes, and reactions.
--
-- Safe to run multiple times — uses IF NOT EXISTS and INSERT IGNORE.
-- Run AFTER migrate-0.7-0.8.sql.
--
-- NOTE: post_id in community tables currently references snap_images.id.
-- When snap_posts ships (carousel migration), post_id will be remapped
-- to snap_posts.id via a follow-up migration. No structural change needed
-- here — only the FK target changes.
-- ============================================================================


-- ============================================================================
-- 1. COMMUNITY USERS
--    Visitor accounts. Entirely separate from snap_users (admin/editor).
--    google_id reserved for OAuth linking when central auth ships.
--    status: 'active' | 'suspended' | 'unverified'
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_community_users` (
    `id`               int          NOT NULL AUTO_INCREMENT,
    `username`         varchar(50)  NOT NULL,
    `display_name`     varchar(100) DEFAULT NULL,
    `email`            varchar(150) NOT NULL,
    `password_hash`    varchar(255) DEFAULT NULL     COMMENT 'NULL when account is Google-only',
    `google_id`        varchar(100) DEFAULT NULL     COMMENT 'Reserved: Google OAuth linking',
    `avatar_url`       varchar(500) DEFAULT NULL,
    `bio`              text         DEFAULT NULL,
    `created_at`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`     datetime     DEFAULT NULL,
    `email_verified`   tinyint(1)   NOT NULL DEFAULT 0,
    `status`           varchar(20)  NOT NULL DEFAULT 'unverified',
    -- Central auth federation (populated when install registers with snapsmack.ca)
    `central_user_id`  varchar(100) DEFAULT NULL     COMMENT 'snapsmack.ca user ID once federated',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username`  (`username`),
    UNIQUE KEY `uq_email`     (`email`),
    UNIQUE KEY `uq_google_id` (`google_id`),
    KEY `idx_status`          (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 2. COMMUNITY SESSIONS
--    Server-side session tokens for community users. Token stored in cookie,
--    validated here on each request. Avoids relying solely on PHP sessions.
--    Clean up expired rows periodically (cron or on-login sweep).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_community_sessions` (
    `id`           int          NOT NULL AUTO_INCREMENT,
    `user_id`      int          NOT NULL,
    `token`        varchar(128) NOT NULL               COMMENT 'SHA-256 hex of random bytes',
    `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`   datetime     NOT NULL,
    `ip`           varchar(45)  DEFAULT NULL,
    `user_agent`   varchar(500) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token`    (`token`),
    KEY `idx_user_id`        (`user_id`),
    KEY `idx_expires_at`     (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 3. EMAIL VERIFICATION TOKENS
--    One-time tokens sent on signup and password reset.
--    type: 'verify_email' | 'reset_password'
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_community_tokens` (
    `id`           int          NOT NULL AUTO_INCREMENT,
    `user_id`      int          NOT NULL,
    `token`        varchar(128) NOT NULL,
    `type`         varchar(30)  NOT NULL,
    `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`   datetime     NOT NULL,
    `used_at`      datetime     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token` (`token`),
    KEY `idx_user_id`     (`user_id`),
    KEY `idx_type`        (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 4. COMMUNITY COMMENTS
--    Account-required comments. Separate from snap_comments (which handles
--    legacy anonymous comments and keeps existing skins working unchanged).
--
--    post_id: currently references snap_images.id. Will reference
--    snap_posts.id after the carousel migration.
--
--    status: 'visible' | 'hidden' | 'deleted'
--    Blog owner can hide/delete. SNAPSMACK admin can act globally.
--    No nesting in v1 — flat chronological threads only.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_community_comments` (
    `id`           int          NOT NULL AUTO_INCREMENT,
    `post_id`      int          NOT NULL               COMMENT 'snap_images.id (snap_posts.id after carousel migration)',
    `user_id`      int          NOT NULL               COMMENT 'snap_community_users.id',
    `comment_text` text         NOT NULL,
    `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `edited_at`    datetime     DEFAULT NULL,
    `status`       varchar(20)  NOT NULL DEFAULT 'visible',
    `ip`           varchar(45)  DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_post_id`    (`post_id`),
    KEY `idx_user_id`    (`user_id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_status`     (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 5. LIKES
--    One row per user per post. Toggle: insert to like, delete to unlike.
--    Unique constraint enforces one like per account per post.
--    No dislike. No algorithmic use. Private to blog owner by default.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_likes` (
    `id`           int      NOT NULL AUTO_INCREMENT,
    `post_id`      int      NOT NULL               COMMENT 'snap_images.id (snap_posts.id after carousel migration)',
    `user_id`      int      NOT NULL               COMMENT 'snap_community_users.id',
    `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_post_user`  (`post_id`, `user_id`),
    KEY `idx_post_id`          (`post_id`),
    KEY `idx_user_id`          (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 6. REACTIONS
--    Curated set, finalized before UI ships (20-30 codes, photography-
--    appropriate). One reaction type per user per post — changing reaction
--    deletes the old row and inserts new. reaction_code is a short slug
--    e.g. 'fire', 'chef-kiss', 'wow', 'moody', 'sharp', 'golden-hour'.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_reactions` (
    `id`             int         NOT NULL AUTO_INCREMENT,
    `post_id`        int         NOT NULL               COMMENT 'snap_images.id (snap_posts.id after carousel migration)',
    `user_id`        int         NOT NULL               COMMENT 'snap_community_users.id',
    `reaction_code`  varchar(50) NOT NULL,
    `created_at`     datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_post_user`   (`post_id`, `user_id`),
    KEY `idx_post_id`           (`post_id`),
    KEY `idx_reaction_code`     (`reaction_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 7. RATE LIMITING
--    Tracks action counts per IP for server-side rate limiting.
--    No CAPTCHA in v1. Sweep old rows on cron or on-request.
--    action: 'comment' | 'like' | 'signup' | 'login' | 'reset'
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_rate_limits` (
    `id`           int         NOT NULL AUTO_INCREMENT,
    `ip`           varchar(45) NOT NULL,
    `action`       varchar(30) NOT NULL,
    `count`        int         NOT NULL DEFAULT 1,
    `window_start` datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ip_action_window` (`ip`, `action`, `window_start`),
    KEY `idx_window_start`           (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 8. COMMUNITY SETTINGS
--    Global switches and federation placeholders.
--    INSERT IGNORE — never overwrites existing values.
-- ============================================================================

-- Global on/off for the community system
INSERT IGNORE INTO snap_settings (setting_key, setting_val) VALUES
    ('community_enabled',            '1'),
    ('community_comments_enabled',   '1'),
    ('community_likes_enabled',      '1'),
    ('community_reactions_enabled',  '0')    -- off until reaction set is finalised
;

-- Email settings for verification and password reset
INSERT IGNORE INTO snap_settings (setting_key, setting_val) VALUES
    ('community_email_from',         ''),
    ('community_email_from_name',    ''),
    ('community_require_verification','1')   -- email must be verified before commenting/liking
;

-- Federation: populated when this install registers with snapsmack.ca
-- Empty strings = standalone mode (local auth only)
INSERT IGNORE INTO snap_settings (setting_key, setting_val) VALUES
    ('snapsmack_install_id',         ''),
    ('snapsmack_central_token',      ''),
    ('snapsmack_directory_listed',   '0'),
    ('snapsmack_last_ping',          '')
;

-- Rate limiting thresholds (per hour per IP)
INSERT IGNORE INTO snap_settings (setting_key, setting_val) VALUES
    ('rate_limit_comments',          '10'),
    ('rate_limit_likes',             '60'),
    ('rate_limit_signups',           '3'),
    ('rate_limit_logins',            '10'),
    ('rate_limit_resets',            '3')
;

-- Session lifetime in days
INSERT IGNORE INTO snap_settings (setting_key, setting_val) VALUES
    ('community_session_days',       '30')
;


-- ============================================================================
-- 9. VERSION STAMP
-- ============================================================================

UPDATE snap_settings
SET setting_val = '0.8.0-alpha'
WHERE setting_key = 'installed_version';
