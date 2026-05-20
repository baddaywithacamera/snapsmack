-- SMACK CENTRAL FORUM — Canonical Schema
-- Database: squir871_smackforum (isolated from smackcent and enemy)
--
-- This file is the source of truth for the forum DB schema.
-- sc-schema.php diffs the live DB against this file and can apply
-- missing tables and columns. Safe to re-run: all statements use
-- IF NOT EXISTS guards.
--
-- Tables (in dependency order):
--   ss_forum_categories — board definitions (slugs, names, counters)
--   ss_forum_installs   — registered SnapSmack installs (forum API auth)
--   ss_forum_threads    — threads posted by installs or hub admin (PGSB)
--   ss_forum_replies    — replies within threads

CREATE TABLE IF NOT EXISTS `ss_forum_categories` (
    `id`           INT           NOT NULL AUTO_INCREMENT,
    `slug`         VARCHAR(50)   NOT NULL,
    `name`         VARCHAR(100)  NOT NULL,
    `description`  TEXT,
    `sort_order`   INT           NOT NULL DEFAULT 0,
    `is_active`    TINYINT(1)    NOT NULL DEFAULT 1,
    `thread_count` INT           NOT NULL DEFAULT 0,
    `reply_count`  INT           NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ss_forum_installs` (
    `id`           INT           NOT NULL AUTO_INCREMENT,
    `api_key`      VARCHAR(64)   NOT NULL,
    `domain`       VARCHAR(255)  NOT NULL,
    `display_name` VARCHAR(100)  NOT NULL DEFAULT '',
    `ss_version`   VARCHAR(20)   NOT NULL DEFAULT '',
    `registered_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `is_banned`    TINYINT(1)    NOT NULL DEFAULT 0,
    `is_moderator` TINYINT(1)    NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_api_key` (`api_key`),
    UNIQUE KEY `uq_domain`  (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ss_forum_threads` (
    `id`           INT           NOT NULL AUTO_INCREMENT,
    `category_id`  INT           NOT NULL,
    `install_id`   INT           NOT NULL,
    `display_name` VARCHAR(100)  NOT NULL DEFAULT '',
    `title`        VARCHAR(200)  NOT NULL,
    `body`         TEXT          NOT NULL,
    `is_pinned`    TINYINT(1)    NOT NULL DEFAULT 0,
    `is_locked`    TINYINT(1)    NOT NULL DEFAULT 0,
    `is_deleted`   TINYINT(1)    NOT NULL DEFAULT 0,
    `reply_count`  INT           NOT NULL DEFAULT 0,
    `last_reply_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_category_last_reply` (`category_id`, `last_reply_at`),
    KEY `idx_install`             (`install_id`),
    CONSTRAINT `fk_thread_category` FOREIGN KEY (`category_id`) REFERENCES `ss_forum_categories` (`id`),
    CONSTRAINT `fk_thread_install`  FOREIGN KEY (`install_id`)  REFERENCES `ss_forum_installs`   (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ss_forum_replies` (
    `id`           INT           NOT NULL AUTO_INCREMENT,
    `thread_id`    INT           NOT NULL,
    `install_id`   INT           NOT NULL,
    `display_name` VARCHAR(100)  NOT NULL DEFAULT '',
    `body`         TEXT          NOT NULL,
    `is_deleted`   TINYINT(1)    NOT NULL DEFAULT 0,
    `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_thread`  (`thread_id`),
    KEY `idx_install` (`install_id`),
    CONSTRAINT `fk_reply_thread`  FOREIGN KEY (`thread_id`)  REFERENCES `ss_forum_threads`  (`id`),
    CONSTRAINT `fk_reply_install` FOREIGN KEY (`install_id`) REFERENCES `ss_forum_installs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
