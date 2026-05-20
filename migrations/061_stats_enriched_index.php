<?php
/**
 * SNAPSMACK - Migration 061: Composite index on snap_stats for enriched fleet queries
 *
 * The fleet stats enriched endpoint queries snap_stats grouped by image_id,
 * filtered on is_bot=0 and optionally hit_at >= DATE_SUB(). Without a
 * composite index this is a full table scan on high-traffic installs.
 * Adds idx_stats_enriched (is_bot, hit_at, image_id).
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$migration_name = '061_stats_enriched_index';

$already = $pdo->prepare("SELECT COUNT(*) FROM snap_migrations WHERE migration_name = ?");
$already->execute([$migration_name]);
if ((int)$already->fetchColumn() > 0) {
    echo "Migration $migration_name already applied — skipping.\n";
    return;
}

// Add composite index if it doesn't already exist (idempotent check)
$idx_exists = $pdo->query("
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE()
      AND table_name   = 'snap_stats'
      AND index_name   = 'idx_stats_enriched'
")->fetchColumn();

if (!$idx_exists) {
    $pdo->exec("ALTER TABLE snap_stats ADD INDEX idx_stats_enriched (is_bot, hit_at, image_id)");
    echo "Index idx_stats_enriched created on snap_stats.\n";
} else {
    echo "Index idx_stats_enriched already exists — skipping ALTER.\n";
}

$pdo->prepare("INSERT INTO snap_migrations (migration_name, applied_at) VALUES (?, NOW())")
    ->execute([$migration_name]);

echo "Migration $migration_name completed.\n";
// ===== SNAPSMACK EOF =====
