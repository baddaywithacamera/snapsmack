<?php
/**
 * SNAPSMACK - Migration 044
 *
 * Adds login_slug and login_recovery_key settings for the custom login URL feature.
 * login_slug:         the URL segment that routes to snap-in.php (default: snap-in)
 * login_recovery_key: pre-shared token for snap-in.php?key=TOKEN slug-hint recovery
 */

function migration_044_login_slug(PDO $pdo): void {
    $pdo->exec("INSERT IGNORE INTO snap_settings (setting_key, setting_val) VALUES
        ('login_slug',         'snap-in'),
        ('login_recovery_key', '')
    ");
}
