<?php
/**
 * SNAPSMACK - Migration 031: Oh Snap! API Keys
 *
 * Creates snap_ohsnap_keys table for storing hashed API keys used by
 * the Oh Snap! remote posting endpoint.
 *
 * USAGE: Run via the migration runner or directly via PHP CLI.
 * This migration is idempotent (safe to run multiple times).
 */

require_once __DIR__ . '/../core/db.php';

$migration_name        = "031_ohsnap_api_keys";
$migration_description = "Create snap_ohsnap_keys table";

// --- IDEMPOTENCY CHECK ---
try {
    $pdo->query("SELECT 1 FROM snap_ohsnap_keys LIMIT 1");
    exit("Migration $migration_name already applied.\n");
} catch (PDOException $e) {
    // Table doesn't exist — proceed
}

try {

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `snap_ohsnap_keys` (
            `id`           int            NOT NULL AUTO_INCREMENT,
            `label`        varchar(100)   COLLATE utf8mb4_unicode_ci NOT NULL
                           COMMENT 'Human-readable label assigned at creation',
            `key_hash`     varchar(64)    COLLATE utf8mb4_unicode_ci NOT NULL
                           COMMENT 'SHA-256 hex digest of the raw key — key itself is never stored',
            `key_prefix`   varchar(8)     COLLATE utf8mb4_unicode_ci NOT NULL
                           COMMENT 'First 8 chars of raw key for identification in the UI',
            `is_active`    tinyint(1)     NOT NULL DEFAULT 1,
            `created_at`   datetime       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `last_used_at` datetime       DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_key_hash` (`key_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  created  snap_ohsnap_keys\n";

    echo "\nMigration $migration_name applied successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
// EOF
