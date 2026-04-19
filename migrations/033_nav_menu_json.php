<?php
/**
 * Migration 033 — Add nav_menu_json setting key.
 *
 * Seeds an empty nav_menu_json value in snap_settings.
 * smack-menu.php will auto-build the default menu from existing
 * settings on first load when the value is empty.
 *
 * Idempotent: INSERT IGNORE skips if key already exists.
 */

require_once __DIR__ . '/../core/db.php';

try {
    $pdo->exec(
        "INSERT IGNORE INTO snap_settings (setting_key, setting_val)
         VALUES ('nav_menu_json', '')"
    );
    echo "033: nav_menu_json seeded in snap_settings.\n";
} catch (PDOException $e) {
    echo "033: Error — " . $e->getMessage() . "\n";
}
