-- ============================================================================
-- SNAPSMACK Migration: v0.7 → v0.8
-- Run on your live database via phpMyAdmin SQL tab or MySQL CLI.
-- Safe to run multiple times — uses IF NOT EXISTS and INSERT IGNORE.
-- ============================================================================


-- ============================================================================
-- 1. NEW TABLES
--    Albums, album mapping, and media assets. The installer already creates
--    these for fresh installs; existing v0.7 databases need them.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `snap_albums` (
    `id` int NOT NULL AUTO_INCREMENT,
    `album_name` varchar(255) NOT NULL,
    `album_description` text,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_image_album_map` (
    `image_id` int NOT NULL,
    `album_id` int NOT NULL,
    PRIMARY KEY (`image_id`, `album_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `snap_assets` (
    `id` int NOT NULL AUTO_INCREMENT,
    `asset_name` varchar(255) NOT NULL,
    `asset_path` varchar(500) NOT NULL,
    `asset_checksum` varchar(64) DEFAULT NULL COMMENT 'SHA-256 hash for recovery verification',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 2. COLUMN ADDITION — snap_images.img_display_options
--    Per-image frame/mat/bevel overrides for Hip To Be Square, plus
--    extracted colour palette. Stored as JSON.
--    Uses INFORMATION_SCHEMA check so it won't fail if already present.
-- ============================================================================

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'snap_images'
    AND COLUMN_NAME = 'img_display_options');

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE snap_images ADD COLUMN img_display_options TEXT DEFAULT NULL COMMENT ''JSON: per-image frame/mat/bevel overrides and extracted colour palette''',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- 3. CORE SETTINGS
--    INSERT IGNORE = skip if key already exists, never overwrites.
-- ============================================================================

-- Download system salt (used by download.php and core/download-overlay.php)
-- *** IMPORTANT: Change this value to a unique random string on your server! ***
INSERT IGNORE INTO snap_settings (setting_key, setting_val)
VALUES ('download_salt', 'CHANGE-ME-to-a-random-string-at-least-32-chars');


-- ============================================================================
-- 4. HIP TO BE SQUARE — skin setting defaults
-- ============================================================================

INSERT IGNORE INTO snap_settings (setting_key, setting_val)
VALUES
    ('wall_texture',           'canvas'),
    ('wall_bg_color',          '#3d3d3d'),
    ('frame_color',            '#2c2017'),
    ('frame_width',            '12'),
    ('mat_color',              '#f5f0eb'),
    ('mat_width',              '24'),
    ('bevel_style',            'single'),
    ('slider_per_view',        '5'),
    ('slider_speed',           '400'),
    ('slider_autoplay',        '0'),
    ('slider_loop',            '1'),
    ('htbs_grid_cols',         '4'),
    ('htbs_mini_frames',       '1'),
    ('htbs_show_filmstrip',    '1'),
    ('htbs_plaque_style',      'brass'),
    ('htbs_force_square',      '0');


-- ============================================================================
-- 5. PICASA WEB ALBUMS — skin setting defaults
-- ============================================================================

INSERT IGNORE INTO snap_settings (setting_key, setting_val)
VALUES
    ('landing_mode',           'albums'),
    ('album_grid_cols',        '3'),
    ('interior_grid_cols',     '5'),
    ('show_sidebar',           '0'),
    ('show_header_nav',        '1'),
    ('pwa_bg_color',           '#ffffff'),
    ('pwa_accent_color',       '#4d90fe'),
    ('pwa_border_color',       '#e0e0e0'),
    ('thumb_border_radius',    '2'),
    ('thumb_shadow',           '1'),
    ('viewer_bg',              'white'),
    ('show_filmstrip',         '1'),
    ('show_exif',              '1'),
    ('slideshow_interval',     '5'),
    ('slideshow_transition',   'crossfade');


-- ============================================================================
-- 6. RATIONAL GEO — skin setting defaults
-- ============================================================================

INSERT IGNORE INTO snap_settings (setting_key, setting_val)
VALUES
    ('image_border_color',     'yellow'),
    ('hero_border_width',      '8'),
    ('thumb_border_width',     '2'),
    ('show_map_background',    '1'),
    ('archive_default_layout', 'cropped'),
    ('main_canvas_width',      '1200'),
    ('optical_lift',           '40'),
    ('header_height',          '70'),
    ('browse_cols',            '4'),
    ('justified_row_height',   '260'),
    ('masthead_font',          'Playfair Display'),
    ('body_font',              'Source Serif 4'),
    ('exif_font',              'DM Mono'),
    ('single_show_description','1'),
    ('single_show_signals',    '1'),
    ('blogroll_columns',       '1'),
    ('blogroll_max_width',     '900');


-- ============================================================================
-- 7. FTP BACKUP — default settings
-- ============================================================================

INSERT IGNORE INTO snap_settings (setting_key, setting_val)
VALUES
    ('ftp_host',       ''),
    ('ftp_port',       '21'),
    ('ftp_user',       ''),
    ('ftp_pass',       ''),
    ('ftp_remote_dir', '/'),
    ('ftp_use_ssl',    '0'),
    ('ftp_passive',    '1'),
    ('ftp_last_push',  ''),
    ('ftp_last_status','');


-- ============================================================================
-- 8. CLOUD BACKUP — OAuth credential placeholders
-- ============================================================================

INSERT IGNORE INTO snap_settings (setting_key, setting_val)
VALUES
    ('google_client_id',       ''),
    ('google_client_secret',   ''),
    ('google_refresh_token',   ''),
    ('onedrive_client_id',     ''),
    ('onedrive_client_secret', ''),
    ('onedrive_refresh_token', ''),
    ('cloud_last_push',        ''),
    ('cloud_last_status',      '');


-- ============================================================================
-- 10. SOCIAL DOCK — profile link settings
-- ============================================================================

INSERT IGNORE INTO snap_settings (setting_key, setting_val)
VALUES
    ('social_dock_enabled',    '0'),
    ('social_dock_position',   'bottom-right'),
    ('social_dock_icon_color', '#ffffff'),
    ('social_dock_opacity',    '20'),
    ('social_dock_icon_shape', 'round'),
    ('social_dock_icon_style', 'outline'),
    ('social_dock_flickr',     ''),
    ('social_dock_smugmug',    ''),
    ('social_dock_instagram',  ''),
    ('social_dock_facebook',   ''),
    ('social_dock_youtube',    ''),
    ('social_dock_500px',      ''),
    ('social_dock_vero',       ''),
    ('social_dock_threads',    ''),
    ('social_dock_bluesky',    ''),
    ('social_dock_linkedin',   ''),
    ('social_dock_pinterest',  ''),
    ('social_dock_tumblr',     ''),
    ('social_dock_deviantart', ''),
    ('social_dock_behance',    ''),
    ('social_dock_website',    '');


-- ============================================================================
-- 11. VERSION STAMP
-- ============================================================================

UPDATE snap_settings
SET setting_val = '0.8.0-alpha'
WHERE setting_key = 'installed_version';
