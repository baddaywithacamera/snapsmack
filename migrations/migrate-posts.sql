-- ============================================================================
-- SNAPSMACK Migration: Post Container Layer
-- Alpha v0.7.1
--
-- Introduces the concept of a post as a container for one or more images.
-- Adds snap_posts (post metadata), snap_post_images (pivot/ordering),
-- snap_post_cat_map, snap_post_album_map, and a post_id FK on snap_images.
--
-- Non-destructive. All existing data is preserved. Existing skins continue
-- to query snap_images directly and see identical results. New post-aware
-- skins and the carousel posting page query through snap_posts.
--
-- Safe to run multiple times -- uses IF NOT EXISTS, INSERT IGNORE, and the
-- migration runner skips 1060 (ER_DUP_FIELDNAME) on duplicate columns.
-- ============================================================================


-- ============================================================================
-- 1. SNAP_POSTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_posts` (
    `id`              int          NOT NULL AUTO_INCREMENT,
    `title`           varchar(500) NOT NULL,
    `slug`            varchar(600) NOT NULL,
    `description`     text         DEFAULT NULL,
    `post_type`       enum('single','carousel','panorama') NOT NULL DEFAULT 'single',
    `status`          varchar(20)  NOT NULL DEFAULT 'published',
    `created_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `allow_comments`  tinyint(1)   NOT NULL DEFAULT 1,
    `allow_download`  tinyint(1)   NOT NULL DEFAULT 0,
    `download_url`    varchar(500) DEFAULT NULL,
    `download_count`  int          NOT NULL DEFAULT 0,
    `panorama_rows`   tinyint      NOT NULL DEFAULT 1,
    `import_source`   varchar(50)  DEFAULT NULL,
    `import_id`       varchar(200) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug`   (`slug`),
    UNIQUE KEY `uq_import` (`import_source`, `import_id`),
    KEY `idx_status`       (`status`),
    KEY `idx_created_at`   (`created_at`),
    KEY `idx_post_type`    (`post_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 2. SNAP_POST_IMAGES (pivot)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_post_images` (
    `id`            int        NOT NULL AUTO_INCREMENT,
    `post_id`       int        NOT NULL,
    `image_id`      int        NOT NULL,
    `sort_position` smallint   NOT NULL DEFAULT 0,
    `is_cover`      tinyint(1) NOT NULL DEFAULT 0,
    `grid_col`      tinyint    DEFAULT NULL,
    `grid_row`      tinyint    DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_image` (`image_id`),
    KEY `idx_post_id`     (`post_id`),
    KEY `idx_sort`        (`post_id`, `sort_position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 3. ADD post_id COLUMN TO snap_images
--    Migration runner skips 1060 (ER_DUP_FIELDNAME) so safe to re-run.
-- ============================================================================

ALTER TABLE `snap_images`
    ADD COLUMN `post_id` int DEFAULT NULL AFTER `id`;


-- ============================================================================
-- 4. SNAP_POST_CAT_MAP
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_post_cat_map` (
    `post_id` int NOT NULL,
    `cat_id`  int NOT NULL,
    PRIMARY KEY (`post_id`, `cat_id`),
    KEY `idx_cat_id` (`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 5. SNAP_POST_ALBUM_MAP
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_post_album_map` (
    `post_id`  int NOT NULL,
    `album_id` int NOT NULL,
    PRIMARY KEY (`post_id`, `album_id`),
    KEY `idx_album_id` (`album_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 6. DATA MIGRATION
--    Wrap every unmigrated snap_images row in a snap_posts record.
--    Set-based SQL -- no stored procedures, no DELIMITER, PDO-safe.
-- ============================================================================

-- Create post wrappers for every image that has not been migrated yet.
INSERT IGNORE INTO snap_posts
    (title, slug, description, post_type, status, created_at,
     allow_comments, allow_download, download_url)
SELECT
    img_title, img_slug, img_description, 'single', img_status,
    COALESCE(img_date, NOW()),
    allow_comments, allow_download, download_url
FROM snap_images
WHERE post_id IS NULL;

-- Link each image to its post by matching slugs.
UPDATE snap_images si
JOIN   snap_posts  sp ON sp.slug = si.img_slug
SET    si.post_id = sp.id
WHERE  si.post_id IS NULL;

-- Create pivot rows (one image per post, always the cover).
INSERT IGNORE INTO snap_post_images (post_id, image_id, sort_position, is_cover)
SELECT post_id, id, 0, 1
FROM   snap_images
WHERE  post_id IS NOT NULL;

-- Copy category mappings from image level to post level.
INSERT IGNORE INTO snap_post_cat_map (post_id, cat_id)
SELECT si.post_id, icm.cat_id
FROM   snap_image_cat_map icm
JOIN   snap_images si ON si.id = icm.image_id
WHERE  si.post_id IS NOT NULL;

-- Copy album mappings from image level to post level.
INSERT IGNORE INTO snap_post_album_map (post_id, album_id)
SELECT si.post_id, iam.album_id
FROM   snap_image_album_map iam
JOIN   snap_images si ON si.id = iam.image_id
WHERE  si.post_id IS NOT NULL;
