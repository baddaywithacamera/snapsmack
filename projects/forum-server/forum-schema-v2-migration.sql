-- ============================================================
-- SNAPSMACK FORUM — v2 Migration
-- Run this ONCE on existing installs that already have the v1 schema.
-- Fresh installs: use forum-schema.sql instead (already includes all of this).
-- Requires MySQL 8.0+ (ADD COLUMN IF NOT EXISTS).
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Extend ss_forum_threads
-- ------------------------------------------------------------
ALTER TABLE ss_forum_threads
    ADD COLUMN IF NOT EXISTS excerpt                 VARCHAR(400)   NOT NULL DEFAULT ''   AFTER body,
    ADD COLUMN IF NOT EXISTS is_solved               TINYINT(1)     NOT NULL DEFAULT 0    AFTER is_deleted,
    ADD COLUMN IF NOT EXISTS solved_reply_id         INT                     DEFAULT NULL AFTER is_solved,
    ADD COLUMN IF NOT EXISTS view_count              INT            NOT NULL DEFAULT 0    AFTER reply_count,
    ADD COLUMN IF NOT EXISTS reaction_count          INT            NOT NULL DEFAULT 0    AFTER view_count,
    ADD COLUMN IF NOT EXISTS last_reply_display_name VARCHAR(100)   NOT NULL DEFAULT ''   AFTER last_reply_at,
    ADD COLUMN IF NOT EXISTS last_reply_domain       VARCHAR(255)   NOT NULL DEFAULT ''   AFTER last_reply_display_name,
    ADD COLUMN IF NOT EXISTS tag_cache               VARCHAR(500)   NOT NULL DEFAULT ''   AFTER last_reply_domain,
    ADD COLUMN IF NOT EXISTS is_edited               TINYINT(1)     NOT NULL DEFAULT 0    AFTER created_at,
    ADD COLUMN IF NOT EXISTS edited_at               TIMESTAMP               DEFAULT NULL AFTER is_edited;

-- Add FULLTEXT index if not already present
ALTER TABLE ss_forum_threads ADD FULLTEXT KEY IF NOT EXISTS ft_thread_search (title, body);

-- ------------------------------------------------------------
-- Extend ss_forum_replies
-- ------------------------------------------------------------
ALTER TABLE ss_forum_replies
    ADD COLUMN IF NOT EXISTS is_edited      TINYINT(1)     NOT NULL DEFAULT 0    AFTER is_deleted,
    ADD COLUMN IF NOT EXISTS edited_at      TIMESTAMP               DEFAULT NULL AFTER is_edited,
    ADD COLUMN IF NOT EXISTS reaction_count INT            NOT NULL DEFAULT 0    AFTER edited_at;

ALTER TABLE ss_forum_replies ADD FULLTEXT KEY IF NOT EXISTS ft_reply_search (body);

-- ------------------------------------------------------------
-- New tables (all safe to run on fresh install too)
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

CREATE TABLE IF NOT EXISTS ss_forum_tags (
    id              INT            NOT NULL AUTO_INCREMENT,
    slug            VARCHAR(50)    NOT NULL,
    name            VARCHAR(80)    NOT NULL,
    thread_count    INT            NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ss_forum_thread_tags (
    thread_id       INT            NOT NULL,
    tag_id          INT            NOT NULL,
    PRIMARY KEY (thread_id, tag_id),
    CONSTRAINT fk_tt_thread FOREIGN KEY (thread_id) REFERENCES ss_forum_threads (id),
    CONSTRAINT fk_tt_tag    FOREIGN KEY (tag_id)    REFERENCES ss_forum_tags    (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ss_forum_read_state (
    install_id      INT            NOT NULL,
    thread_id       INT            NOT NULL,
    read_reply_count INT           NOT NULL DEFAULT 0,
    last_read_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (install_id, thread_id),
    CONSTRAINT fk_rs_install FOREIGN KEY (install_id) REFERENCES ss_forum_installs (id),
    CONSTRAINT fk_rs_thread  FOREIGN KEY (thread_id)  REFERENCES ss_forum_threads  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ss_forum_rate_limit (
    id              INT            NOT NULL AUTO_INCREMENT,
    install_id      INT            NOT NULL,
    action          VARCHAR(20)    NOT NULL,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rate (install_id, action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
