<?php
/**
 * SNAPSMACK - Migration 062: Default masonry_use_thumbs setting
 *
 * Sets masonry_use_thumbs = '1' (ON) for all existing installs so the
 * masonry layout uses pre-generated aspect thumbnails by default.
 * New installs get this default via archive.php's isset() fallback;
 * this migration handles existing installs that have no row in snap_settings.
 *
 * Idempotent: skips if already applied or if the setting already exists.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$migration_name = '062_masonry_thumb_quality';

$already = $pdo->prepare("SELECT COUNT(*) FROM snap_migrations WHERE migration_name = ?");
$already->execute([$migration_name]);
if ((int)$already->fetchColumn() > 0) {
    echo "Migration $migration_name already applied — skipping.\n";
    return;
}

// Upsert masonry_use_thumbs = '1' (default ON).
$pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('masonry_use_thumbs', '1') ON DUPLICATE KEY UPDATE setting_val = setting_val")
    ->execute();

echo "Migration $migration_name applied: masonry_use_thumbs defaulted to ON.\n";

$pdo->prepare("INSERT INTO snap_migrations (migration_name, applied_at) VALUES (?, NOW())")
    ->execute([$migration_name]);
// ===== SNAPSMACK EOF =====
