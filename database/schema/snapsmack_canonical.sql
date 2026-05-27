-- SNAPSMACK_EOF_HEADER
--     -- ===== SNAPSMACK EOF =====
-- Last non-empty line of this file MUST match the line above.
-- Missing or different = truncated/corrupted. Restore before saving.


-- ─────────────────────────────────────────────────────────────────────────────
-- SNAPSMACK — Canonical Schema
-- Alpha v0.7.9M "Maintenance Mode"
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
  `content`           longtext       COLLATE utf8mb4_unicode_ci DEFAULT NULL
                      COMMENT 'Body content for longform (SmackTalk) posts — migration 041',
  `featured_asset_id` int unsigned   DEFAULT NULL
                      COMMENT 'Hero image for longform posts — FK to snap_assets.id — migration 041',
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
                    COMMENT '1 = visible in public archive, 0 = hidden (added 0.7.9f)',
  `featured_post_id` int unsigned DEFAULT NULL
                    COMMENT 'Hero image source for category gallery views — migration 039',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_albums` (
  `id`                int  NOT NULL AUTO_INCREMENT,
  `album_name`        varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `album_description` text COLLATE utf8mb4_unicode_ci,
  `cover_image_id`    int  DEFAULT NULL
                      COMMENT 'Optional manual cover override — FK to snap_images',
  `featured_post_id`  int unsigned DEFAULT NULL
                      COMMENT 'Hero image source for album gallery views — migration 039',
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
  `ui_mode`              varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bigwheel',
  `recovery_code_hash`   varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `force_password_change` tinyint(1) NOT NULL DEFAULT 0,
  `totp_secret`           varchar(32)  DEFAULT NULL,
  `totp_enabled`          tinyint(1)   NOT NULL DEFAULT 0,
  `totp_recovery_json`    text         DEFAULT NULL,
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
  `source_hub_url` varchar(255)         COLLATE utf8mb4_unicode_ci DEFAULT NULL
                                       COMMENT 'Hub URL that synced this entry — NULL = locally added',
  PRIMARY KEY (`id`),
  KEY `idx_source_hub_url` (`source_hub_url`)
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
  `migration`  varchar(100) NOT NULL,
  `applied_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`migration`)
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

-- ─── SEMANTIC ANALYSIS ────────────────────────────────────────────────────────
-- 0.7.9L: AI semantic fingerprinting for troll detection

CREATE TABLE IF NOT EXISTS `snap_comments_semantic` (
  `id`                int unsigned     NOT NULL AUTO_INCREMENT,
  `comment_id`        int unsigned     NOT NULL,
  `fingerprint_hash`  varchar(255)     COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment_text`      longtext         COLLATE utf8mb4_unicode_ci NOT NULL,
  `tfidf_vector`      json             DEFAULT NULL
                      COMMENT '0.7.9L: TF-IDF vector for cosine similarity analysis',
  `created_at`        timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fingerprint` (`fingerprint_hash`),
  KEY `idx_created`     (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='0.7.9L: Comment text and TF-IDF vectors for semantic duplicate detection';

CREATE TABLE IF NOT EXISTS `snap_keywords` (
  `id`          int unsigned     NOT NULL AUTO_INCREMENT,
  `keyword`     varchar(500)     COLLATE utf8mb4_unicode_ci NOT NULL,
  `match_type`  enum('exact','substring','regex')
                COLLATE utf8mb4_unicode_ci DEFAULT 'substring',
  `severity`    enum('flag','reject')
                COLLATE utf8mb4_unicode_ci DEFAULT 'flag',
  `reason`      varchar(255)     COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `added_at`    timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `added_by`    varchar(100)     COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keyword` (`keyword`),
  KEY `idx_keyword`   (`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='0.7.9L: Banned keywords and phrases for content-based troll blocking';

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
  KEY `idx_image_id` (`image_id`),
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
  `key_type`     varchar(20)    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ohsnap'
                 COMMENT 'ohsnap | smackpress',
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
  `site_tagline`        varchar(500)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `blogroll_desc`       varchar(500)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_key_local`       varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL
                        COMMENT 'Our key that the remote site uses to call us',
  `api_key_remote`      varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL
                        COMMENT 'Key we use to call the remote site',
  `software_version`    varchar(50)    COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_seen_at`        datetime       DEFAULT NULL,
  `ban_sync_cursor`     datetime       DEFAULT NULL              -- v0.7.9O Shield Tier 1
                        COMMENT 'Hub: timestamp of last successful ban sync with this spoke.',
  `post_count`          int unsigned   DEFAULT 0,
  `image_count`         int unsigned   DEFAULT 0,
  `pending_comments`    int unsigned   DEFAULT 0,
  `last_backup_at`      datetime       DEFAULT NULL,
  `last_backup_size`    bigint unsigned DEFAULT NULL,
  `last_backup_dest`    varchar(100)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_backup_status`  enum('ok','failed','unknown') COLLATE utf8mb4_unicode_ci DEFAULT 'unknown',
  `disk_usage_bytes`    bigint unsigned DEFAULT NULL,
  `maintenance_mode`    tinyint(1)     NOT NULL DEFAULT 0
                        COMMENT 'Cached from heartbeat: 1 = spoke is currently in maintenance mode',
  `smackback_status`    varchar(20)    NOT NULL DEFAULT 'unknown'
                        COMMENT 'Cached from heartbeat + push: clean|breach|unknown',
  `smackback_breach_at` datetime       DEFAULT NULL
                        COMMENT 'When breach was first detected on this spoke',
  `smackback_breach_files` mediumtext  COLLATE utf8mb4_unicode_ci DEFAULT NULL
                        COMMENT 'JSON: affected files from last breach report',
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


-- ─── SHIELD TIER 1 — HUB SHARED BAN REGISTRY (v0.7.9O) ──────────────────────

CREATE TABLE IF NOT EXISTS `snap_hub_shared_bans` (
  `id`            int unsigned     NOT NULL AUTO_INCREMENT,
  `ban_type`      enum('fingerprint','ip','email_hash') COLLATE utf8mb4_unicode_ci NOT NULL,
  `ban_value`     char(64)         COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 hex. Never a raw IP or email.',
  `reason`        varchar(64)      COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `reported_by`   varchar(255)     COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'URL of the spoke that first reported this hash.',
  `first_seen`    datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen`     datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `report_count`  int unsigned     NOT NULL DEFAULT 1 COMMENT 'Number of distinct spokes that have reported this hash.',
  `removed`       tinyint(1)       NOT NULL DEFAULT 0 COMMENT '1 = manually cleared by hub admin, excluded from distribution.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_type_val` (`ban_type`, `ban_value`),
  KEY `idx_last_seen` (`last_seen`),
  KEY `idx_removed`   (`removed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Consolidated cross-spoke ban registry (SnapSmack Shield Tier 1)';

-- SMACK THE ENEMY CLIENT --
CREATE TABLE IF NOT EXISTS `snap_ste_scores` (
  `ban_type`     varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL
                 COMMENT 'ip, email, fingerprint',
  `ban_hash`     varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL
                 COMMENT 'SHA-256 hash of the raw value',
  `score`        float        NOT NULL DEFAULT 0,
  `colour_level` varchar(10)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'green'
                 COMMENT 'green, yellow, orange, red, black',
  `last_updated` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ban_type`, `ban_hash`),
  KEY `idx_colour` (`colour_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Local cache of SMACK THE ENEMY network reputation scores';


-- ─── MOSAICS (migration 038) ──────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_mosaics` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(150)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_ids`  LONGTEXT      COLLATE utf8mb4_unicode_ci NOT NULL
               COMMENT 'JSON array of snap_assets IDs in display order',
  `gap`        TINYINT       NOT NULL DEFAULT 4
               COMMENT 'Gap in pixels between mosaic cells (0–20)',
  `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── COLLECTIONS (migration 040) ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_collections` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`           VARCHAR(150)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`            VARCHAR(150)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `description`     TEXT          COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_display` ENUM('browse','slideshow') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'browse',
  `cover_image_id`  INT UNSIGNED  DEFAULT NULL
                    COMMENT 'Hero image — NULL falls back to first collection member',
  `sort_order`      INT           NOT NULL DEFAULT 0,
  `published`       TINYINT       NOT NULL DEFAULT 0
                    COMMENT '0=hidden from public, 1=live',
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_collection_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `collection_id` INT UNSIGNED NOT NULL,
  `image_id`      INT UNSIGNED NOT NULL COMMENT 'FK to snap_images.id',
  `position`      INT          NOT NULL DEFAULT 0,
  `caption`       TEXT         COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Collection-specific caption - overrides post description in collection context',
  `added_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_collection_image` (`collection_id`, `image_id`),
  KEY `idx_collection_position` (`collection_id`, `position`),
  CONSTRAINT `fk_ci_collection`
      FOREIGN KEY (`collection_id`) REFERENCES `snap_collections` (`id`)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── LONGFORM POST PIVOTS (migration 041) ────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_post_cat_map` (
  `post_id` INT UNSIGNED NOT NULL,
  `cat_id`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`post_id`, `cat_id`),
  KEY `idx_pcm_cat` (`cat_id`)
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
  `ui_mode`              varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bigwheel',
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

-- ─── SEMANTIC ANALYSIS ────────────────────────────────────────────────────────
-- 0.7.9L: AI semantic fingerprinting for troll detection

CREATE TABLE IF NOT EXISTS `snap_comments_semantic` (
  `id`                int unsigned     NOT NULL AUTO_INCREMENT,
  `comment_id`        int unsigned     NOT NULL,
  `fingerprint_hash`  varchar(255)     COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment_text`      longtext         COLLATE utf8mb4_unicode_ci NOT NULL,
  `tfidf_vector`      json             DEFAULT NULL
                      COMMENT '0.7.9L: TF-IDF vector for cosine similarity analysis',
  `created_at`        timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fingerprint` (`fingerprint_hash`),
  KEY `idx_created`     (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='0.7.9L: Comment text and TF-IDF vectors for semantic duplicate detection';

CREATE TABLE IF NOT EXISTS `snap_keywords` (
  `id`          int unsigned     NOT NULL AUTO_INCREMENT,
  `keyword`     varchar(500)     COLLATE utf8mb4_unicode_ci NOT NULL,
  `match_type`  enum('exact','substring','regex')
                COLLATE utf8mb4_unicode_ci DEFAULT 'substring',
  `severity`    enum('flag','reject')
                COLLATE utf8mb4_unicode_ci DEFAULT 'flag',
  `reason`      varchar(255)     COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `added_at`    timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `added_by`    varchar(100)     COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keyword` (`keyword`),
  KEY `idx_keyword`   (`keyword`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='0.7.9L: Banned keywords and phrases for content-based troll blocking';

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
  KEY `idx_image_id` (`image_id`),
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
  `key_type`     varchar(20)    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ohsnap'
                 COMMENT 'ohsnap | smackpress',
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
  `site_tagline`        varchar(500)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `blogroll_desc`       varchar(500)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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
  `maintenance_mode`    tinyint(1)     NOT NULL DEFAULT 0
                        COMMENT 'Cached from heartbeat: 1 = spoke is currently in maintenance mode',
  `smackback_status`    varchar(20)    NOT NULL DEFAULT 'unknown'
                        COMMENT 'Cached from heartbeat + push: clean|breach|unknown',
  `smackback_breach_at` datetime       DEFAULT NULL
                        COMMENT 'When breach was first detected on this spoke',
  `smackback_breach_files` mediumtext  COLLATE utf8mb4_unicode_ci DEFAULT NULL
                        COMMENT 'JSON: affected files from last breach report',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicod

-- ─── snap_skin_presets ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `snap_skin_presets` (
  `id`           int            NOT NULL AUTO_INCREMENT,
  `skin_slug`    varchar(80)    NOT NULL,
  `preset_name`  varchar(120)   NOT NULL,
  `preset_data`  json           NOT NULL,
  `created_at`   datetime       DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_skin` (`skin_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── snap_file_manifest ──────────────────────────────────────────────────────
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

-- ─── snap_smackback_log ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `snap_smackback_log` (
  `id`             int unsigned   NOT NULL AUTO_INCREMENT,
  `detected_at`    datetime       NOT NULL,
  `resolved_at`    datetime       DEFAULT NULL,
  `affected_files` text           NOT NULL,
  `file_count`     smallint       NOT NULL DEFAULT '0',
  `resolution`     varchar(64)    DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_totp_devices` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED    NOT NULL,
    `token_hash`  CHAR(64)        NOT NULL COMMENT 'SHA-256 hex of the raw trust token',
    `device_hint` VARCHAR(120)    NOT NULL DEFAULT '' COMMENT 'Browser/OS hint stored at trust time',
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`  DATETIME        NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token_hash` (`token_hash`),
    KEY `idx_user_expires` (`user_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== SNAPSMACK EOF =====
