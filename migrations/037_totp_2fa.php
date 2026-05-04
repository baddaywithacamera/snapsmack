<?php
/**
 * SNAPSMACK - Migration 037
 *
 * Adds TOTP two-factor authentication columns to snap_users.
 */

function migration_037_totp_2fa(PDO $pdo): void {
    // totp_secret — Base32 TOTP secret, NULL when 2FA not set up
    $pdo->exec("ALTER TABLE snap_users
        ADD COLUMN IF NOT EXISTS totp_secret        VARCHAR(32)  DEFAULT NULL AFTER force_password_change,
        ADD COLUMN IF NOT EXISTS totp_enabled       TINYINT(1)   NOT NULL DEFAULT 0 AFTER totp_secret,
        ADD COLUMN IF NOT EXISTS totp_recovery_json TEXT         DEFAULT NULL AFTER totp_enabled
    ");
}
// EOF
