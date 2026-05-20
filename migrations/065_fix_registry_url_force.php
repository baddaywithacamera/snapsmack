<?php
/**
 * SNAPSMACK - Migration 065: Force-correct skin registry URL
 *
 * Migration 064 used a conditional UPDATE that only fired if the stored
 * value exactly matched the old wrong URL. If the value differed in any
 * way (trailing slash, http vs https, etc.) the UPDATE silently did nothing
 * but the migration was marked applied — so the fix never landed.
 *
 * This migration unconditionally writes the correct URL regardless of
 * whatever is currently stored. Custom registry URLs pointing to a
 * non-snapsmack.ca host are left alone.
 *
 * Idempotent.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$migration_name = '065_fix_registry_url_force';

try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM snap_migrations WHERE migration = ?");
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    // Force-correct any snapsmack.ca registry URL to the right path.
    // Only touches rows that point to snapsmack.ca — custom registries untouched.
    $pdo->prepare("
        UPDATE snap_settings
        SET setting_val = 'https://snapsmack.ca/releases/skins/registry.json'
        WHERE setting_key = 'skin_registry_url'
          AND setting_val LIKE '%snapsmack.ca%'
    ")->execute();

    $pdo->prepare("INSERT INTO snap_migrations (migration) VALUES (?)")->execute([$migration_name]);

    return ['status' => 'ok', 'message' => 'skin_registry_url force-corrected.'];

} catch (\Throwable $e) {
    return ['status' => 'error', 'message' => $e->getMessage()];
}
// ===== SNAPSMACK EOF =====
