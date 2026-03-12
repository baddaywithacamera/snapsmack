-- ============================================================
-- SNAPSMACK - Hashtag Tables Migration
-- Alpha v0.7.1
--
-- Creates snap_tags (global tag registry) and snap_image_tags
-- (image to tag junction). Safe to re-run (IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS snap_tags (
    id         INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    tag        VARCHAR(100)     NOT NULL,
    slug       VARCHAR(100)     NOT NULL,
    use_count  INT UNSIGNED     DEFAULT 0,
    created_at TIMESTAMP        DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS snap_image_tags (
    id         INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    image_id   INT UNSIGNED     NOT NULL,
    tag_id     INT UNSIGNED     NOT NULL,
    created_at TIMESTAMP        DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_image_tag (image_id, tag_id),
    KEY idx_tag_id   (tag_id),
    KEY idx_image_id (image_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
