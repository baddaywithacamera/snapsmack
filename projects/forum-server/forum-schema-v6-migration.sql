-- SNAPSMACK_EOF_HEADER
--     -- ===== SNAPSMACK EOF =====
-- Last non-empty line of this file MUST match the line above.
-- Missing or different = truncated/corrupted. Restore before saving.


-- ============================================================
-- SNAPSMACK FORUM — v6 Migration  (the moderation log)
-- Adds: ss_forum_mod_log.
-- Run this ONCE on existing installs that already have the v5 schema.
-- Fresh installs: use forum-schema.sql instead (already includes this).
-- The Smack Central self-updater applies forum-schema.sql idempotently, so
-- installs updated through Smack Central get this automatically.
--
-- WHY (SS-1510): no moderation action was recorded anywhere. No audit table
-- existed in any forum schema and no logging call existed in the API, so
-- deletions, locks, board changes and role promotions left nothing behind.
-- The God key makes that worse: the API refuses it for posting, so an action
-- taken with it carried no identity at all.
--
-- RUN THIS BEFORE deploying the API change. The code tolerates the table
-- being absent (it logs to error_log and carries on, so moderation never
-- breaks), which means a forgotten migration produces an EMPTY audit trail
-- rather than an error anyone would notice.
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- One row per act of moderator power.
--
-- actor_install_id is NULL when the God key acted, and actor_is_god says so
-- explicitly rather than leaving a reader to infer it from the NULL.
--
-- Nothing in the API updates or deletes from this table. It is append-only
-- by use, not by grant: MySQL cannot express append-only on a table, so the
-- guarantee is that no UPDATE or DELETE against it exists in the code, and
-- the regression test asserts that.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ss_forum_mod_log (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    occurred_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor_install_id  INT             NULL,
    actor_domain      VARCHAR(255)    NOT NULL DEFAULT '',
    actor_is_god      TINYINT(1)      NOT NULL DEFAULT 0,
    -- The sixteen actions the API actually writes, taken from the router
    -- rather than from intention. This list previously named thread.lock and
    -- thread.pin as separate actions -- the code has always written ONE
    -- action for both -- and omitted thread.flag and thread.move entirely.
    -- board.create, board.update, thread.edit, thread.delete, thread.move,
    -- thread.pinlock (pin/lock), thread.flag (the post kind),
    -- reply.edit, reply.delete, tag.add, tag.remove,
    -- attachment.hide, attachment.restore, answer.accept, answer.unaccept,
    -- install.role
    action            VARCHAR(40)     NOT NULL,
    target_type       VARCHAR(20)     NOT NULL DEFAULT '',
    target_id         INT             NULL,
    -- Free text for the one fact that makes the row readable later: the old
    -- and new role, which field of a board changed, that kind of thing.
    detail            VARCHAR(255)    NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    KEY idx_mod_log_occurred (occurred_at),
    KEY idx_mod_log_actor    (actor_install_id),
    KEY idx_mod_log_target   (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== SNAPSMACK EOF =====
