<?php
/**
 * SNAPSMACK - Migration 046: Tool API Key
 *
 * Seeds the tool_api_key setting used by SYBU and other companion tools
 * for session-free API authentication via the X-Snap-Key request header.
 * Key is empty by default — generated in Admin → Settings → API Access.
 */

$migration_name = '046_tool_api_key';

try {
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM snap_migrations WHERE migration_name = ?"
    );
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    $pdo->prepare(
        "INSERT IGNORE INTO snap_settings (setting_key, setting_val)
         VALUES ('tool_api_key', '')"
    )->execute();

    $pdo->prepare(
        "INSERT INTO snap_migrations (migration_name, applied_at)
         VALUES (?, NOW())"
    )->execute([$migration_name]);

    return ['status' => 'ok', 'message' => 'tool_api_key setting seeded.'];

} catch (PDOException $e) {
    return ['status' => 'error', 'message' => $e->getMessage()];
}
