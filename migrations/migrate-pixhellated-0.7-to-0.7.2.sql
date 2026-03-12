-- ============================================================================
-- SNAPSMACK — Pixhellated.ca One-Shot Migration
-- From: Alpha 0.7.0  To: Alpha 0.7.2 "Sitzfleisch"
-- Date: 2026-03-12
--
-- Run this in phpMyAdmin against the pixhellated.ca database.
-- 100% safe to run: uses IF NOT EXISTS, INSERT IGNORE, and column additions
-- that are no-ops if the column already exists (MySQL will throw 1060 which
-- is expected — just continue past any "Duplicate column" warnings).
--
-- ORDER MATTERS — tables are created before their dependents.
-- ============================================================================


-- ============================================================================
-- 1. MIGRATION TRACKER
--    Lets the built-in migration runner know which files have been applied.
-- ============================================================================

CREATE TABLE IF NOT EXISTS snap_migrations (
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    migration  VARCHAR(200)    NOT NULL,
    applied_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 2. RATE LIMITER
--    Required by community-session.php before any community table is touched.
-- ============================================================================

CREATE TABLE IF NOT EXISTS snap_rate_limits (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    ip           VARCHAR(45)     NOT NULL,
    action       VARCHAR(50)     NOT NULL,
    count        INT UNSIGNED    NOT NULL DEFAULT 1,
    window_start DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ip_action (ip, action),
    KEY idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 3. COMMUNITY USER ACCOUNTS
-- ============================================================================

CREATE TABLE IF NOT EXISTS snap_community_users (
    id             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    username       VARCHAR(50)     NOT NULL,
    display_name   VARCHAR(100)    DEFAULT NULL,
    email          VARCHAR(150)    NOT NULL,
    password_hash  VARCHAR(255)    NOT NULL,
    avatar_url     VARCHAR(500)    DEFAULT NULL,
    bio            TEXT            DEFAULT NULL,
    status         ENUM('active','unverified','suspended') NOT NULL DEFAULT 'unverified',
    email_verified TINYINT(1)      NOT NULL DEFAULT 0,
    last_seen_at   DATETIME        DEFAULT NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_username (username),
    UNIQUE KEY uq_email    (email),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 4. COMMUNITY SESSIONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS snap_community_sessions (
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED    NOT NULL,
    token      VARCHAR(64)     NOT NULL,
    expires_at DATETIME        NOT NULL,
    ip         VARCHAR(45)     DEFAULT NULL,
    user_agent VARCHAR(500)    DEFAULT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token (token),
    KEY idx_user_id    (user_id),
    KEY idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 5. COMMUNITY TOKENS (email verification / password reset)
-- ============================================================================

CREATE TABLE IF NOT EXISTS snap_community_tokens (
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED    NOT NULL,
    token      VARCHAR(64)     NOT NULL,
    type       VARCHAR(30)     NOT NULL,
    expires_at DATETIME        NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token (token),
    KEY idx_user_type (user_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 6. LIKES
-- ============================================================================

CREATE TABLE IF NOT EXISTS snap_likes (
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    post_id    INT UNSIGNED    NOT NULL,
    user_id    INT UNSIGNED    NOT NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_post_user (post_id, user_id),
    KEY idx_post_id (post_id),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 7. REACTIONS
-- ============================================================================

CREATE TABLE IF NOT EXISTS snap_reactions (
    id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    post_id       INT UNSIGNED    NOT NULL,
    user_id       INT UNSIGNED    NOT NULL,
    reaction_code VARCHAR(20)     NOT NULL,
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_post_user (post_id, user_id),
    KEY idx_post_id (post_id),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 8. COMMUNITY COMMENTS
--    Separate from the legacy snap_comments table.
--    Already built with the final guest-comment schema (no ALTER needed).
-- ============================================================================

CREATE TABLE IF NOT EXISTS snap_community_comments (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    post_id      INT UNSIGNED    NOT NULL,
    user_id      INT UNSIGNED    NULL DEFAULT NULL,
    guest_name   VARCHAR(100)    NULL DEFAULT NULL,
    guest_email  VARCHAR(200)    NULL DEFAULT NULL,
    comment_text TEXT            NOT NULL,
    status       ENUM('visible','hidden','deleted') NOT NULL DEFAULT 'visible',
    ip           VARCHAR(45)     NULL DEFAULT NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_post_status (post_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 9. POST CONTAINER LAYER
--    snap_posts, snap_post_images (+ style cols), snap_post_cat_map,
--    snap_post_album_map. The post_id column on snap_images already exists
--    on pixhellated — the ALTER below will produce a "Duplicate column"
--    warning (MySQL 1060) which is safe to ignore.
-- ============================================================================

CREATE TABLE IF NOT EXISTS snap_posts (
    id              INT             NOT NULL AUTO_INCREMENT,
    title           VARCHAR(500)    NOT NULL,
    slug            VARCHAR(600)    NOT NULL,
    description     TEXT            DEFAULT NULL,
    post_type       ENUM('single','carousel','panorama') NOT NULL DEFAULT 'single',
    status          VARCHAR(20)     NOT NULL DEFAULT 'published',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    allow_comments  TINYINT(1)      NOT NULL DEFAULT 1,
    allow_download  TINYINT(1)      NOT NULL DEFAULT 0,
    download_url    VARCHAR(500)    DEFAULT NULL,
    download_count  INT             NOT NULL DEFAULT 0,
    panorama_rows   TINYINT         NOT NULL DEFAULT 1,
    import_source   VARCHAR(50)     DEFAULT NULL,
    import_id       VARCHAR(200)    DEFAULT NULL,
    post_img_size_pct   TINYINT UNSIGNED NOT NULL DEFAULT 100,
    post_border_px      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    post_border_color   CHAR(7)          NOT NULL DEFAULT '#000000',
    post_bg_color       CHAR(7)          NOT NULL DEFAULT '#ffffff',
    post_shadow         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug    (slug),
    UNIQUE KEY uq_import  (import_source, import_id),
    KEY idx_status        (status),
    KEY idx_created_at    (created_at),
    KEY idx_post_type     (post_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS snap_post_images (
    id              INT             NOT NULL AUTO_INCREMENT,
    post_id         INT             NOT NULL,
    image_id        INT             NOT NULL,
    sort_position   SMALLINT        NOT NULL DEFAULT 0,
    is_cover        TINYINT(1)      NOT NULL DEFAULT 0,
    grid_col        TINYINT         DEFAULT NULL,
    grid_row        TINYINT         DEFAULT NULL,
    img_size_pct    TINYINT UNSIGNED NOT NULL DEFAULT 100,
    img_border_px   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    img_border_color CHAR(7)         NOT NULL DEFAULT '#000000',
    img_bg_color    CHAR(7)         NOT NULL DEFAULT '#ffffff',
    img_shadow      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_image (image_id),
    KEY idx_post_id     (post_id),
    KEY idx_sort        (post_id, sort_position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS snap_post_cat_map (
    post_id INT NOT NULL,
    cat_id  INT NOT NULL,
    PRIMARY KEY (post_id, cat_id),
    KEY idx_cat_id (cat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS snap_post_album_map (
    post_id  INT NOT NULL,
    album_id INT NOT NULL,
    PRIMARY KEY (post_id, album_id),
    KEY idx_album_id (album_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add post_id FK to snap_images.
-- Pixhellated already has this column — 1060 "Duplicate column" is expected, ignore it.
ALTER TABLE snap_images ADD COLUMN post_id INT DEFAULT NULL AFTER id;


-- ============================================================================
-- 10. HASHTAGS
-- ============================================================================

CREATE TABLE IF NOT EXISTS snap_tags (
    id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    tag        VARCHAR(100)    NOT NULL,
    slug       VARCHAR(100)    NOT NULL,
    use_count  INT UNSIGNED    DEFAULT 0,
    created_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS snap_image_tags (
    id         INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    image_id   INT UNSIGNED    NOT NULL,
    tag_id     INT UNSIGNED    NOT NULL,
    created_at TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_image_tag (image_id, tag_id),
    KEY idx_tag_id   (tag_id),
    KEY idx_image_id (image_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 11. DATA MIGRATION — wrap every snap_images row in a snap_posts record
--     (mirrors what migrate-posts.sql does, safe to run on fresh post data)
-- ============================================================================

INSERT IGNORE INTO snap_posts
    (title, slug, description, post_type, status, created_at,
     allow_comments, allow_download, download_url)
SELECT
    img_title, img_slug, img_description, 'single', img_status,
    COALESCE(img_date, NOW()),
    allow_comments, allow_download, download_url
FROM snap_images
WHERE post_id IS NULL;

UPDATE snap_images si
JOIN   snap_posts  sp ON sp.slug = si.img_slug
SET    si.post_id = sp.id
WHERE  si.post_id IS NULL;

INSERT IGNORE INTO snap_post_images (post_id, image_id, sort_position, is_cover)
SELECT post_id, id, 0, 1
FROM   snap_images
WHERE  post_id IS NOT NULL;

INSERT IGNORE INTO snap_post_cat_map (post_id, cat_id)
SELECT si.post_id, icm.cat_id
FROM   snap_image_cat_map icm
JOIN   snap_images si ON si.id = icm.image_id
WHERE  si.post_id IS NOT NULL;

INSERT IGNORE INTO snap_post_album_map (post_id, album_id)
SELECT si.post_id, iam.album_id
FROM   snap_image_album_map iam
JOIN   snap_images si ON si.id = iam.image_id
WHERE  si.post_id IS NOT NULL;


-- ============================================================================
-- 12. RECORD MIGRATIONS AS APPLIED
--     Prevents the built-in runner from re-running these files.
-- ============================================================================

INSERT IGNORE INTO snap_migrations (migration) VALUES
    ('migrate-posts.sql'),
    ('migrate-image-style.sql'),
    ('migrate-tags.sql'),
    ('migrate-comment-identity.sql');
