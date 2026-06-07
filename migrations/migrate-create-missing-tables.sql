-- SnapSmack migration: create all tables missing from installs that predate
-- the canonical schema system working correctly.
-- All statements use IF NOT EXISTS — safe to run on any install regardless of state.
-- 0.7.215

CREATE TABLE IF NOT EXISTS `snap_posts` (
  `id`                int            NOT NULL AUTO_INCREMENT,
  `title`             varchar(500)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`              varchar(600)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `description`       text           COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `post_type`         enum('single','carousel','panorama','longform') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single',
  `status`            varchar(20)    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
  `created_at`        datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `allow_comments`    tinyint(1)     NOT NULL DEFAULT 1,
  `allow_download`    tinyint(1)     NOT NULL DEFAULT 0,
  `download_url`      varchar(500)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `download_count`    int            NOT NULL DEFAULT 0,
  `panorama_rows`     tinyint        NOT NULL DEFAULT 1,
  `import_source`     varchar(50)    COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_id`         varchar(200)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `post_img_size_pct` tinyint unsigned NOT NULL DEFAULT 100,
  `post_border_px`    tinyint unsigned NOT NULL DEFAULT 0,
  `post_border_color` char(7)        COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#000000',
  `post_bg_color`     char(7)        COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#ffffff',
  `post_shadow`       tinyint unsigned NOT NULL DEFAULT 0,
  `content`           longtext       COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `featured_asset_id` int unsigned   DEFAULT NULL,
  `trigram_id`        int unsigned   DEFAULT NULL,
  `sort_order`        int            NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_post_type` (`post_type`),
  KEY `idx_trigram` (`trigram_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_trigrams` (
  `id`          INT UNSIGNED        NOT NULL AUTO_INCREMENT,
  `source_path` VARCHAR(500)        NOT NULL,
  `orientation` ENUM('h','v')       NOT NULL DEFAULT 'h',
  `cut_a`       SMALLINT UNSIGNED   NOT NULL,
  `cut_b`       SMALLINT UNSIGNED   NOT NULL,
  `post_id_1`   INT UNSIGNED        NOT NULL,
  `post_id_2`   INT UNSIGNED        NOT NULL,
  `post_id_3`   INT UNSIGNED        NOT NULL,
  `created_at`  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_post_1` (`post_id_1`),
  KEY `idx_post_2` (`post_id_2`),
  KEY `idx_post_3` (`post_id_3`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_post_images` (
  `id`               int            NOT NULL AUTO_INCREMENT,
  `post_id`          int            NOT NULL,
  `image_id`         int            NOT NULL,
  `sort_position`    smallint       NOT NULL DEFAULT 0,
  `is_cover`         tinyint(1)     NOT NULL DEFAULT 0,
  `grid_col`         tinyint        DEFAULT NULL,
  `grid_row`         tinyint        DEFAULT NULL,
  `img_size_pct`     tinyint unsigned NOT NULL DEFAULT 100,
  `img_border_px`    tinyint unsigned NOT NULL DEFAULT 0,
  `img_border_color` char(7)        COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#000000',
  `img_bg_color`     char(7)        COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#ffffff',
  `img_shadow`       tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_image` (`image_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_sort` (`post_id`, `sort_position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_tags` (
  `id`           int unsigned  NOT NULL AUTO_INCREMENT,
  `tag`          varchar(100)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`         varchar(100)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `use_count`    int unsigned  DEFAULT 0,
  `color_family` varchar(20)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at`   timestamp     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tag_slug` (`slug`),
  KEY `idx_tags_color_family` (`color_family`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_image_tags` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `image_id`   int unsigned NOT NULL,
  `tag_id`     int unsigned NOT NULL,
  `created_at` timestamp    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_image_tag` (`image_id`, `tag_id`),
  KEY `idx_image_tags_image_id` (`image_id`),
  KEY `idx_image_tags_tag_id`   (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_post_cat_map` (
  `post_id` int NOT NULL,
  `cat_id`  int NOT NULL,
  PRIMARY KEY (`post_id`, `cat_id`),
  KEY `idx_cat_id` (`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_post_album_map` (
  `post_id`  int NOT NULL,
  `album_id` int NOT NULL,
  PRIMARY KEY (`post_id`, `album_id`),
  KEY `idx_album_id` (`album_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_migrations` (
  `migration`  varchar(100) NOT NULL,
  `applied_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_password_resets` (
  `id`     int          NOT NULL AUTO_INCREMENT,
  `email`  varchar(255) NOT NULL,
  `token`  varchar(64)  NOT NULL,
  `expiry` datetime     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_password_resets_email` (`email`),
  KEY `idx_password_resets_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_rate_limits` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `ip`           varchar(45)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `action`       varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `count`        int unsigned NOT NULL DEFAULT 1,
  `window_start` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ip_action` (`ip`, `action`),
  KEY `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_ip_bans` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `ip`         varchar(45)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason`     varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto:brute_force',
  `banned_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ip` (`ip`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_totp_devices` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED    NOT NULL,
  `token_hash`  CHAR(64)        NOT NULL,
  `device_hint` VARCHAR(120)    NOT NULL DEFAULT '',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`  DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_user_expires` (`user_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_community_users` (
  `id`             int unsigned NOT NULL AUTO_INCREMENT,
  `username`       varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name`   varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email`          varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash`  varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar_url`     varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio`            text         COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status`         enum('active','unverified','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unverified',
  `email_verified` tinyint(1)   NOT NULL DEFAULT 0,
  `last_seen_at`   datetime     DEFAULT NULL,
  `created_at`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_community_sessions` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `user_id`    int unsigned NOT NULL,
  `token`      varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime     NOT NULL,
  `ip`         varchar(45)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_community_tokens` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `user_id`    int unsigned NOT NULL,
  `token`      varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `type`       varchar(30)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime     NOT NULL,
  `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_user_type` (`user_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_community_comments` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `post_id`      int unsigned NOT NULL,
  `user_id`      int unsigned NULL DEFAULT NULL,
  `guest_name`   varchar(100) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `guest_email`  varchar(200) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `guest_url`    varchar(500) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `comment_text` text         COLLATE utf8mb4_unicode_ci NOT NULL,
  `status`       enum('visible','hidden','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'visible',
  `ip`           varchar(45)  COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `fp_hash`      varchar(64)  COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at`    datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_post_status` (`post_id`, `status`),
  KEY `idx_fp_hash` (`fp_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_likes` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `post_id`    int unsigned NOT NULL,
  `user_id`    int unsigned NOT NULL,
  `guest_hash` varchar(64)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_post_user` (`post_id`, `user_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_likes_guest` (`post_id`, `guest_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_reactions` (
  `id`            int unsigned NOT NULL AUTO_INCREMENT,
  `post_id`       int unsigned NOT NULL,
  `user_id`       int unsigned NOT NULL,
  `guest_hash`    varchar(64)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reaction_code` varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_post_user` (`post_id`, `user_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_reactions_guest` (`post_id`, `guest_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_ban_list` (
  `id`        int unsigned     NOT NULL AUTO_INCREMENT,
  `ban_type`  enum('fingerprint','ip','email_hash') COLLATE utf8mb4_unicode_ci NOT NULL,
  `ban_value` varchar(255)     COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason`    varchar(500)     COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `banned_at` datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `banned_by` int unsigned     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ban`       (`ban_type`, `ban_value`),
  KEY         `idx_type_val` (`ban_type`, `ban_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_ste_scores` (
  `ban_type`     varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `ban_hash`     varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `score`        float        NOT NULL DEFAULT 0,
  `colour_level` varchar(10)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'green',
  `last_updated` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ban_type`, `ban_hash`),
  KEY `idx_colour` (`colour_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_comments_semantic` (
  `id`               int unsigned  NOT NULL AUTO_INCREMENT,
  `comment_id`       int unsigned  NOT NULL,
  `fingerprint_hash` varchar(255)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment_text`     longtext      COLLATE utf8mb4_unicode_ci NOT NULL,
  `tfidf_vector`     json          DEFAULT NULL,
  `created_at`       timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fingerprint` (`fingerprint_hash`),
  KEY `idx_created`     (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_keywords` (
  `id`         int unsigned  NOT NULL AUTO_INCREMENT,
  `keyword`    varchar(500)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `match_type` enum('exact','substring','regex') COLLATE utf8mb4_unicode_ci DEFAULT 'substring',
  `severity`   enum('flag','reject') COLLATE utf8mb4_unicode_ci DEFAULT 'flag',
  `reason`     varchar(255)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `added_at`   timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `added_by`   varchar(100)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keyword` (`keyword`),
  KEY `idx_keyword` (`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_stats` (
  `id`            int unsigned  NOT NULL AUTO_INCREMENT,
  `hit_at`        datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `page_type`     varchar(50)   COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `page_slug`     varchar(600)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_id`      int unsigned  DEFAULT NULL,
  `referrer`      varchar(500)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referrer_host` varchar(255)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent`    varchar(500)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `browser`       varchar(100)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `os`            varchar(100)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country`       varchar(10)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_hash`       varchar(64)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_bot`        tinyint(1)    NOT NULL DEFAULT 0,
  `search_term`   varchar(255)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_hit_at`       (`hit_at`),
  KEY `idx_is_bot`       (`is_bot`),
  KEY `idx_image_id`     (`image_id`),
  KEY `idx_stats_enriched` (`is_bot`, `hit_at`, `image_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_stats_daily` (
  `id`              int unsigned NOT NULL AUTO_INCREMENT,
  `stat_date`       date         NOT NULL,
  `total_views`     int unsigned NOT NULL DEFAULT 0,
  `unique_visitors` int unsigned NOT NULL DEFAULT 0,
  `bot_views`       int unsigned NOT NULL DEFAULT 0,
  `top_image_id`    int unsigned DEFAULT NULL,
  `top_referrer`    varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stat_date` (`stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_pimpotron_slideshows` (
  `id`                  int unsigned NOT NULL AUTO_INCREMENT,
  `name`                varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`                varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `default_speed_ms`    int          NOT NULL DEFAULT 5000,
  `glitch_frequency`    varchar(30)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'occasional',
  `glitch_intensity`    varchar(30)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `stage_shift_enabled` tinyint(1)   NOT NULL DEFAULT 0,
  `stage_shift_max_px`  int          NOT NULL DEFAULT 8,
  `stage_scale_max`     float        NOT NULL DEFAULT 1.015,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_pimpotron_slides` (
  `id`                   int unsigned NOT NULL AUTO_INCREMENT,
  `slideshow_id`         int unsigned NOT NULL,
  `image_id`             int unsigned NOT NULL,
  `caption`              text         COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `speed_ms`             int          DEFAULT NULL,
  `glitch_frequency`     varchar(30)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `glitch_intensity`     varchar(30)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stage_shift_enabled`  tinyint(1)   NOT NULL DEFAULT 0,
  `is_active`            tinyint(1)   NOT NULL DEFAULT 1,
  `sort_order`           int          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_slideshow_id` (`slideshow_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_ohsnap_keys` (
  `id`           int            NOT NULL AUTO_INCREMENT,
  `label`        varchar(100)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_type`     varchar(20)    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ohsnap',
  `key_hash`     varchar(64)    COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_prefix`   varchar(8)     COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active`    tinyint(1)     NOT NULL DEFAULT 1,
  `created_at`   datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime       DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key_hash` (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_mosaics` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(150)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_ids`  LONGTEXT      COLLATE utf8mb4_unicode_ci NOT NULL,
  `gap`        TINYINT       NOT NULL DEFAULT 4,
  `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_skin_presets` (
  `id`          int      NOT NULL AUTO_INCREMENT,
  `skin_slug`   varchar(80)  NOT NULL,
  `preset_name` varchar(120) NOT NULL,
  `preset_data` json         NOT NULL,
  `created_at`  datetime     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_skin` (`skin_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_file_manifest` (
  `id`             int unsigned   NOT NULL AUTO_INCREMENT,
  `file_path`      varchar(500)   NOT NULL,
  `expected_hash`  char(64)       NOT NULL,
  `file_size`      int unsigned   NOT NULL,
  `expected_mtime` int unsigned   DEFAULT NULL,
  `eof_signature`  varchar(512)   DEFAULT NULL,
  `skin_id`        varchar(64)    DEFAULT NULL,
  `baseline_set`   datetime       NOT NULL,
  `last_verified`  datetime       DEFAULT NULL,
  `last_status`    enum('ok','tampered','truncated','corrupted','missing','pending') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_path` (`file_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_smackback_log` (
  `id`             int unsigned   NOT NULL AUTO_INCREMENT,
  `detected_at`    datetime       NOT NULL,
  `resolved_at`    datetime       DEFAULT NULL,
  `affected_files` text           NOT NULL,
  `file_count`     smallint       NOT NULL DEFAULT '0',
  `resolution`     varchar(64)    DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_cats` (
  `id`   int          NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_backup_log` (
  `id`          int unsigned   NOT NULL AUTO_INCREMENT,
  `created_at`  datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status`      varchar(20)    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ok',
  `size_bytes`  bigint unsigned DEFAULT NULL,
  `destination` varchar(100)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes`       text           COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== SNAPSMACK EOF =====
