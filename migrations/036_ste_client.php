<?php
/**
 * SNAPSMACK - Migration 036: SMACK THE ENEMY Client
 *
 * Creates snap_ste_scores (local score cache from the network reputation system)
 * and seeds the settings keys that control STE participation.
 */

function migration_036(PDO $pdo): void {

    // --- CREATE SCORE CACHE TABLE ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `snap_ste_scores` (
            `ban_type`     VARCHAR(20)  NOT NULL COMMENT 'ip, email, fingerprint',
            `ban_hash`     VARCHAR(64)  NOT NULL COMMENT 'SHA-256 hash of the raw value',
            `score`        FLOAT        NOT NULL DEFAULT 0,
            `colour_level` VARCHAR(10)  NOT NULL DEFAULT 'green'
                           COMMENT 'green, yellow, orange, red, black',
            `last_updated` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                           ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`ban_type`, `ban_hash`),
            KEY `idx_colour` (`colour_level`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        COMMENT='Local cache of SMACK THE ENEMY network scores'
    ");

    // --- SEED SETTINGS ---
    $seeds = [
        // '0' = not participating, '1' = active member
        ['ste_enabled',          '0'],

        // Bearer token returned by sc-enemy-api.php?route=register
        ['ste_api_key',          ''],

        // Auto-ban threshold: never | yellow | orange | red | black
        ['ste_auto_ban_threshold', 'red'],

        // ISO-8601 timestamp of last successful scores/delta pull
        ['ste_scores_cursor',    ''],

        // Unix timestamp of last heartbeat sent
        ['ste_last_heartbeat',   '0'],
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO snap_settings (setting_key, setting_val)
        VALUES (?, ?)
    ");
    foreach ($seeds as [$key, $val]) {
        $stmt->execute([$key, $val]);
    }
}
// EOF
