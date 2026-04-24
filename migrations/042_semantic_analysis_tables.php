<?php
/**
 * SNAPSMACK - Migration 030b: Semantic Analysis Tables
 *
 * Creates snap_comments_semantic (comment text + TF-IDF vectors for
 * semantic troll detection) and snap_keywords (banned keyword/phrase list).
 *
 * NOTE: This file has a duplicate prefix — another 030_ migration exists.
 * It should be renamed to 042_semantic_analysis_tables.php via:
 *   git mv migrations/030_semantic_analysis_tables.php migrations/042_semantic_analysis_tables.php
 *
 * USAGE: Run via the migration runner or directly via PHP CLI.
 * This migration is idempotent (safe to run multiple times).
 */

require_once __DIR__ . '/../core/db.php';

$migration_name        = '030_semantic_analysis_tables';
$migration_description = 'Create snap_comments_semantic and snap_keywords tables';

// --- IDEMPOTENCY CHECK ---
try {
    $pdo->query('SELECT 1 FROM snap_comments_semantic LIMIT 1');
    exit("Migration {$migration_name} already applied.\n");
} catch (PDOException $e) {
    // Table doesn't exist — proceed
}

try {

    // snap_comments_semantic: stores comment text + TF-IDF vectors for
    // semantic analysis used by the troll-detection engine.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `snap_comments_semantic` (
            `id`               INT            NOT NULL AUTO_INCREMENT,
            `comment_id`       INT            NOT NULL,
            `fingerprint_hash` VARCHAR(255)   COLLATE utf8mb4_unicode_ci NOT NULL,
            `comment_text`     LONGTEXT       COLLATE utf8mb4_unicode_ci NOT NULL,
            `tfidf_vector`     JSON           DEFAULT NULL,
            `created_at`       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_scs_fingerprint` (`fingerprint_hash`),
            KEY `idx_scs_created`     (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // snap_keywords: banned keywords and phrases for content-based filtering.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `snap_keywords` (
            `id`         INT            NOT NULL AUTO_INCREMENT,
            `keyword`    VARCHAR(500)   COLLATE utf8mb4_unicode_ci NOT NULL,
            `match_type` ENUM('exact','substring','regex') NOT NULL DEFAULT 'substring',
            `severity`   ENUM('flag','reject')             NOT NULL DEFAULT 'flag',
            `reason`     VARCHAR(255)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `added_at`   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `added_by`   VARCHAR(100)   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_kw_keyword` (`keyword`),
            KEY `idx_kw_keyword` (`keyword`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    echo "Migration {$migration_name} applied: snap_comments_semantic + snap_keywords created.\n";

} catch (PDOException $e) {
    echo "Migration {$migration_name} FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
