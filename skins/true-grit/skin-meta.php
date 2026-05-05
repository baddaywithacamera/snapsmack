<?php
/**
 * SNAPSMACK - Meta tags for the true-grit skin
 * Alpha v0.7.8
 *
 * Includes core meta tags and loads the appropriate variant stylesheet.
 * Sets $skin_variant_url BEFORE including meta.php so the variant loads
 * between style.css and dynamic compiled CSS (admin overrides always win).
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */




// Resolve variant stylesheet URL before meta.php loads
$allowed_variants = ['dark', 'light'];
$active_variant   = $settings['active_skin_variant'] ?? 'dark';
if (!in_array($active_variant, $allowed_variants)) {
    $active_variant = 'dark';
}
$skin_variant_url = BASE_URL . 'skins/' . ($settings['active_skin'] ?? 'true-grit') . '/variant-' . $active_variant . '.css';

// Include core meta tags for SEO and CSS
// meta.php will insert the variant stylesheet at the correct cascade position
include(dirname(__DIR__, 2) . '/core/meta.php');
// ===== SNAPSMACK EOF =====
