<?php
/**
 * SNAPSMACK - Migration 060: Archive border settings
 *
 * Replaces the single archive_frame_style with two independent border
 * controls: one for grid/cropped thumbs and one for masonry/justified thumbs.
 * Seeds defaults matching the old border_thin grey style so existing sites
 * don't see a visual change on upgrade.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$migration_name = '060_archive_border_settings';

$already = $pdo->prepare("SELECT COUNT(*) FROM snap_migrations WHERE migration_name = ?");
$already->execute([$migration_name]);
if ((int)$already->fetchColumn() > 0) {
    echo "Migration $migration_name already applied — skipping.\n";
    return;
}

// Seed new settings (defaults match old border_thin appearance).
$seeds = [
    'archive_grid_border_width'    => '1',
    'archive_grid_border_color'    => '#888888',
    'archive_masonry_border_width' => '1',
    'archive_masonry_border_color' => '#888888',
];

$stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = setting_val");
foreach ($seeds as $k => $v) {
    $stmt->execute([$k, $v]);
}

$pdo->prepare("INSERT INTO snap_migrations (migration_name, applied_at) VALUES (?, NOW())")
    ->execute([$migration_name]);

echo "Migration $migration_name completed.\n";
// ===== SNAPSMACK EOF =====
