-- ─────────────────────────────────────────────────────────────────────────────
-- SMACK THE ENEMY — Central Database Schema
-- SnapSmack Shield Tier 2
--
-- Runs on squir871_enemy (dedicated DB on snapsmack.ca).
-- Apply once via phpMyAdmin or CLI. All tables use CREATE IF NOT EXISTS
-- so this file is safe to re-run.
-- ─────────────────────────────────────────────────────────────────────────────


-- ─── REGISTERED SITES ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `ste_sites` (
  `id`               int unsigned     NOT NULL AUTO_INCREMENT,
  `site_url`         varchar(255)     COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_key`          char(64)         COLLATE utf8mb4_unicode_ci NOT NULL
                     COMMENT 'Random 64-char hex key sent to the site on registration.',
  `post_count`       int unsigned     NOT NULL DEFAULT 0,
  `registered_at`    datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at`     datetime         DEFAULT NULL,
  `report_count`     int unsigned     NOT NULL DEFAULT 0
                     COMMENT 'Total lifetime reports submitted by this site.',
  `allow_count`      int unsigned     NOT NULL DEFAULT 0
                     COMMENT 'Total lifetime allow votes submitted by this site.',
  `override_rate`    float            NOT NULL DEFAULT 0.0
                     COMMENT 'Fraction of this site''s reports overridden by allow votes (0.0–1.0). Recomputed periodically.',
  `weight_suspended` tinyint(1)       NOT NULL DEFAULT 0
                     COMMENT '1 = velocity flag triggered; reports contribute zero weight.',
  `status`           enum('active','suspended','opted_out')
                     COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_url` (`site_url`),
  UNIQUE KEY `uq_api_key`  (`api_key`),
  KEY `idx_status`   (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registered participating SnapSmack installations.';


-- ─── FINGERPRINT REGISTRY ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `ste_fingerprints` (
  `id`               int unsigned     NOT NULL AUTO_INCREMENT,
  `ban_type`         enum('fingerprint','ip','email_hash')
                     COLLATE utf8mb4_unicode_ci NOT NULL,
  `ban_value`        char(64)         COLLATE utf8mb4_unicode_ci NOT NULL
                     COMMENT 'SHA-256 hex. Never a raw value.',
  `score`            float            NOT NULL DEFAULT 0.0
                     COMMENT 'Current weighted reputation score. Recomputed on each report/allow/decay tick.',
  `colour_level`     enum('green','yellow','orange','red','black')
                     COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'green'
                     COMMENT 'Materialised from score for fast delivery.',
  `report_count`     int unsigned     NOT NULL DEFAULT 0
                     COMMENT 'Number of distinct sites that have reported this hash.',
  `allow_count`      int unsigned     NOT NULL DEFAULT 0
                     COMMENT 'Number of distinct sites that have allowed this hash.',
  `first_seen`       datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen`        datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,
  `last_score_update` datetime        DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_type_val` (`ban_type`, `ban_value`),
  KEY `idx_colour`   (`colour_level`),
  KEY `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Central fingerprint reputation registry.';


-- ─── BAN REPORTS ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `ste_reports` (
  `id`                    int unsigned  NOT NULL AUTO_INCREMENT,
  `site_id`               int unsigned  NOT NULL,
  `fingerprint_id`        int unsigned  NOT NULL,
  `reported_at`           datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP
                          COMMENT 'Timestamp of original ban on the reporting site.',
  `site_weight_at_report` float         NOT NULL DEFAULT 0.0
                          COMMENT 'Snapshot of site weight at report time — used for decay calc.',
  `is_quarantined`        tinyint(1)    NOT NULL DEFAULT 0
                          COMMENT '1 = velocity flag; not applied to score.',
  `coordination_cluster_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_fp` (`site_id`, `fingerprint_id`)
                          COMMENT 'One strike per site per fingerprint. Refresh reported_at on duplicate.',
  KEY `idx_fp`    (`fingerprint_id`),
  KEY `idx_site`  (`site_id`),
  KEY `idx_reported_at` (`reported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Individual ban reports from participating sites.';


-- ─── ALLOW VOTES ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `ste_allow_votes` (
  `id`                  int unsigned  NOT NULL AUTO_INCREMENT,
  `site_id`             int unsigned  NOT NULL,
  `fingerprint_id`      int unsigned  NOT NULL,
  `voted_at`            datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `site_threshold`      enum('yellow','orange','red','black','never')
                        COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'red'
                        COMMENT 'Threshold the site had at vote time — determines allow weight multiplier.',
  `site_weight_at_vote` float         NOT NULL DEFAULT 0.0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_fp` (`site_id`, `fingerprint_id`)
                        COMMENT 'One allow vote per site per fingerprint.',
  KEY `idx_fp`   (`fingerprint_id`),
  KEY `idx_site` (`site_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Explicit allow votes — admin approved a flagged comment.';


-- ─── SCORE CACHE ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `ste_score_cache` (
  `fingerprint_id`  int unsigned  NOT NULL,
  `score`           float         NOT NULL DEFAULT 0.0,
  `colour_level`    enum('green','yellow','orange','red','black')
                    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'green',
  `computed_at`     datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`fingerprint_id`),
  KEY `idx_computed_at` (`computed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pre-computed scores for delta push to registered sites.';


-- ─── COORDINATION CLUSTERS ────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `ste_coordination_clusters` (
  `id`              int unsigned  NOT NULL AUTO_INCREMENT,
  `fingerprint_id`  int unsigned  NOT NULL,
  `detected_at`     datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `site_count`      int           NOT NULL DEFAULT 0,
  `window_minutes`  int           NOT NULL DEFAULT 10,
  `resolved`        tinyint(1)    NOT NULL DEFAULT 0
                    COMMENT '1 = reviewed and cleared; quarantine lifted.',
  PRIMARY KEY (`id`),
  KEY `idx_fp`       (`fingerprint_id`),
  KEY `idx_resolved` (`resolved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Suspected coordinated submission events for review.';
