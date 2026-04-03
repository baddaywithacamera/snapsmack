-- SnapSmack Backup Service
-- Type: SCHEMA
-- Date: 2026-04-03 15:43:36

DROP TABLE IF EXISTS `snap_images`;
CREATE TABLE `snap_images` (
  `id` int NOT NULL AUTO_INCREMENT,
  `img_title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `img_slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `img_description` text COLLATE utf8mb4_unicode_ci,
  `img_film` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `img_date` datetime NOT NULL,
  `img_file` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `img_source_file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Original filename on the posting machine at upload time',
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
  `sort_order` int NOT NULL DEFAULT '0' COMMENT 'Manual display order. Lower = earlier in feed. 0 = unset (falls back to img_date DESC).',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=825 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `snap_categories`;
CREATE TABLE `snap_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cat_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cat_slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cat_description` text COLLATE utf8mb4_unicode_ci,
  `cover_image_id` int DEFAULT NULL COMMENT 'Optional manual cover override — FK to snap_images',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `snap_image_cat_map`;
CREATE TABLE `snap_image_cat_map` (
  `image_id` int NOT NULL,
  `cat_id` int NOT NULL,
  PRIMARY KEY (`image_id`,`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `snap_image_album_map`;
CREATE TABLE `snap_image_album_map` (
  `image_id` int NOT NULL,
  `album_id` int NOT NULL,
  PRIMARY KEY (`image_id`,`album_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `snap_albums`;
CREATE TABLE `snap_albums` (
  `id` int NOT NULL AUTO_INCREMENT,
  `album_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `album_description` text COLLATE utf8mb4_unicode_ci,
  `cover_image_id` int DEFAULT NULL COMMENT 'Optional manual cover override — FK to snap_images',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `snap_settings`;
CREATE TABLE `snap_settings` (
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_val` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `snap_pages`;
CREATE TABLE `snap_pages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `image_asset` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `image_size` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full',
  `image_align` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'center',
  `image_shadow` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `menu_order` int DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

DROP TABLE IF EXISTS `snap_assets`;
CREATE TABLE `snap_assets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asset_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_checksum` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SHA-256 hash for recovery verification',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

