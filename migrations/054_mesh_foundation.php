<?php
/**
 * SNAPSMACK - Migration 054: Smack in the Middle (Mesh Mode) foundation
 *
 * Extends snap_multisite_nodes so every install can hold a roster of peers
 * (not just the hub holding spokes / a spoke holding the hub). Each peer
 * row now carries inbound-permission flags so an install can opt out of
 * specific kinds of cross-network traffic from a specific peer without
 * disconnecting entirely.
 *
 * Columns added:
 *   accepts_crosspost     TINYINT — accept cross-posts FROM this peer
 *   accepts_blogroll      TINYINT — accept blogroll syncs FROM this peer
 *   accepts_stats_query   TINYINT — accept stats queries FROM this peer
 *   roster_source         VARCHAR(255) — where we learned this peer ("self"
 *                                        for the hub registering its own
 *                                        spokes, otherwise the hub URL)
 *   last_roster_seen_at   TIMESTAMP — last time the roster sync confirmed
 *                                     this peer is still in the network
 *
 * Idempotent: each ADD COLUMN is column-existence guarded.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$migration_name = '054_mesh_foundation';

try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM snap_migrations WHERE migration = ?");
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    $col_exists = function (string $col) use ($pdo): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'snap_multisite_nodes'
               AND COLUMN_NAME  = ?"
        );
        $stmt->execute([$col]);
        return (int)$stmt->fetchColumn() > 0;
    };

    if (!$col_exists('accepts_crosspost')) {
        $pdo->exec(
            "ALTER TABLE `snap_multisite_nodes`
             ADD COLUMN `accepts_crosspost` TINYINT NOT NULL DEFAULT 1
             COMMENT 'Accept cross-posts FROM this peer'"
        );
    }
    if (!$col_exists('accepts_blogroll')) {
        $pdo->exec(
            "ALTER TABLE `snap_multisite_nodes`
             ADD COLUMN `accepts_blogroll` TINYINT NOT NULL DEFAULT 1
             COMMENT 'Accept blogroll syncs FROM this peer'"
        );
    }
    if (!$col_exists('accepts_stats_query')) {
        $pdo->exec(
            "ALTER TABLE `snap_multisite_nodes`
             ADD COLUMN `accepts_stats_query` TINYINT NOT NULL DEFAULT 1
             COMMENT 'Accept fleet-stats queries FROM this peer'"
        );
    }
    if (!$col_exists('roster_source')) {
        $pdo->exec(
            "ALTER TABLE `snap_multisite_nodes`
             ADD COLUMN `roster_source` VARCHAR(255)
             COLLATE utf8mb4_unicode_ci DEFAULT NULL
             COMMENT 'Where we learned this peer: self, or hub URL'"
        );
    }
    if (!$col_exists('last_roster_seen_at')) {
        $pdo->exec(
            "ALTER TABLE `snap_multisite_nodes`
             ADD COLUMN `last_roster_seen_at` TIMESTAMP NULL DEFAULT NULL
             COMMENT 'Last roster sync that confirmed this peer'"
        );
    }

    // Hub: stamp existing rows as roster_source='self' (hub registered them)
    // Spoke: stamp existing hub row as roster_source='self' too — this install
    //        registered the hub directly via handshake, so the hub row is its
    //        own source-of-truth (not learned from anywhere else).
    $pdo->exec(
        "UPDATE snap_multisite_nodes
         SET roster_source = 'self', last_roster_seen_at = NOW()
         WHERE roster_source IS NULL"
    );

    $pdo->prepare("INSERT INTO snap_migrations (migration, applied_at) VALUES (?, NOW())")
        ->execute([$migration_name]);

    return [
        'status'  => 'ok',
        'message' => 'Mesh foundation columns added. Existing peers marked as roster_source=self.',
    ];

} catch (PDOException $e) {
    return ['status' => 'error', 'message' => $e->getMessage()];
}
// ===== SNAPSMACK EOF =====
