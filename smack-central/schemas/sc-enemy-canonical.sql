-- SMACK THE ENEMY — Canonical Schema
-- Database: squir871_enemy (isolated from smackcent and smackforum)
--
-- This file is the source of truth for the enemy DB schema.
-- sc-schema.php diffs the live DB against this file and can apply
-- missing tables and columns. Safe to re-run: all statements use
-- IF NOT EXISTS guards.
--
-- Tables (in dependency order):
--   ste_sites               — registered SnapSmack installs
--   ste_fingerprints        — tracked ban fingerprints (IP/email/browser)
--   ste_reports             — one report per site per fingerprint
--   ste_allow_votes         — community allow votes (false-positive signals)
--   ste_score_cache         — pre-computed scores for delta push
--   ste_coordination_clusters — detected sybil coordination events
--   ste_style_vectors       — stylometric writing style vectors (SMACK STYLE / Tier 3)

CREATE TABLE IF NOT EXISTS `ste_sites` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `site_url`         VARCHAR(500)    NOT NULL,
    `api_key`          CHAR(64)        NOT NULL,
    `post_count`       INT UNSIGNED    NOT NULL DEFAULT 0,
    `report_count`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `allow_count`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `override_rate`    DECIMAL(6,4)    NOT NULL DEFAULT 0.0000
        COMMENT 'Fraction of this site''s reports that other sites have allow-voted down',
    `weight_suspended` TINYINT(1)      NOT NULL DEFAULT 0,
    `status`           ENUM('active','opted_out') NOT NULL DEFAULT 'active',
    `registered_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_api_key`  (`api_key`),
    UNIQUE KEY `uq_site_url` (`site_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ste_fingerprints` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `ban_type`         ENUM('fingerprint','ip','email_hash') NOT NULL,
    `ban_value`        CHAR(64)        NOT NULL COMMENT 'SHA-256 hex of the original value',
    `score`            DECIMAL(10,4)   NOT NULL DEFAULT 0.0000,
    `colour_level`     ENUM('green','yellow','orange','red','black') NOT NULL DEFAULT 'green',
    `report_count`     INT UNSIGNED    NOT NULL DEFAULT 0,
    `allow_count`      INT UNSIGNED    NOT NULL DEFAULT 0,
    `last_score_update` TIMESTAMP      NULL     DEFAULT NULL,
    `last_seen`        TIMESTAMP       NULL     DEFAULT NULL
        COMMENT 'Timestamp of the most recent report for this fingerprint',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ban` (`ban_type`, `ban_value`),
    KEY `idx_colour` (`colour_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ste_reports` (
    `id`                       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `site_id`                  INT UNSIGNED  NOT NULL,
    `fingerprint_id`           INT UNSIGNED  NOT NULL,
    `reported_at`              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `site_weight_at_report`    DECIMAL(6,4)  NOT NULL DEFAULT 0.0000,
    `is_quarantined`           TINYINT(1)    NOT NULL DEFAULT 0,
    `coordination_cluster_id`  INT UNSIGNED  NULL     DEFAULT NULL
        COMMENT 'Set when this report was quarantined due to a coordination cluster',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_site_fingerprint` (`site_id`, `fingerprint_id`),
    KEY `idx_fingerprint` (`fingerprint_id`),
    KEY `idx_site`        (`site_id`),
    KEY `idx_cluster`     (`coordination_cluster_id`),
    KEY `idx_quarantined` (`is_quarantined`),
    CONSTRAINT `fk_report_site`        FOREIGN KEY (`site_id`)        REFERENCES `ste_sites`        (`id`),
    CONSTRAINT `fk_report_fingerprint` FOREIGN KEY (`fingerprint_id`) REFERENCES `ste_fingerprints` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ste_allow_votes` (
    `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `site_id`              INT UNSIGNED  NOT NULL,
    `fingerprint_id`       INT UNSIGNED  NOT NULL,
    `voted_at`             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `site_threshold`       ENUM('yellow','orange','red','black','never') NOT NULL DEFAULT 'red',
    `site_weight_at_vote`  DECIMAL(6,4)  NOT NULL DEFAULT 0.0000,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_site_fingerprint` (`site_id`, `fingerprint_id`),
    KEY `idx_fingerprint` (`fingerprint_id`),
    CONSTRAINT `fk_allow_site`        FOREIGN KEY (`site_id`)        REFERENCES `ste_sites`        (`id`),
    CONSTRAINT `fk_allow_fingerprint` FOREIGN KEY (`fingerprint_id`) REFERENCES `ste_fingerprints` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ste_score_cache` (
    `fingerprint_id` INT UNSIGNED   NOT NULL,
    `score`          DECIMAL(10,4)  NOT NULL DEFAULT 0.0000,
    `colour_level`   ENUM('green','yellow','orange','red','black') NOT NULL DEFAULT 'green',
    `computed_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`fingerprint_id`),
    KEY `idx_computed_at`  (`computed_at`),
    KEY `idx_colour_level` (`colour_level`),
    CONSTRAINT `fk_cache_fingerprint` FOREIGN KEY (`fingerprint_id`) REFERENCES `ste_fingerprints` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ste_coordination_clusters` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `fingerprint_id` INT UNSIGNED  NOT NULL,
    `site_count`     INT UNSIGNED  NOT NULL DEFAULT 0,
    `window_minutes` INT UNSIGNED  NOT NULL DEFAULT 10,
    `detected_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved`       TINYINT(1)    NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_fingerprint` (`fingerprint_id`),
    KEY `idx_resolved`    (`resolved`),
    CONSTRAINT `fk_cluster_fingerprint` FOREIGN KEY (`fingerprint_id`) REFERENCES `ste_fingerprints` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ste_style_vectors` (
    `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `fingerprint_id` INT UNSIGNED  NOT NULL,
    `site_id`        INT UNSIGNED  NOT NULL,
    `vector`         TEXT          NOT NULL  COMMENT 'JSON array — 25 normalized floats',
    `word_count`     SMALLINT      NOT NULL  DEFAULT 0   COMMENT 'Total words across source comments',
    `comment_count`  TINYINT       NOT NULL  DEFAULT 0   COMMENT 'Number of comments used',
    `recorded_at`    TIMESTAMP     NOT NULL  DEFAULT CURRENT_TIMESTAMP,
    `expires_at`     DATE          NOT NULL  COMMENT 'Hard delete after this date (1-year retention)',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_fp_site`    (`fingerprint_id`, `site_id`),
    KEY `idx_fingerprint`      (`fingerprint_id`),
    KEY `idx_expires`          (`expires_at`),
    CONSTRAINT `fk_style_fingerprint` FOREIGN KEY (`fingerprint_id`) REFERENCES `ste_fingerprints` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_style_site`        FOREIGN KEY (`site_id`)        REFERENCES `ste_sites`        (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
