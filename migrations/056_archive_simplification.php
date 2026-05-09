<?php
/**
 * SNAPSMACK - Migration 056: Archive layout simplification + calendar decoupling
 *
 * Pre-0.7.79 model: archive_layouts_available was a CSV of allowed layouts
 * (square, cropped, croppedwithcalendar, masonry). Visitors saw a 3- or 4-way
 * toggle. Calendar visibility was tied to the 'croppedwithcalendar' pseudo-
 * layout, which forced a full page reload and locked calendar to the cropped
 * grid only.
 *
 * 0.7.79 model: thumb style is an admin choice (square OR cropped, applies to
 * the "thumbs" layout regardless of which skin is active). Layout toggle is
 * binary (thumbs ↔ masonry). Calendar is fully independent of layout — a
 * separate on/off control that overlays on either layout.
 *
 * New settings introduced here:
 *   archive_thumb_style          'square' | 'cropped'   (default 'cropped')
 *   archive_calendar_enabled     '0' | '1'              (default '0')
 *   archive_calendar_default_open '0' | '1'             (default '0')
 *   archive_show_layout_toggle   '0' | '1'              (default '1')
 *
 * archive_layouts_available is preserved for backwards-compat reads but is
 * no longer authoritative — we narrow it to 'thumbs,masonry' on save.
 *
 * Migration logic from old → new:
 *   - Had 'square' in available  → archive_thumb_style = 'square'
 *   - Had 'cropped' or 'croppedwithcalendar' (and not 'square') → 'cropped'
 *   - Had 'croppedwithcalendar'  → archive_calendar_enabled = 1
 *   - Had 2+ entries (square+masonry, cropped+masonry, etc.) → toggle = 1
 *   - Had only 1 layout entry → toggle = 0 (no point)
 *
 * Idempotent.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$migration_name = '056_archive_simplification';

try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM snap_migrations WHERE migration = ?");
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    // Read current archive_layouts_available
    $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'archive_layouts_available' LIMIT 1");
    $stmt->execute();
    $existing = $stmt->fetchColumn();
    $available = [];
    if ($existing !== false) {
        $available = array_map('trim', explode(',', (string)$existing));
        $available = array_filter($available, fn($v) => $v !== '');
    }

    // Derive new values
    $thumb_style = in_array('square', $available, true) ? 'square' : 'cropped';
    $calendar_enabled = in_array('croppedwithcalendar', $available, true) ? '1' : '0';

    // Toggle on iff admin had at least one thumbs-style AND masonry available
    $had_thumbs = in_array('square', $available, true)
               || in_array('cropped', $available, true)
               || in_array('croppedwithcalendar', $available, true);
    $had_masonry = in_array('masonry', $available, true);
    $show_toggle = ($had_thumbs && $had_masonry) ? '1' : (empty($available) ? '1' : '0');

    // Default layout: read existing default and map to 'thumbs' or 'masonry'
    $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'archive_layout_default' LIMIT 1");
    $stmt->execute();
    $old_default = (string)$stmt->fetchColumn();
    $new_default = ($old_default === 'masonry') ? 'masonry' : 'thumbs';

    // Helper: upsert a setting
    $upsert = function(string $key, string $val) use ($pdo) {
        $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
        )->execute([$key, $val]);
    };

    $upsert('archive_thumb_style',           $thumb_style);
    $upsert('archive_calendar_enabled',      $calendar_enabled);
    $upsert('archive_calendar_default_open', '0');
    $upsert('archive_show_layout_toggle',    $show_toggle);
    $upsert('archive_layout_default',        $new_default);

    // Narrow archive_layouts_available to the new vocabulary.
    // archive.php's read code falls back to this value if new keys are absent;
    // keeping it sane prevents weird states on rollback.
    $upsert('archive_layouts_available', 'thumbs,masonry');

    $pdo->prepare("INSERT INTO snap_migrations (migration, applied_at) VALUES (?, NOW())")
        ->execute([$migration_name]);

    return [
        'status' => 'ok',
        'message' => "Archive simplified: thumb_style={$thumb_style}, calendar_enabled={$calendar_enabled}, default={$new_default}, toggle={$show_toggle}.",
    ];

} catch (Throwable $e) {
    return ['status' => 'error', 'message' => 'Migration 056 failed: ' . $e->getMessage()];
}
// ===== SNAPSMACK EOF =====
