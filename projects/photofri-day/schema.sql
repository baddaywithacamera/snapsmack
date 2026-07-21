-- SNAPSMACK_EOF_HEADER: this file MUST end with the canonical .sql EOF marker.
-- PHOTOFRI.DAY — database schema (own DB `photofri_day`, own Proxmox CT).
-- Whole-fediverse Photo Friday. No image storage: ids + text + a referenced
-- origin preview URL only. Reuses the SMACKVERSE relay's DB shape.

CREATE TABLE IF NOT EXISTS `pfd_settings` (
  `k` varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `v` mediumtext   COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Participants: anyone (whole-fediverse) who FOLLOWED @participate = joined.
-- Following IS the opt-in; unfollowing IS leaving. No pending/allowlist gate.
-- This follower list is the population for the Starter Kit + SnapSmack global
-- discovery feed (v0.3) — a leave (Undo Follow) removes them everywhere.
--   state: active — joined; blocked — moderated off (inbound dropped, delisted).
CREATE TABLE IF NOT EXISTS `pfd_participants` (
  `id`               bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_url`        varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `domain`           varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `handle`           varchar(320) COLLATE utf8mb4_unicode_ci DEFAULT NULL, -- @user@instance (display/Starter Kit)
  `inbox_url`        varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shared_inbox_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `follow_id`        varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL, -- their Follow activity id (for Accept)
  `followback_id`    varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL, -- our Follow-back activity id (for Undo)
  `followback_ok`    tinyint(1)   NOT NULL DEFAULT 0,                      -- their server Accepted our follow-back
  `state`            enum('active','blocked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `joined_at`        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_part_actor` (`actor_url`(191)),
  KEY `idx_part_state` (`state`),
  KEY `idx_part_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blocklist: domains/actors refused. Whole-fediverse scope = a real moderation
-- surface (spec §9). Defederation-safety is the moat.
CREATE TABLE IF NOT EXISTS `pfd_blocklist` (
  `domain`     varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason`     varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `blocked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbound delivery queue (Accepts, follow-backs, weekly prompt, Announces).
CREATE TABLE IF NOT EXISTS `pfd_deliveries` (
  `id`            bigint unsigned NOT NULL AUTO_INCREMENT,
  `inbox_url`     varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activity_json` mediumtext   COLLATE utf8mb4_unicode_ci NOT NULL,
  `status`        enum('queued','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `attempts`      int NOT NULL DEFAULT 0,
  `last_error`    varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `next_try_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_del_due` (`status`, `next_try_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bounded diagnostic ring — one row per inbound POST (verb, actor, outcome).
CREATE TABLE IF NOT EXISTS `pfd_inbox_log` (
  `id`          bigint unsigned NOT NULL AUTO_INCREMENT,
  `received_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verb`        varchar(40)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actor_url`   varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `object_ref`  varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `outcome`     varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_log_time` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ===== SNAPSMACK EOF =====
