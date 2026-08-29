-- SNAPSMACK_EOF_HEADER
--     -- ===== SNAPSMACK EOF =====
-- Last non-empty line of this file MUST match the line above.
-- Missing or different = truncated/corrupted. Restore before saving.


-- ============================================================
-- SNAPSMACK FORUM — v4 Migration  (BOARD LORD backend)
-- Adds: install role ladder, thread author-flags, thread follows.
-- Run this ONCE on existing installs that already have the v3 schema.
-- Fresh installs: use forum-schema.sql instead (already includes this).
-- The Smack Central self-updater applies forum-schema.sql idempotently, so
-- installs updated through Smack Central get this automatically.
-- Requires MySQL 8.0+ (ADD COLUMN IF NOT EXISTS).
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Role ladder on installs.
-- 0 = ordinary, 1 = power, 2 = moderator, 3 = admin.
-- "God" is NOT a role — it is Smack Central wielding FORUM_MOD_KEY (out-of-band).
-- is_moderator is kept as a synced mirror of (role >= 2) for back-compat.
-- ------------------------------------------------------------
ALTER TABLE ss_forum_installs
    ADD COLUMN IF NOT EXISTS role TINYINT NOT NULL DEFAULT 0 AFTER is_moderator;

-- Backfill: existing moderators become role 2.
UPDATE ss_forum_installs SET role = 2 WHERE is_moderator = 1 AND role < 2;
-- Keep the mirror consistent the other way too (role>=2 implies moderator flag).
UPDATE ss_forum_installs SET is_moderator = 1 WHERE role >= 2 AND is_moderator = 0;

-- ------------------------------------------------------------
-- Author post-flags on threads.
-- Set by the thread author at post time; filterable (BOARD LORD triage).
-- Distinct from mod-managed tags.
-- ------------------------------------------------------------
ALTER TABLE ss_forum_threads
    ADD COLUMN IF NOT EXISTS flag ENUM('none','chat','support','question','brag')
        NOT NULL DEFAULT 'none' AFTER tag_cache;

ALTER TABLE ss_forum_threads ADD KEY IF NOT EXISTS idx_flag (flag);

-- ------------------------------------------------------------
-- Thread follows.
-- A blog can follow any thread to receive reply notifications even if it
-- never posted in it. PK prevents dupes; idx_thread powers follower fan-out.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_follows (
    install_id      INT            NOT NULL,
    thread_id       INT            NOT NULL,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (install_id, thread_id),
    KEY idx_thread (thread_id),
    CONSTRAINT fk_follow_install FOREIGN KEY (install_id) REFERENCES ss_forum_installs (id),
    CONSTRAINT fk_follow_thread  FOREIGN KEY (thread_id)  REFERENCES ss_forum_threads  (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== SNAPSMACK EOF =====
