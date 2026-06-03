-- SNAPSMACK_EOF_HEADER: this file must end with -- ===== SNAPSMACK EOF =====
-- SnapSmack: trigram infrastructure
-- Creates snap_trigrams table and adds trigram_id FK to snap_posts.
-- Trigrams are wide source images sliced into three square cover tiles
-- that form a panoramic across adjacent Grid posts (L/M/R).

CREATE TABLE IF NOT EXISTS `snap_trigrams` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `source_path` VARCHAR(500)  NOT NULL COMMENT 'Original uploaded source image path',
    `cut_left`    SMALLINT      NOT NULL COMMENT 'Pixel x of left cut point',
    `cut_right`   SMALLINT      NOT NULL COMMENT 'Pixel x of right cut point',
    `post_id_l`   INT UNSIGNED  NOT NULL COMMENT 'Post assigned the L slice',
    `post_id_m`   INT UNSIGNED  NOT NULL COMMENT 'Post assigned the M slice',
    `post_id_r`   INT UNSIGNED  NOT NULL COMMENT 'Post assigned the R slice',
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_post_l` (`post_id_l`),
    KEY `idx_post_m` (`post_id_m`),
    KEY `idx_post_r` (`post_id_r`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `snap_posts`
    ADD COLUMN IF NOT EXISTS `trigram_id` INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to snap_trigrams.id — NULL = normal post cover';

-- ===== SNAPSMACK EOF =====
