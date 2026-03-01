-- SnapSmack Backup Service
-- Type: SCHEMA
-- Date: 2026-03-01 19:13:04

DROP TABLE IF EXISTS `snap_images`;
CREATE TABLE `snap_images` (
  `id` int NOT NULL AUTO_INCREMENT,
  `img_title` varchar(255) NOT NULL,
  `img_slug` varchar(255) NOT NULL,
  `img_description` text,
  `img_film` varchar(100) DEFAULT NULL,
  `img_date` datetime NOT NULL,
  `img_file` varchar(255) NOT NULL,
  `img_exif` text,
  `img_download_url` varchar(500) DEFAULT NULL,
  `img_download_count` int unsigned NOT NULL DEFAULT '0',
  `img_width` int DEFAULT '0',
  `img_height` int DEFAULT '0',
  `img_status` enum('published','draft') DEFAULT 'published',
  `img_orientation` int DEFAULT '0',
  `allow_comments` tinyint(1) DEFAULT '1',
  `allow_download` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `snap_categories`;
CREATE TABLE `snap_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cat_name` varchar(100) NOT NULL,
  `cat_slug` varchar(100) NOT NULL,
  `cat_description` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `snap_image_cat_map`;
CREATE TABLE `snap_image_cat_map` (
  `image_id` int NOT NULL,
  `cat_id` int NOT NULL,
  PRIMARY KEY (`image_id`,`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `snap_comments`;
CREATE TABLE `snap_comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `img_id` int NOT NULL,
  `comment_author` varchar(100) DEFAULT NULL,
  `comment_email` varchar(150) DEFAULT NULL,
  `comment_text` text,
  `comment_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `comment_ip` varchar(45) DEFAULT NULL,
  `is_approved` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `img_id` (`img_id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `snap_users`;
CREATE TABLE `snap_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `user_role` varchar(20) NOT NULL DEFAULT 'editor',
  `email` varchar(100) DEFAULT NULL,
  `preferred_skin` varchar(100) DEFAULT 'default-dark',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `snap_settings`;
CREATE TABLE `snap_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_val` text,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `snap_pages`;
CREATE TABLE `snap_pages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` longtext,
  `image_asset` varchar(255) DEFAULT '',
  `is_active` tinyint(1) DEFAULT '1',
  `menu_order` int DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

