<?php
/**
 * SNAPSMACK - Migration 050: Search Field Placeholder Setting
 *
 * Adds search_placeholder to snap_settings so each install can label the
 * archive search field independently (useful for multi-blog domains where
 * each blog wants its own wording — "Search articles", "Search photos", etc.).
 *
 * Default value matches the previous hardcoded placeholder.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$migration_name = '050_search_placeholder';

try {
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM snap_migrations WHERE migration = ?"
    );
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    $pdo->prepare(
        "INSERT IGNORE INTO snap_settings (setting_key, setting_val)
         VALUES ('search_placeholder', 'Search or #tag…')"
    )->execute();

    $pdo->prepare(
        "INSERT INTO snap_migrations (migration, applied_at) VALUES (?, NOW())"
    )->execute([$migration_name]);

    return ['status' => 'ok', 'message' => 'search_placeholder seeded.'];

} catch (PDOException $e) {
    return ['status' => 'error', 'message' => $e->getMessage()];
}
// ===== SNAPSMACK EOF =====
