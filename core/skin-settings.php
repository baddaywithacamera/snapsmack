<?php
/**
 * SNAPSMACK - Skin Settings Overlay
 *
 * Overlays skin-scoped DB values onto bare setting keys so each skin
 * retains its own customizations independently.
 *
 * When the admin saves skin settings, each key is stored with a skin
 * prefix: e.g. "galleria__htbs_wall_color". This function finds all
 * keys matching the active skin prefix and copies them onto the bare
 * key names so existing code continues to read $settings['htbs_wall_color']
 * transparently.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


function snapsmack_apply_skin_settings(array &$settings, string $skin_slug): void
{
    // Keys owned exclusively by Global Vibe -- skin-scoped copies must never
    // override these, even if a stale prefixed DB row exists from a previous
    // manifest version.
    $global_only = [
        'show_wall_link',
        'wall_rows',
        'wall_gap',
        'wall_reflect',
        'wall_friction',
        'wall_dragweight',
        'wall_theme',
        'active_skin',
        'site_url',
        'site_name',
        'archive_layout',
        'thumb_size',
        'exif_display_enabled',
        // Archive/calendar settings are global (managed via Archive Appearance,
        // not per-skin). Skin-scoped stale copies must never override these.
        'archive_calendar_enabled',
        'archive_calendar_default_open',
        'archive_show_layout_toggle',
        'archive_thumb_style',
        'calendar_side',
        'calendar_months',
        'calendar_post_count',
        // Masonry settings -- global, managed via Archive Appearance.
        'masonry_use_thumbs',
        'justified_row_height',
    ];

    $prefix     = $skin_slug . '__';
    $prefix_len = strlen($prefix);

    foreach ($settings as $key => $val) {
        if (strpos($key, $prefix) === 0) {
            $bare_key = substr($key, $prefix_len);
            if (!in_array($bare_key, $global_only, true)) {
                $settings[$bare_key] = $val;
            }
        }
    }
}
// ===== SNAPSMACK EOF =====
