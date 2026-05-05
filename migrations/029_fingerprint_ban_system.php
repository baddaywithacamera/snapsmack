<?php
/**
 * SNAPSMACK - Migration 029: Browser Fingerprint + Ban System
 *
 * Adds browser fingerprint storage to both comment tables and creates the
 * centralised ban list used to block persistent harassers who evade IP bans
 * via residential VPNs or dynamic IPs.
 *
 * Changes:
 *   snap_comments          — adds fp_hash varchar(64)
 *   snap_community_comments — adds fp_hash varchar(64)
 *   snap_ban_list (NEW)    — stores fingerprint, IP, and email-hash bans
 *
 * USAGE: Run via the migration runner or directly via PHP CLI.
 * This migration is idempotent (safe to run multiple times).
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once __DIR__ . '/../core/db.php';

$migration_name        = "029_fingerprint_ban_system";
$migration_description = "Add fp_hash to comment tables; create snap_ban_list";

// --- IDEMPOTENCY CHECK ---
try {
    $pdo->query("SELECT fp_hash FROM snap_comments LIMIT 1");
    exit("Migration $migration_name already applied.\n");
} catch (PDOException $e) {
    // Column doesn't exist — proceed
}

try {

    // ── snap_comments: add fp_hash ────────────────────────────────────────────
    $pdo->exec("
        ALTER TABLE `snap_comments`
            ADD COLUMN `fp_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                COMMENT 'SHA-256 browser fingerprint collected at submission time'
                AFTER `comment_ip`,
            ADD KEY `idx_fp_hash` (`fp_hash`)
    ");
    echo "  altered  snap_comments (added fp_hash)\n";

    // ── snap_community_comments: add fp_hash ──────────────────────────────────
    $pdo->exec("
        ALTER TABLE `snap_community_comments`
            ADD COLUMN `fp_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL
                COMMENT 'SHA-256 browser fingerprint collected at submission time'
                AFTER `ip`,
            ADD KEY `idx_fp_hash` (`fp_hash`)
    ");
    echo "  altered  snap_community_comments (added fp_hash)\n";

    // ── snap_ban_list: new table ───────────────────────────────────────────────
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `snap_ban_list` (
            `id`          int unsigned     NOT NULL AUTO_INCREMENT,
            `ban_type`    enum('fingerprint','ip','email_hash')
                          COLLATE utf8mb4_unicode_ci NOT NULL,
            `ban_value`   varchar(255)     COLLATE utf8mb4_unicode_ci NOT NULL,
            `reason`      varchar(500)     COLLATE utf8mb4_unicode_ci DEFAULT NULL
                          COMMENT 'Admin note — not shown to the banned user',
            `banned_at`   datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `banned_by`   int unsigned     DEFAULT NULL
                          COMMENT 'snap_users.id of the admin who created this ban',
            PRIMARY KEY (`id`),
            UNIQUE KEY  `uq_ban`       (`ban_type`, `ban_value`),
            KEY         `idx_type_val` (`ban_type`, `ban_value`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Centralised ban list: fingerprint, IP, and email-hash bans'
    ");
    echo "  created  snap_ban_list\n";

    echo "\nMigration $migration_name applied successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
// ===== SNAPSMACK EOF =====
