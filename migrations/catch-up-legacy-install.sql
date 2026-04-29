-- ============================================================
-- SnapSmack — Legacy Install Catch-Up Migration
-- ============================================================
-- For installs that pre-date the migration system (no snap_migrations table).
-- Run once via phpMyAdmin (or any MySQL client) on the affected site.
--
-- What this does:
--   1. Creates snap_migrations (bootstrap) and marks 027–043 as applied
--      so the admin migration runner does not attempt to re-run them.
--   2. Creates all tables added in 0.7.x that this install is missing.
--   3. Adds missing columns to snap_albums, snap_categories, snap_users.
--
-- Requires: MySQL 8.0.3+ or MariaDB 10.0+ (for ADD COLUMN IF NOT EXISTS).
-- Safe to run more than once (all statements are idempotent).
-- ============================================================


-- ============================================================
-- STEP 1 — Bootstrap the migration tracker
-- ============================================================

CREATE TABLE IF NOT EXISTS `snap_migrations` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `migration`  varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `applied_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mark all existing migrations as already applied so the runner skips them.
INSERT IGNORE INTO `snap_migrations` (`migration`, `applied_at`) VALUES
  ('027_multisite_tables',                  NOW()),
  ('028_pages_image_columns',               NOW()),
  ('029_fingerprint_ban_system',            NOW()),
  ('030_categories_show_in_archive',        NOW()),
  ('031_ohsnap_api_keys',                   NOW()),
  ('032_multisite_rename_satellite_to_spoke', NOW()),
  ('033_hub_shared_bans',                   NOW()),
  ('034_ban_sync_spoke_cursor',             NOW()),
  ('035_bigwheel_pimpmobile',               NOW()),
  ('036_ste_client',                        NOW()),
  ('037_totp_2fa',                          NOW()),
  ('038_mosaics',                           NOW()),
  ('039_featured_images',                   NOW()),
  ('040_collections',                       NOW()),
  ('041_longform_post_type',                NOW()),
  ('042_semantic_analysis_tables',          NOW()),
  ('043_enable_longform',                   NOW());


-- ============================================================
-- STEP 2 — Missing tables (alphabetical within groups)
-- ============================================================

-- ── Ban & moderation ──────────────────────────────────────────────────────────

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `snap_ste_scores` (
  `ban_type`     varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `ban_hash`     varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `score`        float        NOT NULL DEFAULT 0,
  `colour_level` varchar(10)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'green',
  `last_updated` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ban_type`, `ban_hash`),
  KEY `idx_colour` (`colour_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Blogroll ──────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_blogroll_cats` (
  `id`       int          NOT NULL AUTO_INCREMENT,
  `cat_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Collections ───────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_collections` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`             VARCHAR(150)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`             VARCHAR(150)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `description`      TEXT          COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `featured_post_id` INT UNSIGNED  DEFAULT NULL,
  `sort_order`       INT           NOT NULL DEFAULT 0,
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_collection_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `collection_id` INT UNSIGNED NOT NULL,
  `item_type`     ENUM('post','album','category') COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id`       INT UNSIGNED NOT NULL,
  `sort_order`    INT          NOT NULL DEFAULT 0,
  `added_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_collection_item` (`collection_id`, `item_type`, `item_id`),
  KEY `idx_collection` (`collection_id`),
  CONSTRAINT `fk_ci_collection`
      FOREIGN KEY (`collection_id`) REFERENCES `snap_collections` (`id`)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Community ─────────────────────────────────────────────────────────────────

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

CREATE TABLE IF NOT EXISTS `snap_community_comments` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `post_id`      int unsigned NOT NULL,
  `user_id`      int unsigned NULL DEFAULT NULL,
  `guest_name`   varchar(100) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `guest_email`  varchar(200) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
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

-- ── Engagement ────────────────────────────────────────────────────────────────

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

-- ── Multisite ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_multisite_nodes` (
  `id`                  int unsigned   NOT NULL AUTO_INCREMENT,
  `role`                enum('hub','spoke') COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_url`            varchar(500)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_name`           varchar(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_key_local`       varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_key_remote`      varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `software_version`    varchar(50)    COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_seen_at`        datetime       DEFAULT NULL,
  `ban_sync_cursor`     datetime       DEFAULT NULL,
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
  `id`           int unsigned   NOT NULL AUTO_INCREMENT,
  `node_id`      int unsigned   NOT NULL,
  `action`       varchar(50)    COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload`      text           COLLATE utf8mb4_unicode_ci,
  `status`       enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `attempts`     tinyint unsigned DEFAULT 0,
  `created_at`   datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` datetime       DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_node_status` (`node_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_hub_shared_bans` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `ban_type`     enum('fingerprint','ip','email_hash') COLLATE utf8mb4_unicode_ci NOT NULL,
  `ban_value`    char(64)     COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason`       varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `reported_by`  varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `first_seen`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `report_count` int unsigned NOT NULL DEFAULT 1,
  `removed`      tinyint(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_type_val` (`ban_type`, `ban_value`),
  KEY `idx_last_seen` (`last_seen`),
  KEY `idx_removed`   (`removed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Oh Snap! API ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_ohsnap_keys` (
  `id`           int          NOT NULL AUTO_INCREMENT,
  `label`        varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_hash`     varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `key_prefix`   varchar(8)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active`    tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key_hash` (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Password resets ───────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_password_resets` (
  `id`     int          NOT NULL AUTO_INCREMENT,
  `email`  varchar(255) NOT NULL,
  `token`  varchar(64)  NOT NULL,
  `expiry` datetime     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_password_resets_email` (`email`),
  KEY `idx_password_resets_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Pimpotron (slideshows) ────────────────────────────────────────────────────

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

-- ── Posts & related ───────────────────────────────────────────────────────────

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
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_post_type` (`post_type`)
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

CREATE TABLE IF NOT EXISTS `snap_post_cat_map` (
  `post_id` INT UNSIGNED NOT NULL,
  `cat_id`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`post_id`, `cat_id`),
  KEY `idx_pcm_cat` (`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_post_album_map` (
  `post_id`  INT UNSIGNED NOT NULL,
  `album_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`post_id`, `album_id`),
  KEY `idx_pam_album` (`album_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Semantic analysis ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_comments_semantic` (
  `id`               int unsigned NOT NULL AUTO_INCREMENT,
  `comment_id`       int unsigned NOT NULL,
  `fingerprint_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment_text`     longtext     COLLATE utf8mb4_unicode_ci NOT NULL,
  `tfidf_vector`     json         DEFAULT NULL,
  `created_at`       timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fingerprint` (`fingerprint_hash`),
  KEY `idx_created`     (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Stats ─────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_stats` (
  `id`            int unsigned NOT NULL AUTO_INCREMENT,
  `hit_at`        datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `page_type`     varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `page_slug`     varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_id`      int unsigned DEFAULT NULL,
  `referrer`      varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referrer_host` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent`    varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `browser`       varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `os`            varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country`       varchar(10)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_hash`       varchar(64)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_bot`        tinyint(1)   NOT NULL DEFAULT 0,
  `search_term`   varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
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

-- ── Tags ──────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_tags` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `tag`          varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`         varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `use_count`    int unsigned DEFAULT 0,
  `color_family` varchar(20)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at`   timestamp    DEFAULT CURRENT_TIMESTAMP,
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

-- ── Mosaics ───────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_mosaics` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Untitled Mosaic',
  `asset_ids`  LONGTEXT     COLLATE utf8mb4_unicode_ci NOT NULL,
  `gap`        TINYINT      NOT NULL DEFAULT 4,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- STEP 3 — Missing columns on existing tables
-- ============================================================
-- Requires MySQL 8.0.3+ or MariaDB 10.0+.
-- If your server is older, add these three columns manually.

ALTER TABLE `snap_albums`
  ADD COLUMN IF NOT EXISTS `featured_post_id` int unsigned DEFAULT NULL
    COMMENT 'Hero image source for album gallery views — migration 039';

ALTER TABLE `snap_categories`
  ADD COLUMN IF NOT EXISTS `featured_post_id` int unsigned DEFAULT NULL
    COMMENT 'Hero image source for category gallery views — migration 039';

ALTER TABLE `snap_users`
  ADD COLUMN IF NOT EXISTS `totp_secret`        varchar(32) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `totp_enabled`        tinyint(1)  NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `totp_recovery_json`  text        DEFAULT NULL;


-- ============================================================
-- Done.
-- ============================================================
