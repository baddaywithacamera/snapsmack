-- SNAPSMACK_EOF_HEADER
--     -- ===== SNAPSMACK EOF =====
-- Last non-empty line of this file MUST match the line above.
-- Missing or different = truncated/corrupted. Restore before saving.


-- Migration: Multisite hub/spoke tables
-- Introduced: Alpha 0.7.9
-- Safe to run multiple times (CREATE TABLE IF NOT EXISTS, INSERT IGNORE)

CREATE TABLE IF NOT EXISTS `snap_multisite_nodes` (
    `id`                  int unsigned NOT NULL AUTO_INCREMENT,
    `role`                enum('hub','spoke') NOT NULL,
    `site_url`            varchar(500) NOT NULL,
    `site_name`           varchar(255) DEFAULT NULL,
    `api_key_local`       varchar(255) NOT NULL,
    `api_key_remote`      varchar(255) NOT NULL,
    `software_version`    varchar(50)  DEFAULT NULL,
    `last_seen_at`        datetime     DEFAULT NULL,
    `post_count`          int unsigned DEFAULT 0,
    `image_count`         int unsigned DEFAULT 0,
    `pending_comments`    int unsigned DEFAULT 0,
    `last_backup_at`      datetime     DEFAULT NULL,
    `last_backup_size`    bigint unsigned DEFAULT NULL,
    `last_backup_dest`    varchar(100) DEFAULT NULL,
    `last_backup_status`  enum('ok','failed','unknown') DEFAULT 'unknown',
    `disk_usage_bytes`    bigint unsigned DEFAULT NULL,
    `status`              enum('active','offline','disconnected') DEFAULT 'active',
    `connected_at`        datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_site_url` (`site_url`),
    KEY `idx_role_status` (`role`, `status`),
    KEY `idx_last_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_multisite_queue` (
    `id`           int unsigned NOT NULL AUTO_INCREMENT,
    `node_id`      int unsigned NOT NULL,
    `action`       varchar(50)  NOT NULL,
    `payload`      text,
    `status`       enum('pending','processing','completed','failed') DEFAULT 'pending',
    `attempts`     tinyint unsigned DEFAULT 0,
    `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_node_status` (`node_id`, `status`),
    KEY `idx_created` (`created_at`),
    CONSTRAINT `fk_queue_node` FOREIGN KEY (`node_id`)
        REFERENCES `snap_multisite_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `snap_settings` (`setting_key`, `setting_val`) VALUES ('multisite_role', '');
INSERT IGNORE INTO `snap_settings` (`setting_key`, `setting_val`) VALUES ('multisite_reg_token', '');
INSERT IGNORE INTO `snap_settings` (`setting_key`, `setting_val`) VALUES ('multisite_reg_token_expires', '0');
-- ===== SNAPSMACK EOF =====
