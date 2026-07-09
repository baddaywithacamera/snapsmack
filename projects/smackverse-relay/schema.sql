-- SNAPSMACK_EOF_HEADER: this file MUST end with the canonical .sql EOF marker.
-- SMACKVERSE Relay — database schema (own DB `smackverse_relay`, own Proxmox CT).
-- No image storage: the relay handles Notes/activities (ids + text) only.

CREATE TABLE IF NOT EXISTS `relay_settings` (
  `k` varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `v` mediumtext   COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Installs that have subscribed to the relay. state:
--   pending — followed us, awaiting allowlist approval (open mode auto-actives)
--   active  — approved; receives + contributes fan-out
--   blocked — moderated off; inbound dropped, excluded from fan-out
CREATE TABLE IF NOT EXISTS `relay_subscribers` (
  `id`               bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_url`        varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `domain`           varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `inbox_url`        varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shared_inbox_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `follow_id`        varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state`            enum('pending','active','blocked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `subscribed_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sub_actor` (`actor_url`(191)),
  KEY `idx_sub_state` (`state`),
  KEY `idx_sub_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Allowlist: domains auto-admitted to 'active' on subscribe (your own fleet).
-- Ignored when relay_settings.open_mode = 'open' (then everyone auto-actives).
CREATE TABLE IF NOT EXISTS `relay_allowlist` (
  `domain`   varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note`     varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `added_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blocklist: domains refused subscribe, dropped on inbound, excluded from fan-out.
CREATE TABLE IF NOT EXISTS `relay_blocklist` (
  `domain`     varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason`     varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `blocked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbound delivery queue (fan-out + Accepts). Mirrors snap_ap_deliveries.
CREATE TABLE IF NOT EXISTS `relay_deliveries` (
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
CREATE TABLE IF NOT EXISTS `relay_inbox_log` (
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
