<?php
/**
 * SNAPSMACK - Migration 030: Category archive visibility
 * Alpha v0.7.9g
 *
 * Adds show_in_archive column to snap_categories so individual categories
 * can be hidden from the public archive view while remaining in admin.
 *
 * USAGE: Run via the migration runner or directly via PHP CLI.
 * This migration is idempotent (safe to run multiple times).
 */

require_once __DIR__ . '/../core/db.php';

$migration_name        = "030_categories_show_in_archive";
$migration_description = "Add show_in_archive to snap_categories";

// --- IDEMPOTENCY CHECK ---
try {
    $pdo->query("SELECT show_in_archive FROM snap_categories LIMIT 1");
    exit("Migration $migration_name already applied.\n");
} catch (PDOException $e) {
    // Column doesn't exist — proceed
}

try {

    $pdo->exec("
        ALTER TABLE `snap_categories`
            ADD COLUMN `show_in_archive` tinyint(1) NOT NULL DEFAULT 1
                COMMENT '1 = visible in public archive; 0 = hidden (added 0.7.9f)'
    ");
    echo "  altered  snap_categories (added show_in_archive)\n";

    echo "\nMigration $migration_name applied successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
