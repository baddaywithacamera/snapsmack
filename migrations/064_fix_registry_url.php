<?php
/**
 * SNAPSMACK - Migration 064: Fix skin registry URL
 *
 * The default registry URL was incorrectly set to
 * https://snapsmack.ca/skins/registry.json but the Skin Packager
 * writes files to releases/skins/. The correct URL is
 * https://snapsmack.ca/releases/skins/registry.json.
 *
 * Only corrects the value if it still matches the old wrong URL —
 * sites that have overridden it to a custom registry are left alone.
 *
 * Idempotent.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$migration_name = '064_fix_registry_url';

try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM snap_migrations WHERE migration = ?");
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    // Only fix the URL if it still has the old wrong value.
    // Sites with a custom registry URL are left untouched.
    $pdo->prepare("
        UPDATE snap_settings
        SET setting_val = 'https://snapsmack.ca/releases/skins/registry.json'
        WHERE setting_key = 'skin_registry_url'
          AND setting_val = 'https://snapsmack.ca/skins/registry.json'
    ")->execute();

    $pdo->prepare("INSERT INTO snap_migrations (migration) VALUES (?)")->execute([$migration_name]);

    return ['status' => 'ok', 'message' => 'skin_registry_url corrected.'];

} catch (\Throwable $e) {
    return ['status' => 'error', 'message' => $e->getMessage()];
}
// ===== SNAPSMACK EOF =====
