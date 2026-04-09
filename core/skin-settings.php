<?php
/**
 * SNAPSMACK - Skin Settings Overlay
 * Alpha v0.7.9c
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

function snapsmack_apply_skin_settings(array &$settings, string $skin_slug): void
{
    // Keys owned exclusively by Global Vibe — skin-scoped copies must never
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
        'browse_cols',
        'exif_display_enabled',
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
