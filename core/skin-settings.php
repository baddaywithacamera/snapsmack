<?php
/**
 * SNAPSMACK - Skin Settings Overlay
 * Alpha v0.7.1
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
    $prefix     = $skin_slug . '__';
    $prefix_len = strlen($prefix);

    foreach ($settings as $key => $val) {
        if (strpos($key, $prefix) === 0) {
            $bare_key = substr($key, $prefix_len);
            $settings[$bare_key] = $val;
        }
    }
}
