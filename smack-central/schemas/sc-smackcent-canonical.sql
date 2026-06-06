-- SMACK CENTRAL — Canonical Schema
-- Database: squir871_smackcent (the main SC admin database)
--
-- This file is the source of truth for the smackcent DB schema.
-- sc-schema.php diffs the live DB against this file and can apply
-- missing tables and columns. Safe to re-run: all statements use
-- IF NOT EXISTS guards.
--
-- Tables:
--   sc_admin_users  — SC admin login accounts
--   sc_settings     — key-value configuration store
--   sc_releases     — packaged stable release history
--   sc_dev_builds   — packaged dev (BITCHIN' track) build history
--   sc_assets       — font/script/CSS asset repository
--   sc_rss_cache    — RSS feed health cache (one row per registered install)

CREATE TABLE IF NOT EXISTS `sc_admin_users` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(50)   NOT NULL,
    `password_hash` VARCHAR(255)  NOT NULL,
    `last_login_at` TIMESTAMP     NULL     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sc_settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_val` TEXT         NOT NULL DEFAULT '',
    `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sc_releases` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `version`         VARCHAR(20)   NOT NULL COMMENT 'Short version string e.g. 0.7.18',
    `version_full`    VARCHAR(50)   NOT NULL COMMENT 'Full display string e.g. Alpha 0.7.18',
    `git_tag`         VARCHAR(100)  NOT NULL COMMENT 'Git tag checked out for this release',
    `checksum_sha256` VARCHAR(64)   NOT NULL,
    `signature`       VARCHAR(128)  NOT NULL COMMENT 'Hex Ed25519 signature of the SHA-256 checksum',
    `download_url`    VARCHAR(500)  NOT NULL,
    `download_size`   INT UNSIGNED  NOT NULL DEFAULT 0  COMMENT 'Zip file size in bytes',
    `schema_changes`  TINYINT(1)    NOT NULL DEFAULT 0,
    `requires_php`    VARCHAR(10)   NOT NULL DEFAULT '8.0',
    `requires_mysql`  VARCHAR(10)   NOT NULL DEFAULT '5.7',
    `changelog`       TEXT          NOT NULL,
    `file_changes`    TEXT          NULL COMMENT 'JSON: {added:[],modified:[],removed:[]}',
    `released_at`     DATE          NOT NULL,
    `is_latest`       TINYINT(1)    NOT NULL DEFAULT 0,
    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_released_at` (`released_at`),
    KEY `idx_is_latest`   (`is_latest`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sc_assets` (
    `id`           INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `asset_type`   ENUM('font','script','css') NOT NULL,
    `family`       VARCHAR(100)   NOT NULL DEFAULT '' COMMENT 'Font family folder name — empty for scripts',
    `filename`     VARCHAR(200)   NOT NULL,
    `rel_path`     VARCHAR(300)   NOT NULL COMMENT 'Path relative to CMS root e.g. assets/fonts/Foo/foo.ttf',
    `file_path`    VARCHAR(500)   NOT NULL COMMENT 'Absolute path on this server',
    `download_url` VARCHAR(500)   NOT NULL,
    `file_size`    INT UNSIGNED   NOT NULL DEFAULT 0,
    `sha256`       CHAR(64)       NOT NULL DEFAULT '',
    `created_at`   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rel_path` (`rel_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Network Alert: current broadcast state (single row, Sean controls via sc-network-alert.php)
CREATE TABLE IF NOT EXISTS `sc_network_alert_state` (
    `id`      INT UNSIGNED  NOT NULL DEFAULT 1,
    `level`   ENUM('green','yellow_slow','yellow_fast') NOT NULL DEFAULT 'green',
    `message` VARCHAR(500)  NOT NULL DEFAULT '',
    `set_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `set_by`  VARCHAR(20)   NOT NULL DEFAULT 'manual' COMMENT 'manual | auto',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the single state row (safe on re-run: INSERT IGNORE)
INSERT IGNORE INTO `sc_network_alert_state` (`id`, `level`, `message`, `set_by`)
VALUES (1, 'green', '', 'init');

-- Network Alert: breach reports received from SnapSmack installs
CREATE TABLE IF NOT EXISTS `sc_network_alert_reports` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `site_name`      VARCHAR(255)  NOT NULL DEFAULT '',
    `site_url`       VARCHAR(500)  NOT NULL DEFAULT '',
    `request_ip`     VARCHAR(45)   NOT NULL DEFAULT '',
    `affected_files` JSON          NULL,
    `file_hashes`    JSON          NULL,
    `file_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `received_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `reviewed`       TINYINT(1)    NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_request_ip_received` (`request_ip`, `received_at`),
    KEY `idx_received_at`         (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Network Alert: installs opted in to receive SC push notifications on breach
CREATE TABLE IF NOT EXISTS `sc_push_subscribers` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `uid`           CHAR(32)      NOT NULL DEFAULT '' COMMENT 'Anonymous install uid — for correlation only',
    `site_url`      VARCHAR(500)  NOT NULL DEFAULT '',
    `site_name`     VARCHAR(255)  NOT NULL DEFAULT '',
    `push_token`    CHAR(64)      NOT NULL DEFAULT '' COMMENT '64-char hex secret — SC must include in push, spoke validates',
    `push_url`      VARCHAR(600)  NOT NULL DEFAULT '' COMMENT 'site_url + /network-alert-push.php',
    `registered_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_push_at`  TIMESTAMP     NULL DEFAULT NULL,
    `push_failures` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Consecutive delivery failures — auto-pruned at 5',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_site_url` (`site_url`(255)),
    KEY `idx_push_failures` (`push_failures`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sc_dev_builds` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `version`         VARCHAR(20)   NOT NULL COMMENT 'Short version string e.g. 0.7.184D',
    `version_full`    VARCHAR(50)   NOT NULL COMMENT 'Full display string e.g. Alpha 0.7.184D',
    `git_tag`         VARCHAR(100)  NOT NULL COMMENT 'Git tag checked out for this build',
    `checksum_sha256` VARCHAR(64)   NOT NULL,
    `download_url`    VARCHAR(500)  NOT NULL,
    `download_size`   INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'Zip file size in bytes',
    `built_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_built_at` (`built_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sc_rss_cache` (
    `install_id`      INT UNSIGNED   NOT NULL,
    `feed_url`        VARCHAR(500)   NOT NULL DEFAULT '',
    `last_fetched_at` TIMESTAMP      NULL     DEFAULT NULL,
    `last_status`     VARCHAR(20)    NOT NULL DEFAULT 'unknown' COMMENT 'live | dead | error | unknown',
    `last_http_code`  SMALLINT       NOT NULL DEFAULT 0,
    `item_count`      SMALLINT       NOT NULL DEFAULT 0,
    `latest_title`    VARCHAR(255)   NOT NULL DEFAULT '',
    `latest_pub_date` TIMESTAMP      NULL     DEFAULT NULL,
    `error_message`   TEXT           NULL,
    PRIMARY KEY (`install_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phone-home ping log (written by snapsmack.ca/releases/ping.php)
-- role=hub: standalone or hub install (counted in fleet total)
-- role=spoke: slim ping from a spoke; hub already counts it via spoke_count
CREATE TABLE IF NOT EXISTS `sc_phone_home` (
    `id`          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `uid`         CHAR(32)          NOT NULL,
    `version`     VARCHAR(20)       NOT NULL DEFAULT '',
    `track`       VARCHAR(10)       NOT NULL DEFAULT 'stable',
    `spoke_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `role`        VARCHAR(10)       NOT NULL DEFAULT 'hub',
    `hub_uid`     CHAR(32)          NULL     DEFAULT NULL COMMENT 'For spoke rows: the hub uid they belong to',
    `first_seen`  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen`   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uid`     (`uid`),
    KEY           `idx_hub_uid` (`hub_uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== SNAPSMACK EOF =====
