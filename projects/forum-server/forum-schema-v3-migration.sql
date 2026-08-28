-- SNAPSMACK_EOF_HEADER
--     -- ===== SNAPSMACK EOF =====
-- Last non-empty line of this file MUST match the line above.
-- Missing or different = truncated/corrupted. Restore before saving.


-- ============================================================
-- SNAPSMACK FORUM — v3 Migration
-- Adds image-attachment support (ss_forum_attachments) to threads & replies.
-- Run this ONCE on existing installs that already have the v2 schema.
-- Fresh installs: use forum-schema.sql instead (already includes this).
-- The Smack Central self-updater applies forum-schema.sql idempotently, so
-- installs updated through Smack Central get this automatically.
-- Requires MySQL 8.0+.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Attachments: images posted on a thread or reply.
-- Polymorphic target (thread|reply), matching reactions/edit_history.
-- Files live on disk under api/forum/uploads/; this row is the record.
-- is_deleted = reversible hide (never hard-delete — GL-6).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_attachments (
    id              INT            NOT NULL AUTO_INCREMENT,
    target_type     ENUM('thread','reply') NOT NULL,
    target_id       INT            NOT NULL,
    install_id      INT            NOT NULL,
    stored_name     VARCHAR(96)    NOT NULL,
    orig_name       VARCHAR(255)   NOT NULL DEFAULT '',
    mime            VARCHAR(40)    NOT NULL,
    byte_size       INT            NOT NULL DEFAULT 0,
    width           INT            NOT NULL DEFAULT 0,
    height          INT            NOT NULL DEFAULT 0,
    is_deleted      TINYINT(1)     NOT NULL DEFAULT 0,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stored (stored_name),
    KEY idx_target (target_type, target_id),
    KEY idx_install (install_id),
    CONSTRAINT fk_attach_install FOREIGN KEY (install_id) REFERENCES ss_forum_installs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== SNAPSMACK EOF =====
