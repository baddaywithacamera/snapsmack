<?php
/**
 * SNAPSMACK - Migration 063: Re-enable archive layout toggle
 *
 * Migration 056 set archive_show_layout_toggle = '0' for any site whose
 * archive_layouts_available only contained one layout family (e.g. masonry-
 * only or thumbs-only). This was over-restrictive — the toggle is always
 * useful now that both thumbs and masonry are available to every skin.
 *
 * Corrects the value to '1' wherever it was set to '0'. Sites where an
 * admin has explicitly re-disabled the toggle via the Archive Settings UI
 * will remain '0' (idempotent re-runs leave them alone because the migration
 * mark is written regardless).
 *
 * Idempotent.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$migration_name = '063_enable_layout_toggle';

try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM snap_migrations WHERE migration = ?");
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    // Flip '0' → '1'. If the key doesn't exist yet, insert it as '1'.
    $pdo->prepare("
        INSERT INTO snap_settings (setting_key, setting_val)
        VALUES ('archive_show_layout_toggle', '1')
        ON DUPLICATE KEY UPDATE
            setting_val = '1'
    ")->execute();

    $pdo->prepare("INSERT INTO snap_migrations (migration) VALUES (?)")->execute([$migration_name]);

    return ['status' => 'ok', 'message' => 'archive_show_layout_toggle set to 1.'];

} catch (\Throwable $e) {
    return ['status' => 'error', 'message' => $e->getMessage()];
}
// ===== SNAPSMACK EOF =====
