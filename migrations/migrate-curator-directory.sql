-- Consent-managed fediverse.info photography directory follows.
CREATE TABLE IF NOT EXISTS `snap_curator_directory` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(80) NOT NULL DEFAULT 'fediverse.info:photography',
  `source_id` varchar(100) DEFAULT NULL,
  `acct` varchar(255) NOT NULL,
  `actor_url` varchar(500) DEFAULT NULL,
  `follow_row_id` int unsigned DEFAULT NULL,
  `state` varchar(32) NOT NULL DEFAULT 'discovered',
  `seen_generation` char(36) DEFAULT NULL,
  `first_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `missing_since` datetime DEFAULT NULL,
  `last_checked_at` datetime DEFAULT NULL,
  `next_check_at` datetime DEFAULT NULL,
  `failure_count` int unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_curator_source_acct` (`source`,`acct`),
  KEY `idx_curator_work` (`state`,`next_check_at`),
  KEY `idx_curator_generation` (`source`,`seen_generation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `snap_settings` (`setting_key`,`setting_val`) VALUES ('curator_directory_enabled','0');
-- ===== SNAPSMACK EOF =====
