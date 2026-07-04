-- SNAPSMACK_EOF_HEADER
--     -- ===== SNAPSMACK EOF =====
-- Last non-empty line of this file MUST match the line above.
-- Missing or different = truncated/corrupted. Restore before saving.


-- ─────────────────────────────────────────────────────────────────────────────
-- SNAPSMACK — Canonical Schema
-- Alpha v0.7.213 "Courtesy Wipe"
--
-- AUTHORITATIVE source of truth for the intended database structure at the
-- current release. updater_canonical_diff() reads this file and creates any
-- missing tables or columns idempotently against a live database.
--
-- Rules:
--   • ONE definition per table — no duplicates.
--   • No DROP TABLE — this is additive, not destructive.
--   • No AUTO_INCREMENT values — those are install-specific.
--   • CREATE TABLE IF NOT EXISTS throughout — safe to apply against any install.
--   • New columns added since the initial table definition are marked with a
--     version comment so the history is readable as a diff.
-- ─────────────────────────────────────────────────────────────────────────────


-- ─── CORE IMAGE STORE ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_images` (
  `id`                  int            NOT NULL AUTO_INCREMENT,
  `img_title`           varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `img_slug`            varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `img_description`     text           COLLATE utf8mb4_unicode_ci,
  `img_film`            varchar(100)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `img_license`         varchar(100)   COLLATE utf8mb4_unicode_ci DEFAULT NULL
                        COMMENT 'Rights/licence label (e.g. Flickr import: "All Rights Reserved", CC BY 2.0). Optional; surfaced per-image.',
  `img_date`            datetime       NOT NULL,
  `img_file`            varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `img_source_file`     varchar(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL
                        COMMENT 'Original filename on the posting machine at upload time',
  `img_exif`            text           COLLATE utf8mb4_unicode_ci,
  `img_download_url`    varchar(500)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `img_download_count`  int unsigned   NOT NULL DEFAULT '0',
  `img_like_seed`       int unsigned   NOT NULL DEFAULT '0'
                        COMMENT 'Imported like tally (e.g. Flickr fave count). Live snap_likes add on top.',
  `img_view_seed`       int unsigned   NOT NULL DEFAULT '0'
                        COMMENT 'Imported view tally (e.g. Flickr count_views). Powers the Most Viewed collection.',
  `img_source_url`      varchar(500)   COLLATE utf8mb4_unicode_ci DEFAULT NULL
                        COMMENT 'Original source page (e.g. Flickr photopage) — provenance backlink.',
  `img_width`           int            DEFAULT '0',
  `img_height`          int            DEFAULT '0',
  `img_status`          enum('published','draft') COLLATE utf8mb4_unicode_ci DEFAULT 'published',
  `img_orientation`     int            DEFAULT '0',
  `allow_comments`      tinyint(1)     DEFAULT '1',
  `allow_download`      tinyint(1)     NOT NULL DEFAULT '1',
  `download_url`        varchar(512)   COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `img_thumb_square`    varchar(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL
                        COMMENT 'Relative path to 400x400 square thumbnail (t_ prefix)',
  `img_thumb_aspect`    varchar(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL
                        COMMENT 'Relative path to aspect-ratio thumbnail (a_ prefix)',
  `img_checksum`        varchar(64)    COLLATE utf8mb4_unicode_ci DEFAULT NULL
                        COMMENT 'SHA-256 hash of main image file for recovery verification',
  `img_display_options` text           COLLATE utf8mb4_unicode_ci
                        COMMENT 'JSON: per-image frame/mat/bevel overrides and extracted colour palette',
  `post_id`             int            DEFAULT NULL
                        COMMENT 'FK to snap_posts — populated when image is wrapped in a post',
  `user_id`             int unsigned   DEFAULT NULL
                        COMMENT 'FK to snap_users — owner/author for per-user import attribution. NULL = legacy/unattributed.',
  `sort_order`          int            NOT NULL DEFAULT '0'
                        COMMENT 'Manual display order. Lower = earlier in feed. 0 = unset (falls back to img_date DESC).',
  `modified_at`         datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        COMMENT 'Auto-updated on any row change. Used by GYSS for conflict detection.',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── POSTS (image wrappers for carousels, panoramas, longform) ───────────────

CREATE TABLE IF NOT EXISTS `snap_posts` (
  `id`                int            NOT NULL AUTO_INCREMENT,
  `title`             text           COLLATE utf8mb4_unicode_ci NOT NULL,
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
  `content`           longtext       COLLATE utf8mb4_unicode_ci DEFAULT NULL
                      COMMENT 'Body content for longform (SmackTalk) posts — migration 041',
  `featured_asset_id` int unsigned   DEFAULT NULL
                      COMMENT 'Hero image for longform posts — FK to snap_assets.id — migration 041',
  `trigram_id`        int unsigned   DEFAULT NULL
                      COMMENT 'FK to snap_trigrams.id — NULL = normal post cover',
  `sort_order`        int            NOT NULL DEFAULT 0
                      COMMENT 'Manual feed order. 0 = unset (falls back to created_at DESC).',
  `user_id`           int unsigned   DEFAULT NULL
                      COMMENT 'FK to snap_users — post author/owner (multi-user attribution). NULL = legacy/unattributed. Web-admin wiring pending.',
  `fedi_enabled`      tinyint(1)     NOT NULL DEFAULT 1
                      COMMENT 'SMACKVERSE (0.7.367): 1 = eligible to federate; 0 = never push this post to the fediverse.',
  `fedi_pushed_at`    datetime       DEFAULT NULL
                      COMMENT 'SMACKVERSE (0.7.367): last time this post was pushed to the fediverse. NULL = staged, not yet pushed.',
  `fedi_published_at` datetime       DEFAULT NULL
                      COMMENT 'SMACKVERSE (0.7.367): fediverse date LABEL override (the Note published ts). NULL = use created_at. Does NOT change remote order — order is delivery order.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_post_type` (`post_type`),
  KEY `idx_trigram` (`trigram_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── TRIGRAMS ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_trigrams` (
  `id`           INT UNSIGNED         NOT NULL AUTO_INCREMENT,
  `trigram_type` ENUM('slice','group') NOT NULL DEFAULT 'slice'
                 COMMENT 'slice=GD/Imagick cut in SnapSmack; group=pre-sliced external import',
  `source_path`  VARCHAR(500)         NULL COMMENT 'Original uploaded source image — NULL for group type',
  `orientation`  ENUM('h','v')        NOT NULL DEFAULT 'h' COMMENT 'h=horizontal L/M/R, v=vertical T/M/B',
  `cut_a`        SMALLINT UNSIGNED    NULL COMMENT 'First cut point px — NULL for group type',
  `cut_b`        SMALLINT UNSIGNED    NULL COMMENT 'Second cut point px — NULL for group type',
  `post_id_1`    INT UNSIGNED         NOT NULL COMMENT 'Post assigned slice 1 (L or T)',
  `post_id_2`    INT UNSIGNED         NOT NULL COMMENT 'Post assigned slice 2 (middle)',
  `post_id_3`    INT UNSIGNED         NOT NULL COMMENT 'Post assigned slice 3 (R or B)',
  `created_at`   DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_post_1` (`post_id_1`),
  KEY `idx_post_2` (`post_id_2`),
  KEY `idx_post_3` (`post_id_3`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── POST → IMAGE PIVOT ───────────────────────────────────────────────────────

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
  `img_crop_mode`    enum('fit','fill') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fit'
                     COMMENT 'fit = contain in 1:1 panel (optional frame); fill = IG square crop (cover). Per-image, set by the gram composer.',
  `img_focus_x`      tinyint unsigned NOT NULL DEFAULT 50
                     COMMENT 'Square-crop focal point, horizontal %, 0-100 (50=centre). Per-image, set by the crop/zoom widget.',
  `img_focus_y`      tinyint unsigned NOT NULL DEFAULT 50
                     COMMENT 'Square-crop focal point, vertical %, 0-100 (50=centre).',
  `img_zoom`         smallint unsigned NOT NULL DEFAULT 100
                     COMMENT 'Square-crop zoom %, 100-300 (100=no zoom). Crop dim = min(w,h)/(zoom/100).',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_image` (`image_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_sort` (`post_id`, `sort_position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── TAXONOMY ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_categories` (
  `id`              int   NOT NULL AUTO_INCREMENT,
  `cat_name`        varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cat_slug`        varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cat_description` text  COLLATE utf8mb4_unicode_ci,
  `cover_image_id`  int   DEFAULT NULL
                    COMMENT 'Optional manual cover override — FK to snap_images',
  `show_in_archive` tinyint(1) NOT NULL DEFAULT 1
                    COMMENT '1 = visible in public archive, 0 = hidden (added 0.7.9f)',
  `featured_post_id` int unsigned DEFAULT NULL
                    COMMENT 'Hero image source for category gallery views — migration 039',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_albums` (
  `id`                int  NOT NULL AUTO_INCREMENT,
  `album_name`        varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `album_description` text COLLATE utf8mb4_unicode_ci,
  `cover_image_id`    int  DEFAULT NULL
                      COMMENT 'Optional manual cover override — FK to snap_images',
  `featured_post_id`  int unsigned DEFAULT NULL
                      COMMENT 'Hero image source for album gallery views — migration 039',
  `view_count`        int NOT NULL DEFAULT 0
                      COMMENT 'Album page-view tally — incremented on archive.php?album=N',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_tags` (
  `id`           int unsigned  NOT NULL AUTO_INCREMENT,
  `tag`          varchar(100)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`         varchar(100)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `use_count`    int unsigned  DEFAULT 0,
  `color_family` varchar(20)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at`   timestamp     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tag_slug` (`slug`),
  KEY `idx_tags_color_family` (`color_family`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── PIVOT TABLES ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_image_cat_map` (
  `image_id` int NOT NULL,
  `cat_id`   int NOT NULL,
  PRIMARY KEY (`image_id`, `cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_image_album_map` (
  `image_id` int NOT NULL,
  `album_id` int NOT NULL,
  PRIMARY KEY (`image_id`, `album_id`),
  KEY `idx_iam_album` (`album_id`)
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

CREATE TABLE IF NOT EXISTS `snap_post_cat_map` (
  `post_id` int NOT NULL,
  `cat_id`  int NOT NULL,
  PRIMARY KEY (`post_id`, `cat_id`),
  KEY `idx_cat_id` (`cat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_post_album_map` (
  `post_id`  int NOT NULL,
  `album_id` int NOT NULL,
  PRIMARY KEY (`post_id`, `album_id`),
  KEY `idx_album_id` (`album_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── STATIC PAGES ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_pages` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── SETTINGS ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_settings` (
  `setting_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_val` text         COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── COMMENTS ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_comments` (
  `id`             int          NOT NULL AUTO_INCREMENT,
  `img_id`         int          NOT NULL,
  `comment_author` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment_url`    varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment_email`  varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `comment_text`   text         COLLATE utf8mb4_unicode_ci,
  `comment_date`   datetime     DEFAULT CURRENT_TIMESTAMP,
  `comment_ip`     varchar(45)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fp_hash`        varchar(64)  COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SHA-256 browser fingerprint',
  `is_approved`    tinyint(1)   DEFAULT '0',
  `ap_source`      enum('local','fediverse') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local'
                   COMMENT 'SMACKVERSE (0.7.344): local blog comment vs a federated reply.',
  `ap_actor_url`   varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                   COMMENT 'Remote commenter actor id (fediverse comments only).',
  `ap_object_id`   varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                   COMMENT 'Remote Note id of an inbound comment — dedup key.',
  `ap_note_id`     varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                   COMMENT 'The Note id we assign a LOCAL comment when federating it out, so replies thread.',
  `ap_in_reply_to` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                   COMMENT 'The parent Note id this comment replies to (post Note or another comment Note).',
  PRIMARY KEY (`id`),
  KEY `img_id` (`img_id`),
  KEY `idx_fp_hash` (`fp_hash`),
  UNIQUE KEY `uq_ap_object` (`ap_object_id`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── USERS ────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_users` (
  `id`                    int          NOT NULL AUTO_INCREMENT,
  `username`              varchar(50)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash`         varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_role`             varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'editor',
  `email`                 varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferred_skin`        varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'default-dark',
  `ui_mode`               varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bigwheel',
  `recovery_code_hash`    varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `force_password_change` tinyint(1)   NOT NULL DEFAULT 0,
  `totp_secret`           varchar(32)  DEFAULT NULL,
  `totp_enabled`          tinyint(1)   NOT NULL DEFAULT 0,
  `totp_recovery_json`    text         DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── BLOGROLL ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_blogroll_cats` (
  `id`       int          NOT NULL AUTO_INCREMENT,
  `cat_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_blogroll` (
  `id`               int          NOT NULL AUTO_INCREMENT,
  `peer_name`        varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `peer_url`         varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cat_id`           int          DEFAULT NULL,
  `peer_rss`         varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `peer_desc`        text         COLLATE utf8mb4_unicode_ci,
  `sort_order`       int          NOT NULL DEFAULT '0',
  `rss_last_fetched` datetime     DEFAULT NULL,
  `rss_last_updated` datetime     DEFAULT NULL,
  `source_hub_url`   varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                     COMMENT 'Hub URL that synced this entry — NULL = locally added',
  PRIMARY KEY (`id`),
  KEY `idx_source_hub_url` (`source_hub_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── ASSETS / MIGRATIONS ──────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_assets` (
  `id`             int          NOT NULL AUTO_INCREMENT,
  `asset_name`     varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_path`     varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_checksum` varchar(64)  COLLATE utf8mb4_unicode_ci DEFAULT NULL
                   COMMENT 'SHA-256 hash for recovery verification',
  `asset_border_width` tinyint unsigned NOT NULL DEFAULT 0
                   COMMENT 'Global image border width in px (0-10, 0 = no border). Applied everywhere [img:ID] renders.',
  `asset_border_color` varchar(7)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#000000'
                   COMMENT 'Global image border colour (hex). Paired with asset_border_width.',
  `created_at`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_migrations` (
  `migration`  varchar(100) NOT NULL,
  `applied_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── AUTH / SECURITY ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_password_resets` (
  `id`     int          NOT NULL AUTO_INCREMENT,
  `email`  varchar(255) NOT NULL,
  `token`  varchar(64)  NOT NULL,
  `expiry` datetime     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_password_resets_email` (`email`),
  KEY `idx_password_resets_token` (`token`)
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

CREATE TABLE IF NOT EXISTS `snap_ip_bans` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `ip`         varchar(45)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason`     varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto:brute_force',
  `banned_at`  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ip` (`ip`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_totp_devices` (
  `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED    NOT NULL,
  `token_hash`  CHAR(64)        NOT NULL COMMENT 'SHA-256 hex of the raw trust token',
  `device_hint` VARCHAR(120)    NOT NULL DEFAULT '' COMMENT 'Browser/OS hint stored at trust time',
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`  DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `idx_user_expires` (`user_id`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── COMMUNITY (optional feature set) ────────────────────────────────────────

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

CREATE TABLE IF NOT EXISTS `snap_community_comments` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `post_id`      int unsigned NOT NULL,
  `user_id`      int unsigned NULL DEFAULT NULL,
  `guest_name`   varchar(100) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `guest_email`  varchar(200) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `guest_url`    varchar(500) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `comment_text` text         COLLATE utf8mb4_unicode_ci NOT NULL,
  `status`       enum('visible','hidden','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'visible',
  `ip`           varchar(45)  COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
  `fp_hash`      varchar(64)  COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL COMMENT 'SHA-256 browser fingerprint',
  `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at`    datetime     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_post_status` (`post_id`, `status`),
  KEY `idx_fp_hash` (`fp_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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


-- ─── BAN LIST ─────────────────────────────────────────────────────────────────

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Centralised ban list: fingerprint, IP, and email-hash bans';


-- ─── SHIELD TIER 1 — HUB SHARED BAN REGISTRY ────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_hub_shared_bans` (
  `id`            int unsigned     NOT NULL AUTO_INCREMENT,
  `ban_type`      enum('fingerprint','ip','email_hash') COLLATE utf8mb4_unicode_ci NOT NULL,
  `ban_value`     char(64)         COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 hex. Never a raw IP or email.',
  `reason`        varchar(64)      COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `reported_by`   varchar(255)     COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'URL of the spoke that first reported this hash.',
  `first_seen`    datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen`     datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `report_count`  int unsigned     NOT NULL DEFAULT 1 COMMENT 'Number of distinct spokes that have reported this hash.',
  `removed`       tinyint(1)       NOT NULL DEFAULT 0 COMMENT '1 = manually cleared by hub admin, excluded from distribution.',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_type_val` (`ban_type`, `ban_value`),
  KEY `idx_last_seen` (`last_seen`),
  KEY `idx_removed`   (`removed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Consolidated cross-spoke ban registry (SnapSmack Shield Tier 1)';

CREATE TABLE IF NOT EXISTS `snap_ste_scores` (
  `ban_type`     varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL
                 COMMENT 'ip, email, fingerprint',
  `ban_hash`     varchar(64)  COLLATE utf8mb4_unicode_ci NOT NULL
                 COMMENT 'SHA-256 hash of the raw value',
  `score`        float        NOT NULL DEFAULT 0,
  `colour_level` varchar(10)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'green'
                 COMMENT 'green, yellow, orange, red, black',
  `last_updated` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP
                 ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ban_type`, `ban_hash`),
  KEY `idx_colour` (`colour_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Local cache of SMACK THE ENEMY network reputation scores';


-- ─── SEMANTIC ANALYSIS ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_comments_semantic` (
  `id`                int unsigned     NOT NULL AUTO_INCREMENT,
  `comment_id`        int unsigned     NOT NULL,
  `fingerprint_hash`  varchar(255)     COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment_text`      longtext         COLLATE utf8mb4_unicode_ci NOT NULL,
  `tfidf_vector`      json             DEFAULT NULL
                      COMMENT '0.7.9L: TF-IDF vector for cosine similarity analysis',
  `created_at`        timestamp        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fingerprint` (`fingerprint_hash`),
  KEY `idx_created`     (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Comment text and TF-IDF vectors for semantic duplicate detection';

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Banned keywords and phrases for content-based troll blocking';


-- ─── STATS ────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_stats` (
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
  `dwell_ms`      int unsigned  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_hit_at`   (`hit_at`),
  KEY `idx_is_bot`   (`is_bot`),
  KEY `idx_image_id` (`image_id`),
  KEY `idx_stats_enriched` (`is_bot`, `hit_at`, `image_id`)
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


-- ─── PIMPOTRON ── REMOVED in 0.7.267 ─────────────────────────────────────────
-- KIOSK engine retired (secaudit 026). Tables snap_pimpotron_slideshows /
-- snap_pimpotron_slides dropped via migrations/migrate-drop-pimpotron.sql.
-- Removed from canonical so schema-sync does not recreate them.


-- ─── OH SNAP! / UNZUCKER API KEYS ────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_ohsnap_keys` (
  `id`           int            NOT NULL AUTO_INCREMENT,
  `label`        varchar(100)   COLLATE utf8mb4_unicode_ci NOT NULL
                 COMMENT 'Human-readable label assigned at creation',
  `key_type`     varchar(20)    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ohsnap'
                 COMMENT 'ohsnap | smackpress | unzucker',
  `key_hash`     varchar(64)    COLLATE utf8mb4_unicode_ci NOT NULL
                 COMMENT 'SHA-256 hex digest of the raw key — key itself is never stored',
  `key_prefix`   varchar(8)     COLLATE utf8mb4_unicode_ci NOT NULL
                 COMMENT 'First 8 chars of raw key for identification in the UI',
  `is_active`    tinyint(1)     NOT NULL DEFAULT 1,
  `user_id`      int unsigned   DEFAULT NULL
                 COMMENT 'FK to snap_users — the user this key acts as. Import keys MUST be bound (per-user attribution); NULL = legacy key (reject imports, force regen).',
  `created_at`   datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` datetime       DEFAULT NULL,
  `expires_at`   datetime       DEFAULT NULL
                 COMMENT 'Mandatory key expiry (<=4 weeks) as of 0.7.263; NULL = legacy key, no expiry',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key_hash` (`key_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── MULTISITE MANAGEMENT ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_multisite_nodes` (
  `id`                  int unsigned   NOT NULL AUTO_INCREMENT,
  `role`                enum('hub','spoke') COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_url`            varchar(500)   COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_name`           varchar(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `site_tagline`        varchar(500)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `blogroll_desc`       varchar(500)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `api_key_local`       varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL
                        COMMENT 'Our key that the remote site uses to call us',
  `api_key_remote`      varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
                        COMMENT 'Key we use to call the remote site',
  `api_key_backup`      varchar(255)   COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
                        COMMENT 'Least-privilege hub->spoke key valid ONLY on multisite/backup/* endpoints (0.7.261). Populated on (re)join; empty = use api_key_local fallback.',
  `software_version`    varchar(50)    COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_seen_at`        datetime       DEFAULT NULL,
  `ban_sync_cursor`     datetime       DEFAULT NULL
                        COMMENT 'Hub: timestamp of last successful ban sync with this spoke.',
  `post_count`          int unsigned   DEFAULT 0,
  `image_count`         int unsigned   DEFAULT 0,
  `pending_comments`    int unsigned   DEFAULT 0,
  `last_backup_at`      datetime       DEFAULT NULL,
  `last_backup_size`    bigint unsigned DEFAULT NULL,
  `last_backup_dest`    varchar(100)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_backup_status`  enum('ok','failed','unknown') COLLATE utf8mb4_unicode_ci DEFAULT 'unknown',
  `disk_usage_bytes`    bigint unsigned DEFAULT NULL,
  `maintenance_mode`    tinyint(1)     NOT NULL DEFAULT 0
                        COMMENT 'Cached from heartbeat: 1 = spoke is currently in maintenance mode',
  `smackback_status`    varchar(20)    NOT NULL DEFAULT 'unknown'
                        COMMENT 'Cached from heartbeat + push: clean|breach|unknown',
  `smackback_breach_at` datetime       DEFAULT NULL
                        COMMENT 'When breach was first detected on this spoke',
  `smackback_breach_files` mediumtext  COLLATE utf8mb4_unicode_ci DEFAULT NULL
                        COMMENT 'JSON: affected files from last breach report',
  `installed_skins`     text           COLLATE utf8mb4_unicode_ci DEFAULT NULL
                        COMMENT 'Cached from heartbeat (0.7.343): JSON map {slug: version} of skins installed on this spoke, so the hub only offers skin updates a spoke actually has.',
  `smackverse_enabled`  tinyint(1)     NOT NULL DEFAULT 0
                        COMMENT 'Cached from heartbeat (0.7.343): 1 = spoke has SMACKVERSE federation enabled.',
  `smackverse_followers` int unsigned  NOT NULL DEFAULT 0
                        COMMENT 'Cached from heartbeat (0.7.343): spoke fediverse follower count, for the fleet rollup.',
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


-- ─── MOSAICS ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_mosaics` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`      VARCHAR(150)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_ids`  LONGTEXT      COLLATE utf8mb4_unicode_ci NOT NULL
               COMMENT 'JSON array of snap_assets IDs in display order',
  `gap`        TINYINT       NOT NULL DEFAULT 4
               COMMENT 'Gap in pixels between mosaic cells (0–20)',
  `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── COLLECTIONS ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_collections` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `title`           VARCHAR(150)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug`            VARCHAR(150)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `description`     TEXT          COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_display` ENUM('browse','slideshow') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'browse',
  `cover_image_id`  INT UNSIGNED  DEFAULT NULL
                    COMMENT 'Hero image — NULL falls back to first collection member',
  `sort_order`      INT           NOT NULL DEFAULT 0,
  `published`       TINYINT       NOT NULL DEFAULT 0
                    COMMENT '0=hidden from public, 1=live',
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_collection_items` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `collection_id` INT UNSIGNED NOT NULL,
  `item_type`     ENUM('post','album','category','image') COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id`       INT UNSIGNED NOT NULL,
  `sort_order`    INT          NOT NULL DEFAULT 0,
  `added_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `image_id`      INT UNSIGNED NOT NULL DEFAULT 0
                  COMMENT 'v0.2 image-folio member — FK to snap_images.id',
  `position`      INT          NOT NULL DEFAULT 0,
  `caption`       TEXT         COLLATE utf8mb4_unicode_ci DEFAULT NULL
                  COMMENT 'Collection-specific caption; overrides post description in collection context',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_collection_item` (`collection_id`, `item_type`, `item_id`),
  KEY `idx_collection` (`collection_id`),
  KEY `idx_item` (`item_type`, `item_id`),
  CONSTRAINT `fk_ci_collection`
      FOREIGN KEY (`collection_id`) REFERENCES `snap_collections` (`id`)
      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── SKIN PRESETS ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_skin_presets` (
  `id`           int            NOT NULL AUTO_INCREMENT,
  `skin_slug`    varchar(80)    NOT NULL,
  `preset_name`  varchar(120)   NOT NULL,
  `preset_data`  json           NOT NULL,
  `created_at`   datetime       DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_skin` (`skin_slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── SMACKBACK FILE INTEGRITY ─────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_file_manifest` (
  `id`             int unsigned   NOT NULL AUTO_INCREMENT,
  `file_path`      varchar(500)   NOT NULL,
  `expected_hash`  char(64)       NOT NULL,
  `file_size`      int unsigned   NOT NULL,
  `expected_mtime` int unsigned   DEFAULT NULL,
  `eof_signature`  varchar(512)   DEFAULT NULL,
  `skin_id`        varchar(64)    DEFAULT NULL,
  `baseline_set`   datetime       NOT NULL,
  `last_verified`  datetime       DEFAULT NULL,
  `last_status`    enum('ok','tampered','truncated','corrupted','missing','pending') NOT NULL DEFAULT 'pending',
  `baseline_origin` enum('release','install','disk','rebless') NOT NULL DEFAULT 'release',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_path` (`file_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_smackback_log` (
  `id`             int unsigned   NOT NULL AUTO_INCREMENT,
  `detected_at`    datetime       NOT NULL,
  `resolved_at`    datetime       DEFAULT NULL,
  `affected_files` text           NOT NULL,
  `file_count`     smallint       NOT NULL DEFAULT '0',
  `resolution`     varchar(64)    DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── SMACKPRESS CATEGORIES ───────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_cats` (
  `id`   int          NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='SmackPress post categories (distinct from snap_categories / photo taxonomy)';


-- ─── BACKUP LOG ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `snap_backup_log` (
  `id`          int unsigned   NOT NULL AUTO_INCREMENT,
  `created_at`  datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status`      varchar(20)    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ok',
  `size_bytes`  bigint unsigned DEFAULT NULL,
  `destination` varchar(100)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes`       text           COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail for AI cost-responsibility acceptance. Enabling AI is a signing
-- event (password + TOTP step-up); each acceptance is recorded here with who/when.
CREATE TABLE IF NOT EXISTS `snap_ai_acceptance_audit` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `user_id`     int unsigned DEFAULT NULL,
  `username`    varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action`      varchar(20)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'accepted',
  `ip_address`  varchar(45)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent`  varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accepted_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_accepted_at` (`accepted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ─── SMACKVERSE (ActivityPub federation, 0.7.341) ────────────────────────────

-- Remote actors following this blog. actor_url is the canonical remote id;
-- inbox/shared inbox are captured at Follow time and refreshed when re-fetched.
CREATE TABLE IF NOT EXISTS `snap_ap_followers` (
  `id`               int unsigned  NOT NULL AUTO_INCREMENT,
  `actor_url`        varchar(500)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_handle`     varchar(190)  COLLATE utf8mb4_unicode_ci DEFAULT NULL
                     COMMENT 'Display convenience: preferredUsername@host at Follow time',
  `inbox_url`        varchar(500)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `shared_inbox_url` varchar(500)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `followed_at`      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active`        tinyint(1)    NOT NULL DEFAULT '1'
                     COMMENT '0 after Undo Follow — kept for history, excluded from delivery',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_actor` (`actor_url`(191)),
  KEY `idx_ap_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Accounts the BLOG ACTOR follows (0.7.356) — outbound Follow for reach:
-- following a photographer puts the blog in their notifications, which is
-- how follow-backs happen. Publisher-only: no content ingestion, no reader.
CREATE TABLE IF NOT EXISTS `snap_ap_following` (
  `id`           int unsigned  NOT NULL AUTO_INCREMENT,
  `actor_url`    varchar(500)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_handle` varchar(190)  COLLATE utf8mb4_unicode_ci DEFAULT NULL
                 COMMENT 'Display convenience: preferredUsername@host at Follow time',
  `inbox_url`    varchar(500)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `follow_id`    varchar(600)  COLLATE utf8mb4_unicode_ci NOT NULL
                 COMMENT 'Our Follow activity id — inbound Accept/Reject matches it; Undo wraps it.',
  `state`        enum('pending','accepted','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `followed_at`  datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_following` (`actor_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbound activity delivery queue (Accepts, Creates). Processed by
-- cron-smackverse.php with exponential backoff; rows are deleted on success
-- and parked as status=failed after max attempts.
CREATE TABLE IF NOT EXISTS `snap_ap_deliveries` (
  `id`            int unsigned  NOT NULL AUTO_INCREMENT,
  `inbox_url`     varchar(500)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `activity_json` mediumtext    COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts`      int unsigned  NOT NULL DEFAULT '0',
  `next_try_at`   datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status`        enum('queued','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `last_error`    varchar(500)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at`    datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ap_due` (`status`, `next_try_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Federated likes (0.7.344). Kept separate from snap_likes because a remote
-- actor has no snap_users.user_id (snap_likes' UNIQUE(post_id,user_id) can't
-- hold it). The blog shows a COMBINED tally: native snap_likes + these.
CREATE TABLE IF NOT EXISTS `snap_ap_likes` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `target_type` enum('image','post') COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id`   int unsigned NOT NULL,
  `actor_url`   varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_like` (`target_type`, `target_id`, `actor_url`(180)),
  KEY `idx_ap_like_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── SMACKVERSE reader / engagement (0.7.365) ────────────────────────────────
-- The fediverse client is a first-class feature, not a push-only broadcaster:
-- a blog that only publishes is a spambot. These tables back the READER and the
-- two-way engagement loop (see, be seen, reply back). GRAMOFSMACK-first; the
-- tables are mode-agnostic (harmless when the client is gated off).

-- Cache of remote actor docs so timelines/notifications render without
-- re-fetching every actor on every page load.
CREATE TABLE IF NOT EXISTS `snap_ap_actors` (
  `id`               int unsigned NOT NULL AUTO_INCREMENT,
  `actor_url`        varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `handle`           varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name`             varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar_url`       varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inbox_url`        varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shared_inbox_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `summary`          text         COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profile_url`      varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fetched_at`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_actor_cache` (`actor_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inbound engagement aimed at the blog: the loop-closer. Populated by the
-- inbox handler on Follow / Like / reply Create / Mention / Announce.
CREATE TABLE IF NOT EXISTS `snap_ap_notifications` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `ntype`        enum('follow','like','reply','mention','boost') COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_url`    varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_handle` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `object_id`    varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                 COMMENT 'reply Note id / liked / boosted object',
  `target_url`   varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                 COMMENT 'our post they engaged, when applicable',
  `content`      text         COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read`      tinyint(1)   NOT NULL DEFAULT '0',
  `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_notif` (`ntype`, `actor_url`(150), `object_id`(150)),
  KEY `idx_ap_notif_read` (`is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ingested inbound posts — the Home reader (Creates + Announces from accounts
-- the blog follows), and later cached Local/Global entries. De-duped on the
-- remote Note id.
CREATE TABLE IF NOT EXISTS `snap_ap_timeline` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `object_id`    varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_url`    varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actor_handle` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content`      mediumtext   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `media_json`   mediumtext   COLLATE utf8mb4_unicode_ci DEFAULT NULL
                 COMMENT 'JSON array of image URLs',
  `url`          varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `in_reply_to`  varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_boost`     tinyint(1)   NOT NULL DEFAULT '0',
  `boosted_by`   varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source`       enum('home','local','global') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'home',
  `published`    datetime     DEFAULT NULL,
  `fetched_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_tl` (`object_id`(191)),
  KEY `idx_ap_tl_src` (`source`, `published`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Durable outbound replies to REMOTE objects: gives each reply a permalinked,
-- dereferenceable Note (/ap/note/r/<token>) so it threads and the other person
-- can reply back — retiring the fire-and-forget tokenised id.
CREATE TABLE IF NOT EXISTS `snap_ap_outbound_replies` (
  `id`           int unsigned NOT NULL AUTO_INCREMENT,
  `token`        varchar(40)  COLLATE utf8mb4_unicode_ci NOT NULL,
  `in_reply_to`  varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_actor_url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `to_handle`    varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content`      text         COLLATE utf8mb4_unicode_ci NOT NULL,
  `published`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_reply_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the blog has liked remotely — gives Like real state + Undo.
CREATE TABLE IF NOT EXISTS `snap_ap_outbound_likes` (
  `id`         int unsigned NOT NULL AUTO_INCREMENT,
  `object_id`  varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `like_id`    varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL
               COMMENT 'our Like activity id — the Undo wraps it',
  `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ap_out_like` (`object_id`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== SNAPSMACK EOF =====
