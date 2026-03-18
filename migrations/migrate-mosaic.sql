-- SnapSmack Migration: Mosaic Albums
-- Adds the snap_mosaics table for inline image mosaic shortcodes.
-- Target: v0.8+

CREATE TABLE IF NOT EXISTS snap_mosaics (
    id          INT NOT NULL AUTO_INCREMENT,
    title       VARCHAR(255) NOT NULL DEFAULT 'Untitled Mosaic',
    asset_ids   JSON NOT NULL COMMENT 'Ordered array of snap_assets IDs',
    gap         TINYINT UNSIGNED NOT NULL DEFAULT 4 COMMENT 'Gap between images in pixels',
    max_width   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = full content width',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
