-- SnapSmack SQL Differential — 0.7.9g (batch b)
-- Generated from squir871_hkdoao dump vs snapsmack_canonical.sql
-- Safe to run multiple times (IF NOT EXISTS / errno 1060 on duplicates).
-- ─────────────────────────────────────────────────────────────────────

-- ── 029: Browser fingerprint columns + ban list ──────────────────────
-- (Migration 029_fingerprint_ban_system.php was not applied)

ALTER TABLE `snap_comments`
    ADD COLUMN `fp_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'SHA-256 browser fingerprint collected at submission time'
        AFTER `comment_ip`,
    ADD KEY `idx_fp_hash` (`fp_hash`);

ALTER TABLE `snap_community_comments`
    ADD COLUMN `fp_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'SHA-256 browser fingerprint collected at submission time'
        AFTER `ip`,
    ADD KEY `idx_fp_hash` (`fp_hash`);

CREATE TABLE IF NOT EXISTS `snap_ban_list` (
    `id`          int unsigned     NOT NULL AUTO_INCREMENT,
    `ban_type`    enum('fingerprint','ip','email_hash')
                  COLLATE utf8mb4_unicode_ci NOT NULL,
    `ban_value`   varchar(255)     COLLATE utf8mb4_unicode_ci NOT NULL,
    `reason`      varchar(500)     COLLATE utf8mb4_unicode_ci DEFAULT NULL
                  COMMENT 'Admin note — not shown to the banned user',
    `banned_at`   datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `banned_by`   int unsigned     DEFAULT NULL
                  COMMENT 'snap_users.id of the admin who created this ban',
    PRIMARY KEY (`id`),
    UNIQUE KEY  `uq_ban`       (`ban_type`, `ban_value`),
    KEY         `idx_type_val` (`ban_type`, `ban_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Centralised ban list: fingerprint, IP, and email-hash bans';

-- ── NEW: snap_categories.show_in_archive (added 0.7.9f) ─────────────

ALTER TABLE `snap_categories`
    ADD COLUMN `show_in_archive` tinyint(1) NOT NULL DEFAULT 1
        COMMENT '1 = visible in public archive; 0 = hidden (added 0.7.9f)';

-- ── NEW: snap_ohsnap_keys table (Oh Snap! API keys) ─────────────────

CREATE TABLE IF NOT EXISTS `snap_ohsnap_keys` (
    `id`           int            NOT NULL AUTO_INCREMENT,
    `label`        varchar(100)   COLLATE utf8mb4_unicode_ci NOT NULL
                   COMMENT 'Human-readable label assigned at creation',
    `key_hash`     varchar(64)    COLLATE utf8mb4_unicode_ci NOT NULL
                   COMMENT 'SHA-256 hex digest of the raw key — key itself is never stored',
    `key_prefix`   varchar(8)     COLLATE utf8mb4_unicode_ci NOT NULL
                   COMMENT 'First 8 chars of raw key for identification in the UI',
    `is_active`    tinyint(1)     NOT NULL DEFAULT 1,
    `created_at`   datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` datetime       DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_key_hash` (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Done ─────────────────────────────────────────────────────────────
