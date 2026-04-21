<?php
/**
 * SNAPSMACK - Migration 034: Ban Sync Spoke Cursor
 *
 * Adds the snap_settings key that tracks when a spoke last pushed its bans
 * to the hub. On the hub side this is a column (migration 033). On the spoke
 * side it is a settings key because spokes have exactly one hub relationship.
 *
 * Also seeds the ban_sync_enabled_spokes setting used by the hub to track
 * which spokes have ever successfully completed a ban sync handshake.
 */

function migration_034(PDO $pdo): void {

    // Spoke: timestamp of last outbound ban push to hub.
    // Empty = never synced (hub will receive all existing bans on first sync).
    $pdo->exec("
        INSERT IGNORE INTO `snap_settings` (setting_key, setting_val)
        VALUES ('ban_hub_last_sync_at', '')
    ");

    // Hub: JSON array of spoke node IDs that support ban-sync.
    // Updated automatically when a spoke responds successfully to a ban-sync call.
    $pdo->exec("
        INSERT IGNORE INTO `snap_settings` (setting_key, setting_val)
        VALUES ('ban_sync_capable_spokes', '[]')
    ");
}
