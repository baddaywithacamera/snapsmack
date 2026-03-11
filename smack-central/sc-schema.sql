-- SMACK CENTRAL Schema
-- Extends squir871_smackforum (same database as forum-server).
-- Safe to re-run: all CREATE TABLE uses IF NOT EXISTS.
-- Run this AFTER forum-schema.sql.

-- ── Admin users ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sc_admin_users (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    username      VARCHAR(50)     NOT NULL,
    password_hash VARCHAR(255)    NOT NULL,
    last_login_at TIMESTAMP       NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Key-value settings ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sc_settings (
    setting_key VARCHAR(100) NOT NULL,
    setting_val TEXT         NOT NULL DEFAULT '',
    updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Release history ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sc_releases (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    version         VARCHAR(20)  NOT NULL COMMENT 'Short version string e.g. 0.8',
    version_full    VARCHAR(50)  NOT NULL COMMENT 'Full display string e.g. Alpha 0.8',
    git_tag         VARCHAR(100) NOT NULL COMMENT 'Git tag checked out for this release',
    checksum_sha256 VARCHAR(64)  NOT NULL,
    signature       VARCHAR(128) NOT NULL COMMENT 'Hex Ed25519 signature of the SHA-256 checksum',
    download_url    VARCHAR(500) NOT NULL,
    download_size   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Zip file size in bytes',
    schema_changes  TINYINT(1)   NOT NULL DEFAULT 0,
    requires_php    VARCHAR(10)  NOT NULL DEFAULT '8.0',
    requires_mysql  VARCHAR(10)  NOT NULL DEFAULT '5.7',
    changelog       TEXT         NOT NULL DEFAULT '',
    file_changes    TEXT         NULL COMMENT 'JSON: {added:[],modified:[],removed:[]}',
    released_at     DATE         NOT NULL,
    is_latest       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_released_at (released_at),
    KEY idx_is_latest   (is_latest)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── RSS cache ─────────────────────────────────────────────────────────────────
-- (Future: Phase 4 RSS Console)
CREATE TABLE IF NOT EXISTS sc_rss_cache (
    install_id      INT UNSIGNED  NOT NULL,
    feed_url        VARCHAR(500)  NOT NULL DEFAULT '',
    last_fetched_at TIMESTAMP     NULL DEFAULT NULL,
    last_status     VARCHAR(20)   NOT NULL DEFAULT 'unknown' COMMENT 'live | dead | error | unknown',
    last_http_code  SMALLINT      NOT NULL DEFAULT 0,
    item_count      SMALLINT      NOT NULL DEFAULT 0,
    latest_title    VARCHAR(255)  NOT NULL DEFAULT '',
    latest_pub_date TIMESTAMP     NULL DEFAULT NULL,
    error_message   TEXT          NULL,
    PRIMARY KEY (install_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
