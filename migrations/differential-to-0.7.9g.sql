-- SnapSmack — Schema Differential to Alpha 0.7.9g
-- Apply to any site running a schema older than 0.7.9g.
-- Safe to run multiple times: ALTER TABLE will error on duplicate column
-- (errno 1060 / ER_DUP_FIELDNAME) which can be ignored.
--
-- Covers:
--   Migration 028 — snap_pages image display columns (0.7.8 / 0.7.9b)
--   Users recovery — snap_users recovery + force_password_change columns
--
-- Note on smack-backfill.php "Unknown column 'snap_id'" error:
--   This was a stale PHP file on the server, not a missing DB column.
--   snap_id is only used as a SELECT alias (id AS snap_id) in the current
--   code. Fix: deploy the current smack-backfill.php — no SQL required.
-- ---------------------------------------------------------------------------


-- ── snap_pages: image display columns ──────────────────────────────────────
-- Introduced in 0.7.8, migration 028 added in 0.7.9b.
-- Allows per-page image size (full/medium/small), alignment, and drop shadow.

ALTER TABLE `snap_pages`
    ADD COLUMN `image_size`   varchar(20) NOT NULL DEFAULT 'full'   AFTER `image_asset`;

ALTER TABLE `snap_pages`
    ADD COLUMN `image_align`  varchar(20) NOT NULL DEFAULT 'center' AFTER `image_size`;

ALTER TABLE `snap_pages`
    ADD COLUMN `image_shadow` tinyint(1)  NOT NULL DEFAULT 0        AFTER `image_align`;


-- ── snap_users: recovery and forced password change columns ────────────────
-- Required by the password recovery and 2FA system.
-- recovery_code_hash: stores the hashed one-time recovery code.
-- force_password_change: 1 = user must change password on next login.

ALTER TABLE `snap_users`
    ADD COLUMN `recovery_code_hash`   varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL;

ALTER TABLE `snap_users`
    ADD COLUMN `force_password_change` tinyint(1) NOT NULL DEFAULT 0;
