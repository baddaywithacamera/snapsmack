-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
-- SnapSmack migration: skin preset storage for Chaplin border system
-- migrate-chaplin-presets.sql

CREATE TABLE IF NOT EXISTS snap_skin_presets (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    skin_slug    VARCHAR(80)  NOT NULL,
    preset_name  VARCHAR(120) NOT NULL,
    preset_data  JSON         NOT NULL,
    created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_skin (skin_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== SNAPSMACK EOF =====
