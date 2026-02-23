-- SnapSmack Backup Service
-- Type: SCHEMA
-- Date: 2026-02-23 02:34:39

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
  `img_width` int DEFAULT '0',
  `img_height` int DEFAULT '0',
  `img_status` enum('published','draft') DEFAULT 'published',
  `img_orientation` int DEFAULT '0',
  `allow_comments` tinyint(1) DEFAULT '1',
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

