-- ─────────────────────────────────────────────────────────────────────────────
-- SNAPSMACK — Canonical Schema
-- Alpha v0.7.8 "Jack Be Nimble"
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
  `is_approved`    tinyint(1)   DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `img_id` (`img_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── USERS ────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_users` (
  `id`             int          NOT NULL AUTO_INCREMENT,
  `username`       varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash`  varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_role`      varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'editor',
  `email`          varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferred_skin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'default-dark',
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
  `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at`    datetime     DEFAULT NULL,                                   -- 0.7.6
  PRIMARY KEY (`id`),
  KEY `idx_post_status` (`post_id`, `status`)
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
