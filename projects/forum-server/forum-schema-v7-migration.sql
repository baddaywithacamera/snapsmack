-- SNAPSMACK_EOF_HEADER
-- Last non-empty line must be: -- ===== SNAPSMACK EOF =====
--
-- Forum schema v7 — per-person, revocable moderation keys (SS-1510).
-- Safe to run more than once. Run before deploying the matching API files.

SET NAMES utf8mb4;

-- Per-person keys have no install row. Their edits are attributed in the
-- append-only moderation log, while edit history continues to hold the prior
-- body with a null install_id.
ALTER TABLE ss_forum_edit_history MODIFY COLUMN install_id INT NULL;

CREATE TABLE IF NOT EXISTS ss_forum_moderator_keys (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    key_id        CHAR(16)      NOT NULL,
    label         VARCHAR(100)  NOT NULL,
    secret_hash   CHAR(64)      NOT NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_ip    VARCHAR(45)   NOT NULL DEFAULT '',
    revoked_at    TIMESTAMP     NULL DEFAULT NULL,
    revoked_ip    VARCHAR(45)   NOT NULL DEFAULT '',
    last_used_at  TIMESTAMP     NULL DEFAULT NULL,
    last_used_ip  VARCHAR(45)   NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_moderator_key_id (key_id),
    KEY idx_moderator_key_active (revoked_at),
    KEY idx_moderator_key_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== SNAPSMACK EOF =====
