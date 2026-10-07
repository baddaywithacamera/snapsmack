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

    // A short-lived Skin Admin regression copied the currently selected font
    // from one skin into the scoped row of the next skin that was saved.  Once
    // scoped, those values looked intentional and survived the general legacy
    // key repair.  These are the exact combinations observed on the affected
    // installs; discard only those combinations so every other customization
    // remains authoritative.
    $known_font_leaks = [
        'galleria' => [
            'htbs_title_font'   => 'Libre Baskerville',
            'htbs_heading_font' => 'DM Sans',
            'htbs_body_font'    => 'DM Sans',
        ],
        'true-grit' => [
            'header_font_family' => 'Playfair Display',
        ],
        'rational-geo' => [
            'body_font'    => 'DM Sans',
            'comment_font' => 'DM Sans',
        ],
    ];
    $leak_profile = $known_font_leaks[$skin_slug] ?? [];
    if ($leak_profile) {
        $matches_profile = true;
        foreach ($leak_profile as $bare_key => $leaked_value) {
            $scoped_key = $prefix . $bare_key;
            if (!array_key_exists($scoped_key, $settings)
                || (string)$settings[$scoped_key] !== $leaked_value) {
                $matches_profile = false;
                break;
            }
        }
        if ($matches_profile) {
            foreach ($leak_profile as $bare_key => $_leaked_value) {
                unset($settings[$prefix . $bare_key], $settings[$bare_key]);
            }
        }
    }

    // A bare legacy value may belong to whichever skin was saved before
    // per-skin scoping existed.  Do not let it bleed into another skin.  For
    // every option declared by this skin, use its scoped value when one exists
    // and otherwise restore the manifest default.  Without this pass, merely
    // opening and saving a skin can permanently stamp another skin's font,
    // colour, or layout choice into it.
    $manifest_path = dirname(__DIR__) . '/skins/' . $skin_slug . '/manifest.json';
    if (is_file($manifest_path)) {
        $manifest = json_decode((string) file_get_contents($manifest_path), true);
        foreach (($manifest['options'] ?? []) as $bare_key => $meta) {
            if (in_array($bare_key, $global_only, true)) {
                continue;
            }
            $scoped_key = $prefix . $bare_key;
            if (array_key_exists($scoped_key, $settings)) {
                $settings[$bare_key] = $settings[$scoped_key];
            } elseif (is_array($meta) && array_key_exists('default', $meta)) {
                $settings[$bare_key] = $meta['default'];
            } else {
                unset($settings[$bare_key]);
            }
        }
    }

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
