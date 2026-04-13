-- ============================================================
-- SNAPSMACK FORUM — Database Schema
-- Deploy to: squir871_smackforum on snapsmack.ca
-- Run once. Safe to re-run (IF NOT EXISTS throughout).
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
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_threads (
    id              INT            NOT NULL AUTO_INCREMENT,
    category_id     INT            NOT NULL,
    install_id      INT            NOT NULL,
    display_name    VARCHAR(100)   NOT NULL DEFAULT '',
    title           VARCHAR(200)   NOT NULL,
    body            TEXT           NOT NULL,
    is_pinned       TINYINT(1)     NOT NULL DEFAULT 0,
    is_locked       TINYINT(1)     NOT NULL DEFAULT 0,
    is_deleted      TINYINT(1)     NOT NULL DEFAULT 0,
    reply_count     INT            NOT NULL DEFAULT 0,
    last_reply_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_category_last_reply (category_id, last_reply_at),
    KEY idx_install             (install_id),
    CONSTRAINT fk_thread_category FOREIGN KEY (category_id) REFERENCES ss_forum_categories (id),
    CONSTRAINT fk_thread_install  FOREIGN KEY (install_id)  REFERENCES ss_forum_installs  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Replies
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_replies (
    id              INT            NOT NULL AUTO_INCREMENT,
    thread_id       INT            NOT NULL,
    install_id      INT            NOT NULL,
    display_name    VARCHAR(100)   NOT NULL DEFAULT '',
    body            TEXT           NOT NULL,
    is_deleted      TINYINT(1)     NOT NULL DEFAULT 0,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_thread  (thread_id),
    KEY idx_install (install_id),
    CONSTRAINT fk_reply_thread  FOREIGN KEY (thread_id)  REFERENCES ss_forum_threads  (id),
    CONSTRAINT fk_reply_install FOREIGN KEY (install_id) REFERENCES ss_forum_installs (id)
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
