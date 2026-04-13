-- SnapSmack — Schema Differential to Alpha 0.7.9g
-- Apply to any site running a schema older than 0.7.9g.
-- Safe to run multiple times: ALTER TABLE will error on duplicate column
-- (errno 1060 / ER_DUP_FIELDNAME) which can be ignored.
--
-- Covers:
--   Migration 027 — snap_multisite_nodes and snap_multisite_queue tables
--   Migration 028 — snap_pages image display columns (0.7.8 / 0.7.9b)
--   Users recovery — snap_users recovery + force_password_change columns
--
-- Note on smack-backfill.php "Unknown column 'snap_id'" error:
--   This was a stale PHP file on the server, not a missing DB column.
--   snap_id is only used as a SELECT alias (id AS snap_id) in the current
--   code. Fix: deploy the current smack-backfill.php — no SQL required.
-- ---------------------------------------------------------------------------


-- ── snap_multisite_nodes and snap_multisite_queue ──────────────────────────
-- Migration 027. Required for the Multisite Management page.
-- CREATE TABLE IF NOT EXISTS is safe to run on sites that already have them.

CREATE TABLE IF NOT EXISTS `snap_multisite_nodes` (
  `id`                  int unsigned   NOT NULL AUTO_INCREMENT,
  `role`                enum('hub','spoke') COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_url`            varchar(500)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_name`           varchar(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_key_local`       varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_key_remote`      varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `software_version`    varchar(50)    COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_seen_at`        datetime       DEFAULT NULL,
  `post_count`          int unsigned   DEFAULT 0,
  `image_count`         int unsigned   DEFAULT 0,
  `pending_comments`    int unsigned   DEFAULT 0,
  `last_backup_at`      datetime       DEFAULT NULL,
  `last_backup_size`    bigint unsigned DEFAULT NULL,
  `last_backup_dest`    varchar(100)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_backup_status`  enum('ok','failed','unknown') COLLATE utf8mb4_unicode_ci DEFAULT 'unknown',
  `disk_usage_bytes`    bigint unsigned DEFAULT NULL,
  `status`              enum('active','offline','disconnected') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `connected_at`        datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_url` (`site_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_multisite_queue` (
  `id`                  int unsigned   NOT NULL AUTO_INCREMENT,
  `node_id`             int unsigned   NOT NULL,
  `action`              varchar(50)    COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload`             text           COLLATE utf8mb4_unicode_ci,
  `status`              enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `attempts`            tinyint unsigned DEFAULT 0,
  `created_at`          datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at`        datetime       DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_node_status` (`node_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


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
