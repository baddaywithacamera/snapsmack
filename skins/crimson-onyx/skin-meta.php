<?php
/**
 * SNAPSMACK - Meta tags for the CRIMSON ONYX skin
 * CRIMSON ONYX 0.1.0
 *
 * Single-look brand skin: no variant stylesheets. 50 Shades ships three
 * greyscale variants because greyscale is the point of it; CRIMSON ONYX is
 * photofri.day's identity, and an identity with three interchangeable colour
 * schemes is not an identity. Everything adjustable lives in the manifest
 * controls instead, so the look can be tuned without forking the skin.
 *
 * The favicons ride with the skin rather than being uploaded per install —
 * they ARE the brand, and an install of this skin that shows the default
 * SnapSmack favicon is wrong on arrival.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// Core meta tags, SEO, and the compiled skin stylesheet.
include(dirname(__DIR__, 2) . '/core/meta.php');

$pfd_skin_url = BASE_URL . 'skins/' . ($settings['active_skin'] ?? 'crimson-onyx') . '/';
?>
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo $pfd_skin_url; ?>img/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="<?php echo $pfd_skin_url; ?>img/favicon-16.png">
<link rel="icon" href="<?php echo $pfd_skin_url; ?>img/favicon.ico" sizes="any">
<link rel="apple-touch-icon" sizes="180x180" href="<?php echo $pfd_skin_url; ?>img/apple-touch-icon.png">
<?php // ===== SNAPSMACK EOF =====
