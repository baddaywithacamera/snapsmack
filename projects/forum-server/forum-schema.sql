-- SNAPSMACK_EOF_HEADER
--     -- ===== SNAPSMACK EOF =====
-- Last non-empty line of this file MUST match the line above.
-- Missing or different = truncated/corrupted. Restore before saving.


-- ============================================================
-- SNAPSMACK FORUM — Database Schema (v2)
-- Deploy to: squir871_smackforum on snapsmack.ca
-- Run once on fresh installs. Existing installs: run forum-schema-v2-migration.sql first.
-- Safe to re-run (IF NOT EXISTS throughout).
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ------------------------------------------------------------
-- Registered SnapSmack installs
-- One row per install. The api_key is the sole credential.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_installs (
    id              INT            NOT NULL AUTO_INCREMENT,
    api_key         VARCHAR(64)    NOT NULL,
    domain          VARCHAR(255)   NOT NULL,
    display_name    VARCHAR(100)   NOT NULL DEFAULT '',
    ss_version      VARCHAR(20)    NOT NULL DEFAULT '',
    registered_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_banned       TINYINT(1)     NOT NULL DEFAULT 0,
    is_moderator    TINYINT(1)     NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_api_key (api_key),
    UNIQUE KEY uq_domain  (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Forum boards / categories
-- Managed by Sean. Seeded below.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_categories (
    id              INT            NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(50)    NOT NULL,
    name            VARCHAR(100)   NOT NULL,
    description     TEXT,
    sort_order      INT            NOT NULL DEFAULT 0,
    is_active       TINYINT(1)     NOT NULL DEFAULT 1,
    thread_count    INT            NOT NULL DEFAULT 0,
    reply_count     INT            NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Threads (original posts)
-- New in v2: view_count, is_solved, solved_reply_id,
--            last_reply_display_name, last_reply_domain,
--            excerpt, reaction_count, tag_cache
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_threads (
    id                      INT            NOT NULL AUTO_INCREMENT,
    category_id             INT            NOT NULL,
    install_id              INT            NOT NULL,
    display_name            VARCHAR(100)   NOT NULL DEFAULT '',
    title                   VARCHAR(200)   NOT NULL,
    body                    TEXT           NOT NULL,
    excerpt                 VARCHAR(400)   NOT NULL DEFAULT '',
    is_pinned               TINYINT(1)     NOT NULL DEFAULT 0,
    is_locked               TINYINT(1)     NOT NULL DEFAULT 0,
    is_deleted              TINYINT(1)     NOT NULL DEFAULT 0,
    is_solved               TINYINT(1)     NOT NULL DEFAULT 0,
    solved_reply_id         INT                     DEFAULT NULL,
    view_count              INT            NOT NULL DEFAULT 0,
    reply_count             INT            NOT NULL DEFAULT 0,
    reaction_count          INT            NOT NULL DEFAULT 0,
    last_reply_at           TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_reply_display_name VARCHAR(100)   NOT NULL DEFAULT '',
    last_reply_domain       VARCHAR(255)   NOT NULL DEFAULT '',
    tag_cache               VARCHAR(500)   NOT NULL DEFAULT '',
    created_at              TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_edited               TINYINT(1)     NOT NULL DEFAULT 0,
    edited_at               TIMESTAMP               DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_category_last_reply (category_id, last_reply_at),
    KEY idx_install             (install_id),
    FULLTEXT KEY ft_thread_search (title, body),
    CONSTRAINT fk_thread_category FOREIGN KEY (category_id) REFERENCES ss_forum_categories (id),
    CONSTRAINT fk_thread_install  FOREIGN KEY (install_id)  REFERENCES ss_forum_installs  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Replies
-- New in v2: is_edited, edited_at, reaction_count
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_replies (
    id              INT            NOT NULL AUTO_INCREMENT,
    thread_id       INT            NOT NULL,
    install_id      INT            NOT NULL,
    display_name    VARCHAR(100)   NOT NULL DEFAULT '',
    body            TEXT           NOT NULL,
    is_deleted      TINYINT(1)     NOT NULL DEFAULT 0,
    is_edited       TINYINT(1)     NOT NULL DEFAULT 0,
    edited_at       TIMESTAMP               DEFAULT NULL,
    reaction_count  INT            NOT NULL DEFAULT 0,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_thread  (thread_id),
    KEY idx_install (install_id),
    FULLTEXT KEY ft_reply_search (body),
    CONSTRAINT fk_reply_thread  FOREIGN KEY (thread_id)  REFERENCES ss_forum_threads  (id),
    CONSTRAINT fk_reply_install FOREIGN KEY (install_id) REFERENCES ss_forum_installs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Reactions
-- One emoji per install per target. Toggle: same emoji removes.
-- Different emoji replaces. target_type: 'thread' | 'reply'
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_reactions (
    id              INT            NOT NULL AUTO_INCREMENT,
    target_type     ENUM('thread','reply') NOT NULL,
    target_id       INT            NOT NULL,
    install_id      INT            NOT NULL,
    emoji           VARCHAR(12)    NOT NULL,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reaction (target_type, target_id, install_id),
    KEY idx_target (target_type, target_id),
    CONSTRAINT fk_reaction_install FOREIGN KEY (install_id) REFERENCES ss_forum_installs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Edit history
-- Body snapshot taken before each edit. target_type: 'thread' | 'reply'
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_edit_history (
    id              INT            NOT NULL AUTO_INCREMENT,
    target_type     ENUM('thread','reply') NOT NULL,
    target_id       INT            NOT NULL,
    install_id      INT            NOT NULL,
    body_before     TEXT           NOT NULL,
    edited_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tags
-- Mod-managed. thread_count is denormalised for speed.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_tags (
    id              INT            NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(50)    NOT NULL,
    name            VARCHAR(80)    NOT NULL,
    thread_count    INT            NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Thread ↔ Tag pivot
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_thread_tags (
    thread_id       INT            NOT NULL,
    tag_id          INT            NOT NULL,
    PRIMARY KEY (thread_id, tag_id),
    CONSTRAINT fk_tt_thread FOREIGN KEY (thread_id) REFERENCES ss_forum_threads (id),
    CONSTRAINT fk_tt_tag    FOREIGN KEY (tag_id)    REFERENCES ss_forum_tags    (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Read state
-- Per-install, per-thread. Tracks reply_count at last visit
-- so client can flag threads with new replies since last read.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_read_state (
    install_id      INT            NOT NULL,
    thread_id       INT            NOT NULL,
    read_reply_count INT           NOT NULL DEFAULT 0,
    last_read_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (install_id, thread_id),
    CONSTRAINT fk_rs_install FOREIGN KEY (install_id) REFERENCES ss_forum_installs (id),
    CONSTRAINT fk_rs_thread  FOREIGN KEY (thread_id)  REFERENCES ss_forum_threads  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Rate limiting
-- Sliding window. Pruned on access. action: 'thread' | 'reply' | 'react'
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_rate_limit (
    id              INT            NOT NULL AUTO_INCREMENT,
    install_id      INT            NOT NULL,
    action          VARCHAR(20)    NOT NULL,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rate (install_id, action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Notifications
-- Created when someone replies to a thread you authored or
-- participated in. Polled by installs — no push.
-- type: 'reply_to_thread' | 'reply_to_watched'
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_notifications (
    id              INT            NOT NULL AUTO_INCREMENT,
    install_id      INT            NOT NULL,
    type            VARCHAR(30)    NOT NULL,
    thread_id       INT            NOT NULL,
    reply_id        INT            NOT NULL,
    actor_name      VARCHAR(100)   NOT NULL DEFAULT '',
    actor_domain    VARCHAR(255)   NOT NULL DEFAULT '',
    thread_title    VARCHAR(200)   NOT NULL DEFAULT '',
    is_read         TINYINT(1)     NOT NULL DEFAULT 0,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_install_unread (install_id, is_read, created_at),
    CONSTRAINT fk_notif_install FOREIGN KEY (install_id) REFERENCES ss_forum_installs (id),
    CONSTRAINT fk_notif_thread  FOREIGN KEY (thread_id)  REFERENCES ss_forum_threads  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Seed: initial five boards
-- INSERT IGNORE so re-running is safe.
-- ------------------------------------------------------------
INSERT IGNORE INTO ss_forum_categories (slug, name, description, sort_order) VALUES
    ('getting-started',  'Getting Started',       'New to SnapSmack? Questions, setup help, first steps.',                         1),
    ('skins',            'Skins & Customisation', 'Skin questions, CSS tweaks, manifest help, building new skins.',                 2),
    ('bug-reports',      'Bug Reports',           'Something broken? Post here. Include your SnapSmack version and active skin.',   3),
    ('feature-requests', 'Feature Requests',      'Ideas for what comes next. Vote with replies.',                                  4),
    ('show-and-tell',    'Show & Tell',           'Share your site. Show what you\'ve built with SnapSmack.',                      5);
-- ===== SNAPSMACK EOF =====
