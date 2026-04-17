<?php
/**
 * SNAPSMACK - Schema Sync Engine
 *
 * Declares the canonical database schema for the current version and applies
 * any missing tables or columns against a live database. All operations are
 * idempotent — safe to run on any install at any version, as many times as
 * needed.
 *
 * CREATE TABLE IF NOT EXISTS handles tables missing entirely (fresh installs
 * or features added in later versions). INFORMATION_SCHEMA column checks
 * handle columns added to existing tables in point releases.
 *
 * This replaces the fragile migration-chain approach for schema structure.
 * Versioned migration files in /migrations/ should only contain data changes:
 * seed rows, value transforms, or renames that cannot be expressed as
 * idempotent column additions.
 *
 * Usage:
 *   require_once 'core/schema-sync.php';
 *   $report = snap_schema_sync($pdo);
 *   // $report['created']       — tables created
 *   // $report['columns_added'] — columns added
 *   // $report['skipped']       — already-present items (no-op)
 *   // $report['errors']        — failures with message
 */

/**
 * Apply the canonical schema to the connected database.
 * Returns a report array (see file header).
 */
function snap_schema_sync(PDO $pdo): array {

    $report = [
        'created'       => [],
        'columns_added' => [],
        'skipped'       => [],
        'errors'        => [],
    ];

    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();

    // ─────────────────────────────────────────────────────────────────────────
    // 1. TABLES — CREATE IF NOT EXISTS
    //    Each statement includes the full current column set so that fresh
    //    installs get everything in one pass. Existing tables are untouched.
    // ─────────────────────────────────────────────────────────────────────────

    $create_tables = [

        'snap_images' => "CREATE TABLE IF NOT EXISTS `snap_images` (
          `id`                  int            NOT NULL AUTO_INCREMENT,
          `img_title`           varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
          `img_slug`            varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
          `img_description`     text           COLLATE utf8mb4_unicode_ci,
          `img_film`            varchar(100)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `img_date`            datetime       NOT NULL,
          `img_file`            varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
          `img_source_file`     varchar(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Original filename on the posting machine at upload time',
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
          `img_thumb_square`    varchar(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `img_thumb_aspect`    varchar(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `img_checksum`        varchar(64)    COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `img_display_options` text           COLLATE utf8mb4_unicode_ci,
          `post_id`             int            DEFAULT NULL COMMENT 'FK to snap_posts — populated when image is wrapped in a post',
          `sort_order`          int            NOT NULL DEFAULT '0' COMMENT 'Manual display order. Lower = earlier in feed. 0 = unset (falls back to img_date DESC).',
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_posts' => "CREATE TABLE IF NOT EXISTS `snap_posts` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_post_images' => "CREATE TABLE IF NOT EXISTS `snap_post_images` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_categories' => "CREATE TABLE IF NOT EXISTS `snap_categories` (
          `id`              int   NOT NULL AUTO_INCREMENT,
          `cat_name`        varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `cat_slug`        varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `cat_description` text  COLLATE utf8mb4_unicode_ci,
          `cover_image_id`  int   DEFAULT NULL,
          `show_in_archive` tinyint(1) NOT NULL DEFAULT 1
                            COMMENT '1 = visible in public archive; 0 = hidden',
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_albums' => "CREATE TABLE IF NOT EXISTS `snap_albums` (
          `id`                int  NOT NULL AUTO_INCREMENT,
          `album_name`        varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `album_description` text COLLATE utf8mb4_unicode_ci,
          `cover_image_id`    int  DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_image_cat_map' => "CREATE TABLE IF NOT EXISTS `snap_image_cat_map` (
          `image_id` int NOT NULL,
          `cat_id`   int NOT NULL,
          PRIMARY KEY (`image_id`, `cat_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_image_album_map' => "CREATE TABLE IF NOT EXISTS `snap_image_album_map` (
          `image_id` int NOT NULL,
          `album_id` int NOT NULL,
          PRIMARY KEY (`image_id`, `album_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_post_cat_map' => "CREATE TABLE IF NOT EXISTS `snap_post_cat_map` (
          `post_id` int NOT NULL,
          `cat_id`  int NOT NULL,
          PRIMARY KEY (`post_id`, `cat_id`),
          KEY `idx_cat_id` (`cat_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_post_album_map' => "CREATE TABLE IF NOT EXISTS `snap_post_album_map` (
          `post_id`  int NOT NULL,
          `album_id` int NOT NULL,
          PRIMARY KEY (`post_id`, `album_id`),
          KEY `idx_album_id` (`album_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_tags' => "CREATE TABLE IF NOT EXISTS `snap_tags` (
          `id`           int unsigned  NOT NULL AUTO_INCREMENT,
          `tag`          varchar(100)  COLLATE utf8mb4_unicode_ci NOT NULL,
          `slug`         varchar(100)  COLLATE utf8mb4_unicode_ci NOT NULL,
          `use_count`    int unsigned  DEFAULT 0,
          `color_family` varchar(20)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `created_at`   timestamp     DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_tag_slug` (`slug`),
          KEY `idx_tags_color_family` (`color_family`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_image_tags' => "CREATE TABLE IF NOT EXISTS `snap_image_tags` (
          `id`         int unsigned NOT NULL AUTO_INCREMENT,
          `image_id`   int unsigned NOT NULL,
          `tag_id`     int unsigned NOT NULL,
          `created_at` timestamp    DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_image_tag` (`image_id`, `tag_id`),
          KEY `idx_image_tags_image_id` (`image_id`),
          KEY `idx_image_tags_tag_id`   (`tag_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_pages' => "CREATE TABLE IF NOT EXISTS `snap_pages` (
          `id`           int          NOT NULL AUTO_INCREMENT,
          `slug`         varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `title`        varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `content`      longtext     COLLATE utf8mb4_unicode_ci,
          `image_asset`  varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
          `image_size`   varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full',
          `image_align`  varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'center',
          `image_shadow` tinyint(1)   NOT NULL DEFAULT 0,
          `is_active`    tinyint(1)   DEFAULT '1',
          `menu_order`   int          DEFAULT '0',
          `created_at`   timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_settings' => "CREATE TABLE IF NOT EXISTS `snap_settings` (
          `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          `setting_val` text         COLLATE utf8mb4_unicode_ci,
          PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_comments' => "CREATE TABLE IF NOT EXISTS `snap_comments` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_users' => "CREATE TABLE IF NOT EXISTS `snap_users` (
          `id`                    int          NOT NULL AUTO_INCREMENT,
          `username`              varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
          `password_hash`         varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `user_role`             varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'editor',
          `email`                 varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `preferred_skin`        varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'default-dark',
          `recovery_code_hash`    varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `force_password_change` tinyint(1)   NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          UNIQUE KEY `username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_blogroll_cats' => "CREATE TABLE IF NOT EXISTS `snap_blogroll_cats` (
          `id`       int          NOT NULL AUTO_INCREMENT,
          `cat_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_blogroll' => "CREATE TABLE IF NOT EXISTS `snap_blogroll` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_assets' => "CREATE TABLE IF NOT EXISTS `snap_assets` (
          `id`             int          NOT NULL AUTO_INCREMENT,
          `asset_name`     varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
          `asset_path`     varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
          `asset_checksum` varchar(64)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `created_at`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_migrations' => "CREATE TABLE IF NOT EXISTS `snap_migrations` (
          `id`         int unsigned NOT NULL AUTO_INCREMENT,
          `migration`  varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
          `applied_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_password_resets' => "CREATE TABLE IF NOT EXISTS `snap_password_resets` (
          `id`     int          NOT NULL AUTO_INCREMENT,
          `email`  varchar(255) NOT NULL,
          `token`  varchar(64)  NOT NULL,
          `expiry` datetime     NOT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_password_resets_email` (`email`),
          KEY `idx_password_resets_token` (`token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_rate_limits' => "CREATE TABLE IF NOT EXISTS `snap_rate_limits` (
          `id`           int unsigned NOT NULL AUTO_INCREMENT,
          `ip`           varchar(45)  COLLATE utf8mb4_unicode_ci NOT NULL,
          `action`       varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
          `count`        int unsigned NOT NULL DEFAULT 1,
          `window_start` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_ip_action` (`ip`, `action`),
          KEY `idx_window` (`window_start`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_community_users' => "CREATE TABLE IF NOT EXISTS `snap_community_users` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_community_sessions' => "CREATE TABLE IF NOT EXISTS `snap_community_sessions` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_community_tokens' => "CREATE TABLE IF NOT EXISTS `snap_community_tokens` (
          `id`         int unsigned NOT NULL AUTO_INCREMENT,
          `user_id`    int unsigned NOT NULL,
          `token`      varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL,
          `type`       varchar(30)  COLLATE utf8mb4_unicode_ci NOT NULL,
          `expires_at` datetime     NOT NULL,
          `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_token` (`token`),
          KEY `idx_user_type` (`user_id`, `type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_community_comments' => "CREATE TABLE IF NOT EXISTS `snap_community_comments` (
          `id`           int unsigned NOT NULL AUTO_INCREMENT,
          `post_id`      int unsigned NOT NULL,
          `user_id`      int unsigned NULL DEFAULT NULL,
          `guest_name`   varchar(100) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
          `guest_email`  varchar(200) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
          `comment_text` text         COLLATE utf8mb4_unicode_ci NOT NULL,
          `status`       enum('visible','hidden','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'visible',
          `ip`           varchar(45)  COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
          `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `edited_at`    datetime     DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `idx_post_status` (`post_id`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_likes' => "CREATE TABLE IF NOT EXISTS `snap_likes` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_reactions' => "CREATE TABLE IF NOT EXISTS `snap_reactions` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_ban_list' => "CREATE TABLE IF NOT EXISTS `snap_ban_list` (
          `id`          int unsigned     NOT NULL AUTO_INCREMENT,
          `ban_type`    enum('fingerprint','ip','email_hash') COLLATE utf8mb4_unicode_ci NOT NULL,
          `ban_value`   varchar(255)     COLLATE utf8mb4_unicode_ci NOT NULL,
          `reason`      varchar(500)     COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `banned_at`   datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `banned_by`   int unsigned     DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY  `uq_ban`       (`ban_type`, `ban_value`),
          KEY         `idx_type_val` (`ban_type`, `ban_value`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_comments_semantic' => "CREATE TABLE IF NOT EXISTS `snap_comments_semantic` (
          `id`                int unsigned     NOT NULL AUTO_INCREMENT,
          `comment_id`        int unsigned     NOT NULL,
          `fingerprint_hash`  varchar(255)     COLLATE utf8mb4_unicode_ci NOT NULL,
          `comment_text`      longtext         COLLATE utf8mb4_unicode_ci NOT NULL,
          `tfidf_vector`      json             DEFAULT NULL,
          `created_at`        timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_fingerprint` (`fingerprint_hash`),
          KEY `idx_created`     (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_keywords' => "CREATE TABLE IF NOT EXISTS `snap_keywords` (
          `id`          int unsigned     NOT NULL AUTO_INCREMENT,
          `keyword`     varchar(500)     COLLATE utf8mb4_unicode_ci NOT NULL,
          `match_type`  enum('exact','substring','regex') COLLATE utf8mb4_unicode_ci DEFAULT 'substring',
          `severity`    enum('flag','reject') COLLATE utf8mb4_unicode_ci DEFAULT 'flag',
          `reason`      varchar(255)     COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          `added_at`    timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `added_by`    varchar(100)     COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_keyword` (`keyword`),
          KEY `idx_keyword`   (`keyword`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_stats' => "CREATE TABLE IF NOT EXISTS `snap_stats` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_stats_daily' => "CREATE TABLE IF NOT EXISTS `snap_stats_daily` (
          `id`              int unsigned NOT NULL AUTO_INCREMENT,
          `stat_date`       date         NOT NULL,
          `total_views`     int unsigned NOT NULL DEFAULT 0,
          `unique_visitors` int unsigned NOT NULL DEFAULT 0,
          `bot_views`       int unsigned NOT NULL DEFAULT 0,
          `top_image_id`    int unsigned DEFAULT NULL,
          `top_referrer`    varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_stat_date` (`stat_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_pimpotron_slideshows' => "CREATE TABLE IF NOT EXISTS `snap_pimpotron_slideshows` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_pimpotron_slides' => "CREATE TABLE IF NOT EXISTS `snap_pimpotron_slides` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_ohsnap_keys' => "CREATE TABLE IF NOT EXISTS `snap_ohsnap_keys` (
          `id`           int            NOT NULL AUTO_INCREMENT,
          `label`        varchar(100)   COLLATE utf8mb4_unicode_ci NOT NULL,
          `key_hash`     varchar(64)    COLLATE utf8mb4_unicode_ci NOT NULL
                         COMMENT 'SHA-256 hex digest of the raw key',
          `key_prefix`   varchar(8)     COLLATE utf8mb4_unicode_ci NOT NULL
                         COMMENT 'First 8 chars of raw key for UI display',
          `is_active`    tinyint(1)     NOT NULL DEFAULT 1,
          `created_at`   datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `last_used_at` datetime       DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_key_hash` (`key_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_multisite_nodes' => "CREATE TABLE IF NOT EXISTS `snap_multisite_nodes` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'snap_multisite_queue' => "CREATE TABLE IF NOT EXISTS `snap_multisite_queue` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    ];

    foreach ($create_tables as $table => $ddl) {
        try {
            $ps = $pdo->query($ddl);
            if ($ps !== false) $ps->closeCursor();

            // Check whether it existed already by seeing if we actually created it
            $exists_before = (int) $pdo->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
            )->fetchColumn();

            // We can't easily tell "created vs already existed" post-hoc with IF NOT EXISTS,
            // so just report it as verified.
            $report['skipped'][] = $table . ' (verified)';

        } catch (\PDOException $e) {
            $report['errors'][] = "CREATE {$table}: " . $e->getMessage();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. COLUMN ADDITIONS — idempotent via INFORMATION_SCHEMA
    //    Each entry: [table, column, ALTER TABLE ... ADD COLUMN ... DDL]
    //    Only runs the ALTER if INFORMATION_SCHEMA confirms the column absent.
    // ─────────────────────────────────────────────────────────────────────────

    $column_additions = [

        // 0.7.4 / 0.7.6 — snap_tags
        ['snap_tags', 'color_family',
            "ALTER TABLE `snap_tags`
             ADD COLUMN `color_family` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `use_count`"],

        // 0.7.6 — snap_community_comments
        ['snap_community_comments', 'guest_name',
            "ALTER TABLE `snap_community_comments`
             ADD COLUMN `guest_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `user_id`"],

        ['snap_community_comments', 'guest_email',
            "ALTER TABLE `snap_community_comments`
             ADD COLUMN `guest_email` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `guest_name`"],

        ['snap_community_comments', 'edited_at',
            "ALTER TABLE `snap_community_comments`
             ADD COLUMN `edited_at` datetime DEFAULT NULL AFTER `created_at`"],

        // 0.7.7 — snap_images
        ['snap_images', 'img_source_file',
            "ALTER TABLE `snap_images`
             ADD COLUMN `img_source_file` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
             COMMENT 'Original filename on the posting machine at upload time' AFTER `img_file`"],

        ['snap_images', 'sort_order',
            "ALTER TABLE `snap_images`
             ADD COLUMN `sort_order` int NOT NULL DEFAULT 0
             COMMENT 'Manual display order. Lower = earlier in feed. 0 = unset (falls back to img_date DESC).' AFTER `post_id`"],

        // 0.7.8b — snap_users (recovery code + forced password change)
        ['snap_users', 'recovery_code_hash',
            "ALTER TABLE `snap_users`
             ADD COLUMN `recovery_code_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `preferred_skin`"],

        ['snap_users', 'force_password_change',
            "ALTER TABLE `snap_users`
             ADD COLUMN `force_password_change` tinyint(1) NOT NULL DEFAULT 0 AFTER `recovery_code_hash`"],

        // 0.7.8 — snap_pages
        ['snap_pages', 'image_size',
            "ALTER TABLE `snap_pages`
             ADD COLUMN `image_size` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full' AFTER `image_asset`"],

        ['snap_pages', 'image_align',
            "ALTER TABLE `snap_pages`
             ADD COLUMN `image_align` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'center' AFTER `image_size`"],

        ['snap_pages', 'image_shadow',
            "ALTER TABLE `snap_pages`
             ADD COLUMN `image_shadow` tinyint(1) NOT NULL DEFAULT 0 AFTER `image_align`"],

        // 0.7.9f — snap_categories (archive visibility toggle)
        ['snap_categories', 'show_in_archive',
            "ALTER TABLE `snap_categories`
             ADD COLUMN `show_in_archive` tinyint(1) NOT NULL DEFAULT 1
             COMMENT '1 = visible in public archive; 0 = hidden'"],

    ];

    $col_check = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = ?"
    );

    foreach ($column_additions as [$table, $column, $ddl]) {
        try {
            $col_check->execute([$table, $column]);
            $exists = (int) $col_check->fetchColumn();

            if ($exists) {
                $report['skipped'][] = "{$table}.{$column}";
                continue;
            }

            $ps = $pdo->query($ddl);
            if ($ps !== false) $ps->closeCursor();
            $report['columns_added'][] = "{$table}.{$column}";

        } catch (\PDOException $e) {
            $report['errors'][] = "ALTER {$table}.{$column}: " . $e->getMessage();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. INDEX ADDITIONS — idempotent via INFORMATION_SCHEMA.STATISTICS
    // ─────────────────────────────────────────────────────────────────────────

    $index_additions = [
        ['snap_tags', 'idx_tags_color_family',
            "ALTER TABLE `snap_tags` ADD INDEX `idx_tags_color_family` (`color_family`)"],
    ];

    $idx_check = $pdo->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND INDEX_NAME   = ?"
    );

    foreach ($index_additions as [$table, $index, $ddl]) {
        try {
            $idx_check->execute([$table, $index]);
            $exists = (int) $idx_check->fetchColumn();

            if ($exists) {
                $report['skipped'][] = "INDEX {$table}.{$index}";
                continue;
            }

            $ps = $pdo->query($ddl);
            if ($ps !== false) $ps->closeCursor();
            $report['columns_added'][] = "INDEX {$table}.{$index}";

        } catch (\PDOException $e) {
            $report['errors'][] = "ADD INDEX {$table}.{$index}: " . $e->getMessage();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. ENUM REPAIRS — fix enum columns on existing tables
    //    CREATE TABLE IF NOT EXISTS cannot update an existing column type.
    //    This section detects stale enum values and ALTERs them to match the
    //    canonical schema. Each entry checks COLUMN_TYPE before acting.
    // ─────────────────────────────────────────────────────────────────────────

    $enum_repairs = [

        // Migration 032: satellite → spoke rename. If the table existed before
        // that migration, the enum may still say ('hub','satellite').
        [
            'table'    => 'snap_multisite_nodes',
            'column'   => 'role',
            'bad'      => "'satellite'",                     // substring present in stale enum
            'good'     => "'spoke'",                         // substring expected in repaired enum
            'alter'    => "ALTER TABLE `snap_multisite_nodes`
                           MODIFY COLUMN `role` enum('hub','satellite','spoke') COLLATE utf8mb4_unicode_ci NOT NULL",
            'update'   => "UPDATE `snap_multisite_nodes` SET `role` = 'spoke' WHERE `role` = 'satellite'",
            'fix_blank'=> "UPDATE `snap_multisite_nodes` SET `role` = 'spoke' WHERE `role` = '' AND `site_url` != ''",
            'finalise' => "ALTER TABLE `snap_multisite_nodes`
                           MODIFY COLUMN `role` enum('hub','spoke') COLLATE utf8mb4_unicode_ci NOT NULL",
        ],

    ];

    $col_type_check = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = ?
           AND COLUMN_NAME  = ?"
    );

    foreach ($enum_repairs as $repair) {
        try {
            $col_type_check->execute([$repair['table'], $repair['column']]);
            $col_type = $col_type_check->fetchColumn();

            if ($col_type === false) {
                // Table or column doesn't exist yet — nothing to repair.
                $report['skipped'][] = "ENUM {$repair['table']}.{$repair['column']} (column absent)";
                continue;
            }

            if (str_contains($col_type, $repair['good']) && !str_contains($col_type, $repair['bad'])) {
                // Already correct.
                $report['skipped'][] = "ENUM {$repair['table']}.{$repair['column']}";
                continue;
            }

            // Widen enum to include both old and new values
            $pdo->exec($repair['alter']);

            // Migrate rows from old value to new
            $changed = $pdo->exec($repair['update']);
            $report['columns_added'][] = "ENUM {$repair['table']}.{$repair['column']}: migrated {$changed} row(s)";

            // Fix any blank rows left by MySQL silent-fail inserts
            if (!empty($repair['fix_blank'])) {
                $blank_fixed = $pdo->exec($repair['fix_blank']);
                if ($blank_fixed > 0) {
                    $report['columns_added'][] = "ENUM {$repair['table']}.{$repair['column']}: fixed {$blank_fixed} blank row(s)";
                }
            }

            // Shrink enum to canonical set
            $pdo->exec($repair['finalise']);

        } catch (\PDOException $e) {
            $report['errors'][] = "ENUM REPAIR {$repair['table']}.{$repair['column']}: " . $e->getMessage();
        }
    }

    return $report;
}
