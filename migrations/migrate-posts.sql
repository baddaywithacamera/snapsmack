-- ============================================================================
-- SNAPSMACK Migration: Post Container Layer
-- Target: Alpha v0.8
--
-- Introduces the concept of a post as a container for one or more images.
-- Adds snap_posts (post metadata), snap_post_images (pivot/ordering),
-- snap_post_cat_map, snap_post_album_map, and a post_id FK on snap_images.
--
-- Non-destructive. All existing data is preserved. Existing skins continue
-- to query snap_images directly and see identical results. New post-aware
-- skins and the carousel posting page query through snap_posts.
--
-- Safe to run multiple times — uses IF NOT EXISTS and INSERT IGNORE.
-- Run AFTER migrate-community.sql if community tables are needed.
--
-- NOTE: post_page routing in smack-post.php activates when the active skin's
-- manifest declares 'post_page' => 'carousel'. The Grid skin will do this.
-- No existing skin triggers the carousel poster — flip is zero-risk.
-- ============================================================================


-- ============================================================================
-- 1. SNAP_POSTS
--    The post record. A post is a container for 1–10 images.
--    Single-image posts wrap existing snap_images rows one-for-one.
--    Carousel posts group multiple images. Panorama posts are carousels
--    where images are pre-sliced segments of a single wide source.
--
--    import_source / import_id: reserved for the Instagram importer (Phase 5).
--    panorama_rows: only meaningful for post_type = 'panorama'. 1–3 rows.
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
    `panorama_rows`   tinyint      NOT NULL DEFAULT 1  COMMENT '1, 2, or 3 — only used when post_type = panorama',
    `import_source`   varchar(50)  DEFAULT NULL        COMMENT 'e.g. instagram, flickr — for deduplication',
    `import_id`       varchar(200) DEFAULT NULL        COMMENT 'original platform ID or filename — unique per source',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_slug`            (`slug`),
    UNIQUE KEY `uq_import`          (`import_source`, `import_id`),
    KEY `idx_status`                (`status`),
    KEY `idx_created_at`            (`created_at`),
    KEY `idx_post_type`             (`post_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 2. SNAP_POST_IMAGES  (pivot)
--    Maps images to posts with ordering. One image belongs to exactly one post.
--    sort_position determines carousel order and panorama tile placement.
--    is_cover = 1 on the first image (grid thumbnail, og:image, etc).
--    grid_col / grid_row: panorama tile coordinates (1-indexed). NULL for
--    non-panorama posts.
--    sort_position = -1 is reserved for the unsplit panorama source image
--    (stored but not displayed — kept for re-splitting or full download).
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_post_images` (
    `id`              int         NOT NULL AUTO_INCREMENT,
    `post_id`         int         NOT NULL,
    `image_id`        int         NOT NULL,
    `sort_position`   smallint    NOT NULL DEFAULT 0,
    `is_cover`        tinyint(1)  NOT NULL DEFAULT 0,
    `grid_col`        tinyint     DEFAULT NULL  COMMENT 'panorama only: column 1–3',
    `grid_row`        tinyint     DEFAULT NULL  COMMENT 'panorama only: row 1–3',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_image`          (`image_id`),
    KEY `idx_post_id`              (`post_id`),
    KEY `idx_sort`                 (`post_id`, `sort_position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 3. ADD post_id COLUMN TO snap_images
--    NULL = legacy standalone record (pre-migration).
--    After the data migration below, every row will have a post_id.
--    New posts written by the carousel poster always set post_id immediately.
-- ============================================================================

ALTER TABLE `snap_images`
    ADD COLUMN IF NOT EXISTS `post_id` int DEFAULT NULL
        COMMENT 'FK to snap_posts.id. NULL = pre-migration standalone image.'
        AFTER `id`;


-- ============================================================================
-- 4. SNAP_POST_CAT_MAP  (post-level categories)
--    Parallel to snap_image_cat_map. New code writes here.
--    Legacy image maps are preserved for backward compatibility.
--    Lookup helper checks post map first, falls back to image map.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_post_cat_map` (
    `post_id`  int NOT NULL,
    `cat_id`   int NOT NULL,
    PRIMARY KEY (`post_id`, `cat_id`),
    KEY `idx_cat_id` (`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 5. SNAP_POST_ALBUM_MAP  (post-level albums)
--    Same pattern as snap_post_cat_map.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_post_album_map` (
    `post_id`   int NOT NULL,
    `album_id`  int NOT NULL,
    PRIMARY KEY (`post_id`, `album_id`),
    KEY `idx_album_id` (`album_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 6. DATA MIGRATION
--    For every image in snap_images that does not yet have a post_id,
--    create a matching snap_posts record (single type), set the FK,
--    insert the snap_post_images pivot row, and copy cat/album maps.
--
--    Uses a stored procedure to loop cleanly. Procedure is dropped after use.
--    The IGNORE on INSERT handles any slug collisions from duplicates.
-- ============================================================================

DROP PROCEDURE IF EXISTS `snap_migrate_posts`;

DELIMITER $$

CREATE PROCEDURE `snap_migrate_posts`()
BEGIN
    DECLARE done      INT DEFAULT FALSE;
    DECLARE v_id      INT;
    DECLARE v_title   VARCHAR(500);
    DECLARE v_slug    VARCHAR(600);
    DECLARE v_desc    TEXT;
    DECLARE v_status  VARCHAR(20);
    DECLARE v_date    DATETIME;
    DECLARE v_cmts    TINYINT;
    DECLARE v_dl      TINYINT;
    DECLARE v_dlurl   VARCHAR(500);
    DECLARE v_post_id INT;

    DECLARE cur CURSOR FOR
        SELECT id, img_title, img_slug, img_description, img_status,
               img_date, allow_comments, allow_download, download_url
        FROM snap_images
        WHERE post_id IS NULL;

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    OPEN cur;

    migration_loop: LOOP
        FETCH cur INTO v_id, v_title, v_slug, v_desc, v_status,
                       v_date, v_cmts, v_dl, v_dlurl;
        IF done THEN
            LEAVE migration_loop;
        END IF;

        -- Create the post wrapper. Use INSERT IGNORE in case slug exists.
        INSERT IGNORE INTO snap_posts
            (title, slug, description, post_type, status, created_at,
             allow_comments, allow_download, download_url)
        VALUES
            (v_title, v_slug, v_desc, 'single', v_status,
             COALESCE(v_date, NOW()),
             v_cmts, v_dl, v_dlurl);

        SET v_post_id = LAST_INSERT_ID();

        -- If INSERT IGNORE skipped due to slug collision, look up the existing post.
        IF v_post_id = 0 THEN
            SELECT id INTO v_post_id FROM snap_posts WHERE slug = v_slug LIMIT 1;
        END IF;

        -- Link the image to the post.
        UPDATE snap_images SET post_id = v_post_id WHERE id = v_id;

        -- Pivot row: single image is always the cover, sort_position 0.
        INSERT IGNORE INTO snap_post_images
            (post_id, image_id, sort_position, is_cover)
        VALUES
            (v_post_id, v_id, 0, 1);

        -- Copy category mappings to post level.
        INSERT IGNORE INTO snap_post_cat_map (post_id, cat_id)
            SELECT v_post_id, cat_id
            FROM snap_image_cat_map
            WHERE image_id = v_id;

        -- Copy album mappings to post level.
        INSERT IGNORE INTO snap_post_album_map (post_id, album_id)
            SELECT v_post_id, album_id
            FROM snap_image_album_map
            WHERE image_id = v_id;

    END LOOP;

    CLOSE cur;
END$$

DELIMITER ;

CALL `snap_migrate_posts`();

DROP PROCEDURE IF EXISTS `snap_migrate_posts`;
