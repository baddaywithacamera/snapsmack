-- SNAPSMACK_EOF_HEADER: this file must end with -- ===== SNAPSMACK EOF =====
-- SnapSmack: trigram infrastructure
-- Creates snap_trigrams table and adds trigram_id FK to snap_posts.
-- Trigrams are a wide (or tall) source image sliced into three square cover
-- tiles that form a panoramic across adjacent Grid posts.
-- orientation 'h' = horizontal (L/M/R), 'v' = vertical (T/M/B).
-- cut_a and cut_b are the two cut points in pixels, orientation-agnostic.

CREATE TABLE IF NOT EXISTS `snap_trigrams` (
    `id`          INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    `source_path` VARCHAR(500)        NOT NULL COMMENT 'Original uploaded source image path',
    `orientation` ENUM('h','v')       NOT NULL DEFAULT 'h' COMMENT 'h=horizontal L/M/R, v=vertical T/M/B',
    `cut_a`       SMALLINT UNSIGNED   NOT NULL COMMENT 'First cut point in pixels',
    `cut_b`       SMALLINT UNSIGNED   NOT NULL COMMENT 'Second cut point in pixels',
    `post_id_1`   INT UNSIGNED        NOT NULL COMMENT 'Post assigned slice 1 (L or T)',
    `post_id_2`   INT UNSIGNED        NOT NULL COMMENT 'Post assigned slice 2 (middle)',
    `post_id_3`   INT UNSIGNED        NOT NULL COMMENT 'Post assigned slice 3 (R or B)',
    `created_at`  DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_post_1` (`post_id_1`),
    KEY `idx_post_2` (`post_id_2`),
    KEY `idx_post_3` (`post_id_3`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `snap_posts`
    ADD COLUMN IF NOT EXISTS `trigram_id` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to snap_trigrams.id — NULL = normal post cover';

-- ===== SNAPSMACK EOF =====
