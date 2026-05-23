-- SNAPSMACK_EOF_HEADER
--     -- ===== SNAPSMACK EOF =====
-- Last non-empty line of this file MUST match the line above.
-- Missing or different = truncated/corrupted. Restore before saving.

-- SMACKBACK file integrity monitoring
-- 0.7.170

-- ─── FILE MANIFEST ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS snap_file_manifest (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_path      VARCHAR(500)  NOT NULL,
    expected_hash  CHAR(64)      NOT NULL,
    file_size      INT UNSIGNED  NOT NULL,
    expected_mtime INT UNSIGNED  DEFAULT NULL,
    eof_signature  VARCHAR(512)  DEFAULT NULL,
    skin_id        VARCHAR(64)   DEFAULT NULL,
    baseline_set   DATETIME      NOT NULL,
    last_verified  DATETIME      DEFAULT NULL,
    last_status    ENUM('ok','tampered','truncated','corrupted','missing','pending') NOT NULL DEFAULT 'pending',
    UNIQUE KEY uq_path (file_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── INCIDENT LOG ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS snap_smackback_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    detected_at     DATETIME     NOT NULL,
    resolved_at     DATETIME     DEFAULT NULL,
    affected_files  TEXT         NOT NULL,   -- JSON array of {path, status}
    file_count      SMALLINT     NOT NULL DEFAULT 0,
    resolution      VARCHAR(64)  DEFAULT NULL  -- 'restore', 'update', 'manual', 'reinit'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── SETTINGS ──────────────────────────────────────────────────────────────

INSERT IGNORE INTO snap_settings (setting_key, setting_val) VALUES
    ('smackback_enabled',            '0'),
    ('smackback_mode',               'lockout'),
    ('smackback_status',             'clean'),
    ('smackback_breach_files',       ''),
    ('smackback_breach_at',          ''),
    ('smackback_breach_resolved_at', ''),
    ('smackback_last_full_verify',   ''),
    ('smackback_alert_email',        ''),
    ('smackback_pageload_check',     '0');

-- ===== SNAPSMACK EOF =====
