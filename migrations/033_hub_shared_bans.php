<?php
/**
 * SNAPSMACK - Migration 033: Hub Shared Bans
 *
 * Creates the snap_hub_shared_bans table (hub installations only — harmless
 * on spokes since it is created IF NOT EXISTS).
 *
 * Also adds ban_sync_cursor to snap_multisite_nodes so the hub can track
 * per-spoke sync deltas, and seeds the hub_spoke_ban_sync setting (disabled
 * by default so operators consciously opt in).
 */

function migration_033(PDO $pdo): void {

    // ── Shared ban registry (hub) ─────────────────────────────────────────────
    // Stores consolidated cross-spoke ban hashes. report_count tracks how many
    // distinct spokes have reported the same identifier — used as a trust signal.
    // removed=1 lets a hub admin clear a false-positive without deleting the row
    // (preserves the audit trail while stopping distribution).

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `snap_hub_shared_bans` (
          `id`            int unsigned     NOT NULL AUTO_INCREMENT,
          `ban_type`      enum('fingerprint','ip','email_hash')
                          COLLATE utf8mb4_unicode_ci NOT NULL,
          `ban_value`     char(64)         COLLATE utf8mb4_unicode_ci NOT NULL
                          COMMENT 'SHA-256 hex. Never a raw IP or email.',
          `reason`        varchar(64)      COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
          `reported_by`   varchar(255)     COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''
                          COMMENT 'URL of the spoke that first reported this hash.',
          `first_seen`    datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `last_seen`     datetime         NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
          `report_count`  int unsigned     NOT NULL DEFAULT 1
                          COMMENT 'Number of distinct spokes that have reported this hash.',
          `removed`       tinyint(1)       NOT NULL DEFAULT 0
                          COMMENT '1 = manually cleared by hub admin; excluded from distribution.',
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_type_val` (`ban_type`, `ban_value`),
          KEY `idx_last_seen` (`last_seen`),
          KEY `idx_removed`   (`removed`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
          COMMENT='Consolidated cross-spoke ban registry (SnapSmack Shield Tier 1)';
    ");

    // ── Ban sync cursor on multisite nodes ────────────────────────────────────
    // Lets the hub track when it last successfully synced bans with each spoke,
    // so subsequent syncs only transmit deltas. Only meaningful on hub installs
    // but harmless on spoke installs (snap_multisite_nodes may exist either way).

    try {
        // Check if the table exists before altering
        $table_exists = $pdo->query("
            SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'snap_multisite_nodes'
        ")->fetchColumn();

        if ($table_exists) {
            $col_exists = $pdo->query("
                SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE()
                  AND table_name   = 'snap_multisite_nodes'
                  AND column_name  = 'ban_sync_cursor'
            ")->fetchColumn();

            if (!$col_exists) {
                $pdo->exec("
                    ALTER TABLE `snap_multisite_nodes`
                    ADD COLUMN `ban_sync_cursor` datetime DEFAULT NULL
                    COMMENT 'Hub: timestamp of last successful ban sync with this spoke.'
                    AFTER `last_seen_at`
                ");
            }
        }
    } catch (PDOException $e) {
        // Non-fatal — sync will fall back to full list on first run
    }

    // ── Default setting ───────────────────────────────────────────────────────
    // Off by default. Admin enables in Community Settings → Shield section.

    $pdo->exec("
        INSERT IGNORE INTO `snap_settings` (setting_key, setting_val)
        VALUES ('hub_spoke_ban_sync', '0')
    ");
}
