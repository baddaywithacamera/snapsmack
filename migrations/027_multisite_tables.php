<?php
/**
 * SNAPSMACK - Migration 027: Multisite Tables
 *
 * Creates snap_multisite_nodes and snap_multisite_queue tables to support
 * hub-and-spoke architecture. Also adds multisite-related settings keys
 * to snap_settings.
 *
 * USAGE: Run via the system updates or migration runner.
 * This migration is idempotent (safe to run multiple times).
 */

require_once __DIR__ . '/../core/db.php';

$migration_name = "027_multisite_tables";
$migration_description = "Create multisite management tables";

// --- CHECK IF ALREADY APPLIED ---
try {
    $existing = $pdo->query("SELECT COUNT(*) FROM snap_multisite_nodes")->fetchColumn();
    // If the table exists, skip
    exit("Migration $migration_name already applied.\n");
} catch (PDOException $e) {
    // Table doesn't exist; proceed with migration
}

// --- CREATE TABLES ---
try {
    // --- MULTISITE NODES TABLE ---
    // Stores information about connected hub or spoke sites
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `snap_multisite_nodes` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `role` enum('hub','spoke') NOT NULL COMMENT 'hub=we manage this spoke, spoke=we are managed by this hub',
            `site_url` varchar(500) NOT NULL COMMENT 'Full base URL of the remote site',
            `site_name` varchar(255) DEFAULT NULL COMMENT 'Display name of the site',
            `api_key_local` varchar(255) NOT NULL COMMENT 'Key that the remote site uses to authenticate requests to us',
            `api_key_remote` varchar(255) NOT NULL COMMENT 'Key that we use to authenticate requests to the remote site',
            `software_version` varchar(50) DEFAULT NULL COMMENT 'Remote site version (from heartbeat)',
            `last_seen_at` datetime DEFAULT NULL COMMENT 'Last successful API contact with remote site',
            `post_count` int unsigned DEFAULT 0 COMMENT 'Number of posts on remote site (cached)',
            `image_count` int unsigned DEFAULT 0 COMMENT 'Number of images on remote site (cached)',
            `pending_comments` int unsigned DEFAULT 0 COMMENT 'Unapproved comments on remote site (cached)',
            `last_backup_at` datetime DEFAULT NULL COMMENT 'When the remote site last performed a backup',
            `last_backup_size` bigint unsigned DEFAULT NULL COMMENT 'Size of last backup in bytes',
            `last_backup_dest` varchar(100) DEFAULT NULL COMMENT 'Where the last backup was stored (local, cloud, ftp, etc)',
            `last_backup_status` enum('ok','failed','unknown') DEFAULT 'unknown' COMMENT 'Status of the last backup',
            `disk_usage_bytes` bigint unsigned DEFAULT NULL COMMENT 'Total disk usage on remote site',
            `status` enum('active','offline','disconnected') DEFAULT 'active' COMMENT 'Connection status',
            `connected_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this node was registered',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_site_url` (`site_url`),
            KEY `idx_role_status` (`role`, `status`),
            KEY `idx_last_seen` (`last_seen_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Hub-and-spoke multisite management nodes'
    ");

    echo "Created snap_multisite_nodes table.\n";

    // --- MULTISITE QUEUE TABLE ---
    // Stores pending and processed API actions for retry logic
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `snap_multisite_queue` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `node_id` int unsigned NOT NULL COMMENT 'Reference to snap_multisite_nodes.id',
            `action` varchar(50) NOT NULL COMMENT 'Action type: comment_approve, comment_reject, sync_posts, etc',
            `payload` text COMMENT 'JSON-encoded action parameters',
            `status` enum('pending','processing','completed','failed') DEFAULT 'pending',
            `attempts` tinyint unsigned DEFAULT 0 COMMENT 'Number of attempted executions',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `processed_at` datetime DEFAULT NULL COMMENT 'When the action was completed or failed',
            PRIMARY KEY (`id`),
            KEY `idx_node_status` (`node_id`, `status`),
            KEY `idx_created` (`created_at`),
            CONSTRAINT `fk_queue_node` FOREIGN KEY (`node_id`)
                REFERENCES `snap_multisite_nodes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Queue for asynchronous multisite operations'
    ");

    echo "Created snap_multisite_queue table.\n";

} catch (PDOException $e) {
    echo "ERROR: Failed to create tables.\n";
    echo "Details: " . $e->getMessage() . "\n";
    exit(1);
}

// --- SEED SETTINGS ---
try {
    // These settings will be created with empty/default values
    // Actual values are set when the user chooses hub/spoke mode

    $settings_to_seed = [
        'multisite_role' => '',           // 'hub', 'spoke', or empty
        'multisite_reg_token' => '',      // One-time registration token
        'multisite_reg_token_expires' => '0', // Unix timestamp of expiry
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO snap_settings (setting_key, setting_val)
        VALUES (?, ?)
    ");

    foreach ($settings_to_seed as $key => $default_val) {
        $stmt->execute([$key, $default_val]);
    }

    echo "Seeded multisite settings.\n";

} catch (PDOException $e) {
    echo "ERROR: Failed to seed settings.\n";
    echo "Details: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nMigration $migration_name completed successfully.\n";
echo "Tables created: snap_multisite_nodes, snap_multisite_queue\n";
echo "Settings added: multisite_role, multisite_reg_token, multisite_reg_token_expires\n";
?>
// EOF
