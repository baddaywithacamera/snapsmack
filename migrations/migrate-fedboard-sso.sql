-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
-- FEDBOARD: concurrent destination-bound SSO tickets and token-free audit history.

CREATE TABLE IF NOT EXISTS `snap_multisite_sso_tokens` (
  `id`             bigint unsigned NOT NULL AUTO_INCREMENT,
  `token_hash`     char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `destination`    enum('admin','fedboard') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin',
  `requested_by`   varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `expires_at`     datetime NOT NULL,
  `created_at`     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sso_token_hash` (`token_hash`),
  KEY `ix_sso_token_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_multisite_sso_audit` (
  `id`             bigint unsigned NOT NULL AUTO_INCREMENT,
  `direction`      enum('hub','spoke') COLLATE utf8mb4_unicode_ci NOT NULL,
  `peer_url`       varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `destination`    enum('admin','fedboard') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin',
  `outcome`        varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `admin_user_id`  int unsigned DEFAULT NULL,
  `created_at`     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_sso_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `snap_multisite_nodes`
  ADD COLUMN IF NOT EXISTS `fedboard_sso_enabled` tinyint(1) NOT NULL DEFAULT 0
  COMMENT 'Heartbeat cache: spoke explicitly permits hub SSO/FEDBOARD entry';

-- ===== SNAPSMACK EOF =====
