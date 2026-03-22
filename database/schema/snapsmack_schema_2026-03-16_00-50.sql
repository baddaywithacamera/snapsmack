-- SnapSmack Reference Schema
-- Version: Alpha v0.7.4b "La-Z-Boy"
-- Generated: 2026-03-16
--
-- This is the canonical schema for the SnapSmack application.
-- All tables, columns, keys, and indexes are defined here.
-- Keep this file in sync with install.php and any new migrations.

-- =====================================================================
-- PHOTO IMAGES
-- =====================================================================

DROP TABLE IF EXISTS `snap_images`;
CREATE TABLE `snap_images` (
  `id` int NOT NULL AUTO_INCREMENT,
  `img_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `img_slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `img_description` text COLLATE utf8mb4_unicode_ci,
  `img_film` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `img_date` datetime NOT NULL,
  `img_file` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `img_exif` text COLLATE utf8mb4_unicode_ci,
  `img_download_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `img_download_count` int unsigned NOT NULL DEFAULT '0',
  `img_width` int DEFAULT '0',
  `img_height` int DEFAULT '0',
  `img_status` enum('published','draft') COLLATE utf8mb4_unicode_ci DEFAULT 'published',
  `img_orientation` int DEFAULT '0',
  `allow_comments` tinyint(1) DEFAULT '1',
  `allow_download` tinyint(1) NOT NULL DEFAULT '1',
  `download_url` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `img_thumb_square` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Relative path to 400x400 square thumbnail (t_ prefix)',
  `img_thumb_aspect` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Relative path to aspect-ratio thumbnail (a_ prefix)',
  `img_checksum` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SHA-256 hash of main image file for recovery verification',
  `img_display_options` text COLLATE utf8mb4_unicode_ci COMMENT 'JSON: per-image frame/mat/bevel overrides and extracted colour palette',
  `post_id` int DEFAULT NULL COMMENT 'FK to snap_posts — populated when image is wrapped in a post',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- POSTS (container layer wrapping one or more images)
-- =====================================================================

DROP TABLE IF EXISTS `snap_posts`;
CREATE TABLE `snap_posts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `post_type` enum('single','carousel','panorama') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'published',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `allow_comments` tinyint(1) NOT NULL DEFAULT 1,
  `allow_download` tinyint(1) NOT NULL DEFAULT 0,
  `download_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `download_count` int NOT NULL DEFAULT 0,
  `panorama_rows` tinyint NOT NULL DEFAULT 1,
  `import_source` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `import_id` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `post_img_size_pct` tinyint unsigned NOT NULL DEFAULT 100,
  `post_border_px` tinyint unsigned NOT NULL DEFAULT 0,
  `post_border_color` char(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#000000',
  `post_bg_color` char(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#ffffff',
  `post_shadow` tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`),
  UNIQUE KEY `uq_import` (`import_source`, `import_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_post_type` (`post_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- POST-TO-IMAGE MAP
-- =====================================================================

DROP TABLE IF EXISTS `snap_post_images`;
CREATE TABLE `snap_post_images` (
  `id` int NOT NULL AUTO_INCREMENT,
  `post_id` int NOT NULL,
  `image_id` int NOT NULL,
  `sort_position` smallint NOT NULL DEFAULT 0,
  `is_cover` tinyint(1) NOT NULL DEFAULT 0,
  `grid_col` tinyint DEFAULT NULL,
  `grid_row` tinyint DEFAULT NULL,
  `img_size_pct` tinyint unsigned NOT NULL DEFAULT 100,
  `img_border_px` tinyint unsigned NOT NULL DEFAULT 0,
  `img_border_color` char(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#000000',
  `img_bg_color` char(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#ffffff',
  `img_shadow` tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_image` (`image_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_sort` (`post_id`, `sort_position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- CATEGORIES
-- =====================================================================

DROP TABLE IF EXISTS `snap_categories`;
CREATE TABLE `snap_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cat_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cat_slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cat_description` text COLLATE utf8mb4_unicode_ci,
  `cover_image_id` int DEFAULT NULL COMMENT 'Optional manual cover override — FK to snap_images',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- ALBUMS
-- =====================================================================

DROP TABLE IF EXISTS `snap_albums`;
CREATE TABLE `snap_albums` (
  `id` int NOT NULL AUTO_INCREMENT,
  `album_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `album_description` text COLLATE utf8mb4_unicode_ci,
  `cover_image_id` int DEFAULT NULL COMMENT 'Optional manual cover override — FK to snap_images',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- MAPPING TABLES (image/post to category/album)
-- =====================================================================

DROP TABLE IF EXISTS `snap_image_cat_map`;
CREATE TABLE `snap_image_cat_map` (
  `image_id` int NOT NULL,
  `cat_id` int NOT NULL,
  PRIMARY KEY (`image_id`, `cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `snap_image_album_map`;
CREATE TABLE `snap_image_album_map` (
  `image_id` int NOT NULL,
  `album_id` int NOT NULL,
  PRIMARY KEY (`image_id`, `album_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `snap_post_cat_map`;
CREATE TABLE `snap_post_cat_map` (
  `post_id` int NOT NULL,
  `cat_id` int NOT NULL,
  PRIMARY KEY (`post_id`, `cat_id`),
  KEY `idx_cat_id` (`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `snap_post_album_map`;
CREATE TABLE `snap_post_album_map` (
  `post_id` int NOT NULL,
  `album_id` int NOT NULL,
  PRIMARY KEY (`post_id`, `album_id`),
  KEY `idx_album_id` (`album_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- HASHTAGS
-- =====================================================================

DROP TABLE IF EXISTS `snap_tags`;
CREATE TABLE `snap_tags` (
  `id` int unsigned AUTO_INCREMENT,
  `tag` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `use_count` int unsigned DEFAULT 0,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `snap_image_tags`;
CREATE TABLE `snap_image_tags` (
  `id` int unsigned AUTO_INCREMENT,
  `image_id` int unsigned NOT NULL,
  `tag_id` int unsigned NOT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_image_tag` (`image_id`, `tag_id`),
  KEY `idx_tag_id` (`tag_id`),
  KEY `idx_image_id` (`image_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- STATIC PAGES
-- =====================================================================

DROP TABLE IF EXISTS `snap_pages`;
CREATE TABLE `snap_pages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `image_asset` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `is_active` tinyint(1) DEFAULT '1',
  `menu_order` int DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- SETTINGS (KEY-VALUE STORE)
-- =====================================================================

DROP TABLE IF EXISTS `snap_settings`;
CREATE TABLE `snap_settings` (
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_val` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- LEGACY COMMENTS (pre-community system)
-- =====================================================================

DROP TABLE IF EXISTS `snap_comments`;
CREATE TABLE `snap_comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `img_id` int NOT NULL,
  `comment_author` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment_email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment_text` text COLLATE utf8mb4_unicode_ci,
  `comment_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `comment_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_approved` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `img_id` (`img_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- ADMIN USERS
-- =====================================================================

DROP TABLE IF EXISTS `snap_users`;
CREATE TABLE `snap_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_role` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'editor',
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferred_skin` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'default-dark',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- BLOGROLL
-- =====================================================================

DROP TABLE IF EXISTS `snap_blogroll_cats`;
CREATE TABLE `snap_blogroll_cats` (
  `id`       int         NOT NULL AUTO_INCREMENT,
  `cat_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `snap_blogroll`;
CREATE TABLE `snap_blogroll` (
  `id` int NOT NULL AUTO_INCREMENT,
  `peer_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `peer_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cat_id` int DEFAULT NULL,
  `peer_rss` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `peer_desc` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int NOT NULL DEFAULT '0',
  `rss_last_fetched` datetime DEFAULT NULL,
  `rss_last_updated` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- MEDIA ASSETS
-- =====================================================================

DROP TABLE IF EXISTS `snap_assets`;
CREATE TABLE `snap_assets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asset_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_checksum` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SHA-256 hash for recovery verification',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- MIGRATION TRACKING
-- =====================================================================

DROP TABLE IF EXISTS `snap_migrations`;
CREATE TABLE `snap_migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- RATE LIMITER
-- =====================================================================

DROP TABLE IF EXISTS `snap_rate_limits`;
CREATE TABLE `snap_rate_limits` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `count` int unsigned NOT NULL DEFAULT 1,
  `window_start` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ip_action` (`ip`, `action`),
  KEY `idx_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- COMMUNITY USERS
-- =====================================================================

DROP TABLE IF EXISTS `snap_community_users`;
CREATE TABLE `snap_community_users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `avatar_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','unverified','suspended') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unverified',
  `email_verified` tinyint(1) NOT NULL DEFAULT 0,
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_email` (`email`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- COMMUNITY SESSIONS
-- =====================================================================

DROP TABLE IF EXISTS `snap_community_sessions`;
CREATE TABLE `snap_community_sessions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- COMMUNITY TOKENS (email verify / password reset)
-- =====================================================================

DROP TABLE IF EXISTS `snap_community_tokens`;
CREATE TABLE `snap_community_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`),
  KEY `idx_user_type` (`user_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- COMMUNITY COMMENTS
-- Includes edited_at from migrate-074b-community-edited-at.sql
-- =====================================================================

DROP TABLE IF EXISTS `snap_community_comments`;
CREATE TABLE `snap_community_comments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int unsigned NOT NULL,
  `user_id` int unsigned NULL DEFAULT NULL,
  `guest_name` varchar(100) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `guest_email` varchar(200) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `comment_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('visible','hidden','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'visible',
  `ip` varchar(45) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_post_status` (`post_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- LIKES
-- Includes guest_hash from migrate-074b-guest-likes.sql
-- =====================================================================

DROP TABLE IF EXISTS `snap_likes`;
CREATE TABLE `snap_likes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `guest_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_post_user` (`post_id`, `user_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_likes_guest` (`post_id`, `guest_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- REACTIONS
-- Includes guest_hash from migrate-074b-guest-reactions.sql
-- =====================================================================

DROP TABLE IF EXISTS `snap_reactions`;
CREATE TABLE `snap_reactions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL,
  `guest_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reaction_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_post_user` (`post_id`, `user_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_reactions_guest` (`post_id`, `guest_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
