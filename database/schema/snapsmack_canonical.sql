-- ─────────────────────────────────────────────────────────────────────────────
-- SNAPSMACK — Canonical Schema
-- Alpha v0.7.9i "Is This Seat Taken"
--
-- This file is the single source of truth for the intended database structure
-- at the current release. It is maintained by hand alongside schema-sync.php
-- and must be updated whenever a table or column changes.
--
-- Rules:
--   • No DROP TABLE — this is a reference, not a destructive script.
--   • No AUTO_INCREMENT values — those are install-specific.
--   • CREATE TABLE IF NOT EXISTS throughout — safe to apply against any install.
--   • New columns added since the initial table definition are marked with a
--     version comment so the history is readable as a diff.
--
-- For runtime application, schema-sync.php reads these definitions (expressed
-- as PHP heredocs) and applies them idempotently via INFORMATION_SCHEMA checks.
-- ─────────────────────────────────────────────────────────────────────────────


-- ─── CORE IMAGE STORE ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_images` (
  `id`                  int            NOT NULL AUTO_INCREMENT,
  `img_title`           varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `img_slug`            varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `img_description`     text           COLLATE utf8mb4_unicode_ci,
  `img_film`            varchar(100)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `img_date`            datetime       NOT NULL,
  `img_file`            varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `img_source_file`     varchar(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL -- 0.7.7: original filename on the posting machine at upload time
                        COMMENT 'Original filename on the posting machine at upload time',
  `img_exif`            text           COLLATE utf8mb4_unicode_ci,
  `img_download_url`    varchar(500)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `img_download_count`  int unsigned   NOT NULL DEFAULT '0',
  `img_width`           int            DEFAULT '0',
  `img_height`          int            DEFAULT '0',
  `img_status`          enum('published','draft') COLLATE utf8mb4_unicode_ci DEFAULT 'published',
  `img_orientation`     int            DEFAULT '0',
  `allow_comments`      tinyint(1)     DEFAULT '1',
  `allow_download`      tinyint(1)     NOT NULL DEFAULT '1',
  `download_url`        varchar(512)   COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `img_thumb_square`    varchar(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL
                        COMMENT 'Relative path to 400x400 square thumbnail (t_ prefix)',
  `img_thumb_aspect`    varchar(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL
                        COMMENT 'Relative path to aspect-ratio thumbnail (a_ prefix)',
  `img_checksum`        varchar(64)    COLLATE utf8mb4_unicode_ci DEFAULT NULL
                        COMMENT 'SHA-256 hash of main image file for recovery verification',
  `img_display_options` text           COLLATE utf8mb4_unicode_ci
                        COMMENT 'JSON: per-image frame/mat/bevel overrides and extracted colour palette',
  `post_id`             int            DEFAULT NULL
                        COMMENT 'FK to snap_posts — populated when image is wrapped in a post',
  `sort_order`          int            NOT NULL DEFAULT '0' -- 0.7.8: manual display order
                        COMMENT 'Manual display order. Lower = earlier in feed. 0 = unset (falls back to img_date DESC).',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── POSTS (image wrappers for carousels, panoramas, etc.) ───────────────────

CREATE TABLE IF NOT EXISTS `snap_posts` (
  `id`                int            NOT NULL AUTO_INCREMENT,
  `title`             varchar(500)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`              varchar(600)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `description`       text           COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `post_type`         enum('single','carousel','panorama') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single',
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_post_type` (`post_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── POST → IMAGE PIVOT ───────────────────────────────────────────────────────

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


-- ─── TAXONOMY ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_categories` (
  `id`              int   NOT NULL AUTO_INCREMENT,
  `cat_name`        varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cat_slug`        varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cat_description` text  COLLATE utf8mb4_unicode_ci,
  `cover_image_id`  int   DEFAULT NULL
                    COMMENT 'Optional manual cover override — FK to snap_images',
  `show_in_archive` tinyint(1) NOT NULL DEFAULT 1
                    COMMENT '1 = visible in public archive; 0 = hidden (added 0.7.9f)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_albums` (
  `id`                int  NOT NULL AUTO_INCREMENT,
  `album_name`        varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `album_description` text COLLATE utf8mb4_unicode_ci,
  `cover_image_id`    int  DEFAULT NULL
                      COMMENT 'Optional manual cover override — FK to snap_images',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_tags` (
  `id`           int unsigned  NOT NULL AUTO_INCREMENT,
  `tag`          varchar(100)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`         varchar(100)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `use_count`    int unsigned  DEFAULT 0,
  `color_family` varchar(20)   COLLATE utf8mb4_unicode_ci DEFAULT NULL, -- 0.7.4/0.7.6
  `created_at`   timestamp     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tag_slug` (`slug`),
  KEY `idx_tags_color_family` (`color_family`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── PIVOT TABLES ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_image_cat_map` (
  `image_id` int NOT NULL,
  `cat_id`   int NOT NULL,
  PRIMARY KEY (`image_id`, `cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_image_album_map` (
  `image_id` int NOT NULL,
  `album_id` int NOT NULL,
  PRIMARY KEY (`image_id`, `album_id`)
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


-- ─── STATIC PAGES ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_pages` (
  `id`           int          NOT NULL AUTO_INCREMENT,
  `slug`         varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title`        varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content`      longtext     COLLATE utf8mb4_unicode_ci,
  `image_asset`  varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `image_size`   varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full',   -- 0.7.8
  `image_align`  varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'center', -- 0.7.8
  `image_shadow` tinyint(1)   NOT NULL DEFAULT 0,                                   -- 0.7.8
  `is_active`    tinyint(1)   DEFAULT '1',
  `menu_order`   int          DEFAULT '0',
  `created_at`   timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── SETTINGS ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_settings` (
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_val` text         COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── COMMENTS ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_comments` (
  `id`             int          NOT NULL AUTO_INCREMENT,
  `img_id`         int          NOT NULL,
  `comment_author` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment_email`  varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment_text`   text         COLLATE utf8mb4_unicode_ci,
  `comment_date`   datetime     DEFAULT CURRENT_TIMESTAMP,
  `comment_ip`     varchar(45)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fp_hash`        varchar(64)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SHA-256 browser fingerprint',
  `is_approved`    tinyint(1)   DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `img_id` (`img_id`),
  KEY `idx_fp_hash` (`fp_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── USERS ────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_users` (
  `id`             int          NOT NULL AUTO_INCREMENT,
  `username`       varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash`  varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_role`      varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'editor',
  `email`                varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferred_skin`       varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'default-dark',
  `recovery_code_hash`   varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── BLOGROLL ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_blogroll_cats` (
  `id`       int          NOT NULL AUTO_INCREMENT,
  `cat_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_blogroll` (
  `id`               int          NOT NULL AUTO_INCREMENT,
  `peer_name`        varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `peer_url`         varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cat_id`           int          DEFAULT NULL,
  `peer_rss`         varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `peer_desc`        text         COLLATE utf8mb4_unicode_ci,
  `sort_order`       int          NOT NULL DEFAULT '0',
  `rss_last_fetched` datetime     DEFAULT NULL,
  `rss_last_updated` datetime     DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── ASSETS / MIGRATIONS ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_assets` (
  `id`             int          NOT NULL AUTO_INCREMENT,
  `asset_name`     varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_path`     varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_checksum` varchar(64)  COLLATE utf8mb4_unicode_ci DEFAULT NULL
                   COMMENT 'SHA-256 hash for recovery verification',
  `created_at`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_migrations` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `migration`  varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `applied_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── AUTH / SECURITY ──────────────────────────────────────────────────────────

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


-- ─── COMMUNITY (optional feature set) ────────────────────────────────────────

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
  `guest_name`   varchar(100) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,  -- 0.7.6
  `guest_email`  varchar(200) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,  -- 0.7.6
  `comment_text` text         COLLATE utf8mb4_unicode_ci NOT NULL,
  `status`       enum('visible','hidden','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'visible',
  `ip`           varchar(45)  COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `fp_hash`      varchar(64)  COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'SHA-256 browser fingerprint',
  `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at`    datetime     DEFAULT NULL,                                   -- 0.7.6
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


-- ─── BAN LIST ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_ban_list` (
  `id`          int unsigned     NOT NULL AUTO_INCREMENT,
  `ban_type`    enum('fingerprint','ip','email_hash')
                COLLATE utf8mb4_unicode_ci NOT NULL,
  `ban_value`   varchar(255)     COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason`      varchar(500)     COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `banned_at`   datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `banned_by`   int unsigned     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY  `uq_ban`       (`ban_type`, `ban_value`),
  KEY         `idx_type_val` (`ban_type`, `ban_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Centralised ban list: fingerprint, IP, and email-hash bans';

-- ─── STATS ────────────────────────────────────────────────────────────────────

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
  KEY `idx_hit_at`   (`hit_at`),
  KEY `idx_is_bot`   (`is_bot`),
  KEY `idx_image_id` (`image_id`)
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


-- ─── PIMPOTRON ────────────────────────────────────────────────────────────────

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
  `slideshow_font`      varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Stalinist One',
  `is_active`           tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`          datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_pimpotron_slides` (
  `id`                   int unsigned NOT NULL AUTO_INCREMENT,
  `slideshow_id`         int unsigned NOT NULL,
  `slide_type`           varchar(30)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'image',
  `snap_image_id`        int unsigned DEFAULT NULL,
  `external_image_url`   varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_url`            varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `video_autoplay`       tinyint(1)   NOT NULL DEFAULT 0,
  `video_loop`           tinyint(1)   NOT NULL DEFAULT 0,
  `video_muted`          tinyint(1)   NOT NULL DEFAULT 1,
  `bg_color_hex`         char(7)      COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#000000',
  `rain_speed`           int          DEFAULT NULL,
  `rain_density`         int          DEFAULT NULL,
  `rain_color_hex`       char(7)      COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_glitch_enabled` tinyint(1)   NOT NULL DEFAULT 0,
  `overlay_text`         text         COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `text_animation_type`  varchar(30)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'staccato',
  `word_delay_ms`        int          NOT NULL DEFAULT 200,
  `font_color_hex`       char(7)      COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#FFFFFF',
  `pos_x_pct`            int          NOT NULL DEFAULT 50,
  `pos_y_pct`            int          NOT NULL DEFAULT 50,
  `display_duration_ms`  int          DEFAULT NULL,
  `glitch_frequency`     varchar(30)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `glitch_intensity`     varchar(30)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stage_shift_enabled`  tinyint(1)   NOT NULL DEFAULT 0,
  `is_active`            tinyint(1)   NOT NULL DEFAULT 1,
  `sort_order`           int          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_slideshow_id` (`slideshow_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── OH SNAP! API KEYS ───────────────────────────────────────────────────────

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


-- ─── MULTISITE MANAGEMENT ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_multisite_nodes` (
  `id`                  int unsigned   NOT NULL AUTO_INCREMENT,
  `role`                enum('hub','spoke') COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_url`            varchar(500)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_name`           varchar(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_key_local`       varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL
                        COMMENT 'Our key that the remote site uses to call us',
  `api_key_remote`      varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL
                        COMMENT 'Key we use to call the remote site',
  `software_version`    varchar(50)    COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_seen_at`        datetime       DEFAULT NULL,
  `post_count`          int unsigned   DEFAULT 0,
  `image_count`         int unsigned   DEFAULT 0,
  `pending_comments`    int unsigned   DEFAULT 0,
  `last_backup_at`      datetime       DEFAULT NULL,
  `last_backup_size`    bigint unsigned DEFAULT NULL,
  `last_backup_dest`    varchar(100)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_backup_status`  enum('ok','failed','unknown') COLLATE utf8mb4_unicode_ci DEFAULT 'unknown',
  `disk_usage_bytes`    bigint unsigned DEFAULT NULL,
  `status`              enum('active','offline','disconnected') COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `connected_at`        datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_url` (`site_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_multisite_queue` (
  `id`                  int unsigned   NOT NULL AUTO_INCREMENT,
  `node_id`             int unsigned   NOT NULL,
  `action`              varchar(50)    COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload`             text           COLLATE utf8mb4_unicode_ci,
  `status`              enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `attempts`            tinyint unsigned DEFAULT 0,
  `created_at`          datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at`        datetime       DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_node_status` (`node_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
