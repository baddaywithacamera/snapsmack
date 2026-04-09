<?php
/**
 * SNAPSMACK - Migration 028: Pages Image Display Columns
 * Alpha v0.7.9b
 *
 * Adds image_size, image_align, and image_shadow columns to snap_pages.
 * These were added to the canonical schema in 0.7.8 but lacked a migration.
 *
 * USAGE: Run via the migration runner or directly via PHP CLI.
 * This migration is idempotent (safe to run multiple times).
 */

require_once __DIR__ . '/../core/db.php';

$migration_name = "028_pages_image_columns";
$migration_description = "Add image_size, image_align, image_shadow to snap_pages";

// --- CHECK IF ALREADY APPLIED ---
try {
    $pdo->query("SELECT image_size FROM snap_pages LIMIT 1");
    exit("Migration $migration_name already applied.\n");
} catch (PDOException $e) {
    // Column doesn't exist; proceed
}

// --- APPLY ---
try {
    $pdo->exec("
        ALTER TABLE `snap_pages`
            ADD COLUMN `image_size`   varchar(20)  NOT NULL DEFAULT 'full'   AFTER `image_asset`,
            ADD COLUMN `image_align`  varchar(20)  NOT NULL DEFAULT 'center' AFTER `image_size`,
            ADD COLUMN `image_shadow` tinyint(1)   NOT NULL DEFAULT 0        AFTER `image_align`
    ");
    echo "Added image_size, image_align, image_shadow to snap_pages.\n";
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nMigration $migration_name completed successfully.\n";
?>
