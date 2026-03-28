-- ============================================================
-- SnapSmack Migration: 0.7.7 — Traffic Stats ("Blabbermouth")
-- ============================================================
-- Compatible with MySQL 5.7+ and MariaDB 10.3+.
-- All statements are idempotent — safe to re-run.
-- ============================================================


-- ------------------------------------------------------------
-- 1. snap_stats — Raw page-view log
-- One row per hit. Stores hashed IP for unique-visitor counting
-- without retaining PII. Country resolved via lightweight
-- GeoIP at log time.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `snap_stats` (
  `id`            bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `hit_at`        datetime        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `page_type`     varchar(30)     COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `page_slug`     varchar(600)    COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_id`      int UNSIGNED    DEFAULT NULL,
  `referrer`      varchar(1000)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referrer_host` varchar(255)    COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent`    varchar(500)    COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `browser`       varchar(60)     COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `os`            varchar(60)     COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country`       char(2)         COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_hash`       char(64)        COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_bot`        tinyint(1)      NOT NULL DEFAULT '0',
  `search_term`   varchar(255)    COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stats_hit_at`        (`hit_at`),
  KEY `idx_stats_page_type`     (`page_type`),
  KEY `idx_stats_image_id`      (`image_id`),
  KEY `idx_stats_referrer_host` (`referrer_host`),
  KEY `idx_stats_country`       (`country`),
  KEY `idx_stats_ip_hash_day`   (`ip_hash`, `hit_at`),
  KEY `idx_stats_is_bot`        (`is_bot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 2. snap_stats_daily — Rolled-up daily aggregates
-- Populated by the stats logger once per day for fast dashboard
-- queries. Keeps the raw table from being hammered by reports.
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `snap_stats_daily` (
  `id`              int UNSIGNED NOT NULL AUTO_INCREMENT,
  `stat_date`       date         NOT NULL,
  `total_views`     int UNSIGNED NOT NULL DEFAULT '0',
  `unique_visitors` int UNSIGNED NOT NULL DEFAULT '0',
  `bot_views`       int UNSIGNED NOT NULL DEFAULT '0',
  `top_image_id`    int UNSIGNED DEFAULT NULL,
  `top_referrer`    varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stats_daily_date` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- 3. SEED STATS SETTINGS
-- ------------------------------------------------------------

INSERT IGNORE INTO `snap_settings` (`setting_key`, `setting_val`)
VALUES
  ('stats_enabled',        '1'),
  ('stats_retention_days', '365'),
  ('stats_exclude_admin',  '1');


-- ------------------------------------------------------------
-- 4. RECORD MIGRATION
-- ------------------------------------------------------------

INSERT IGNORE INTO `snap_migrations` (`migration`, `applied_at`)
VALUES ('migrate-077.sql', NOW());
