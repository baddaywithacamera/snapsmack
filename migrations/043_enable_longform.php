<?php
/**
 * SNAPSMACK - Migration 043: Enable Longform (SmackTalk) setting
 *
 * Adds enable_longform to snap_settings, defaulting to 0 (off).
 * The New Longform Post link in the sidebar is gated on this flag.
 * Existing installs stay in photo/solo mode unless explicitly opted in.
 */

function migration_043_up(PDO $pdo): void {
    $pdo->exec("
        INSERT IGNORE INTO snap_settings (setting_key, setting_val)
        VALUES ('enable_longform', '0')
    ");
}
// EOF
