-- SnapSmack: Comment Identity Migration
-- Allows guest (unauthenticated) comments when the blog owner enables them.
-- Makes user_id nullable and adds guest_name / guest_email columns.
--
-- Safe to re-run: the PHP migration runner catches MySQL error 1060
-- (ER_DUP_FIELDNAME) and treats it as a no-op, so the ADD COLUMN statements
-- work on MySQL 5.6+ without IF NOT EXISTS syntax.
--
-- Fresh installs that never had snap_community_comments (pre-community builds):
-- the CREATE TABLE IF NOT EXISTS below sets up the table with the final schema
-- so the subsequent ALTER statements are effectively no-ops (1060 skipped).

CREATE TABLE IF NOT EXISTS `snap_community_comments` (
    `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `post_id`      INT UNSIGNED     NOT NULL,
    `user_id`      INT UNSIGNED     NULL DEFAULT NULL,
    `guest_name`   VARCHAR(100)     NULL DEFAULT NULL,
    `guest_email`  VARCHAR(200)     NULL DEFAULT NULL,
    `comment_text` TEXT             NOT NULL,
    `status`       ENUM('visible','hidden','deleted') NOT NULL DEFAULT 'visible',
    `ip`           VARCHAR(45)      NULL DEFAULT NULL,
    `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_post_status` (`post_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Make user_id nullable so rows can exist without a community account.
-- No-op on fresh installs (table already has nullable user_id above).
ALTER TABLE snap_community_comments
    MODIFY COLUMN user_id INT UNSIGNED NULL DEFAULT NULL;

-- Add guest name column (required for guest comments, NULL for account comments).
-- No-op on fresh installs (1060 skipped by migration runner).
ALTER TABLE snap_community_comments
    ADD COLUMN guest_name VARCHAR(100) NULL DEFAULT NULL AFTER user_id;

-- Add guest email column (optional; never displayed publicly).
-- No-op on fresh installs (1060 skipped by migration runner).
ALTER TABLE snap_community_comments
    ADD COLUMN guest_email VARCHAR(200) NULL DEFAULT NULL AFTER guest_name;
