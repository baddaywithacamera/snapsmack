<?php
/**
 * SNAPSMACK - Migration 048: Calendar Archive Layout
 *
 * Appends 'croppedwithcalendar' to the archive_layouts_available setting
 * so the Cal toggle button appears in the archive for skins that support
 * the smack-calendar engine (50-shades-of-noah-grey, rational-geo).
 *
 * The setting is a comma-separated list. If it doesn't exist yet, it is
 * created with all four standard layouts. If 'croppedwithcalendar' is
 * already present, the migration is a no-op.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */




$migration_name = '048_calendar_layout';

try {
    $check = $pdo->prepare(
        "SELECT COUNT(*) FROM snap_migrations WHERE migration = ?"
    );
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    $row = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'archive_layouts_available'");
    $row->execute();
    $current = $row->fetchColumn();

    if ($current === false) {
        // Setting doesn't exist — seed with all layouts
        $pdo->prepare(
            "INSERT IGNORE INTO snap_settings (setting_key, setting_val)
             VALUES ('archive_layouts_available', 'square,cropped,masonry,croppedwithcalendar')"
        )->execute();
    } else {
        $parts = array_filter(array_map('trim', explode(',', $current)));
        if (!in_array('croppedwithcalendar', $parts, true)) {
            $parts[] = 'croppedwithcalendar';
            $pdo->prepare(
                "UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'archive_layouts_available'"
            )->execute([implode(',', $parts)]);
        }
    }

    $pdo->prepare(
        "INSERT INTO snap_migrations (migration, applied_at) VALUES (?, NOW())"
    )->execute([$migration_name]);

    return ['status' => 'ok', 'message' => 'croppedwithcalendar added to archive_layouts_available.'];

} catch (PDOException $e) {
    return ['status' => 'error', 'message' => $e->getMessage()];
}
// ===== SNAPSMACK EOF =====
