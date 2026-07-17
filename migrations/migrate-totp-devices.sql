-- SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
-- SnapSmack: TOTP trusted-device tokens
-- Creates snap_totp_devices to store long-lived trust tokens that let users
-- skip the TOTP interstitial on devices they have explicitly trusted.
-- Each row represents one active trust grant; token is stored as a SHA-256
-- hash (never plain-text). Expired or revoked rows can be pruned at any time.

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

-- ===== SNAPSMACK EOF =====
