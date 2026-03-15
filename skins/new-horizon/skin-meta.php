<?php
/**
 * SNAPSMACK - Meta tags for the new-horizon skin
 * Alpha v0.7.3a
 *
 * Includes core meta tags, loads the variant colour stylesheet,
 * then loads the skin's main stylesheet.
 */

// Include core meta tags for SEO and CSS
include(dirname(__DIR__, 2) . '/core/meta.php');

// Load the appropriate variant stylesheet
$allowed_variants = ['dark', 'light'];
$active_variant   = $settings['active_skin_variant'] ?? 'dark';
if (!in_array($active_variant, $allowed_variants)) {
    $active_variant = 'dark';
}
$skin_slug    = $settings['active_skin'] ?? 'new-horizon';
$variant_url  = BASE_URL . 'skins/' . $skin_slug . '/variant-' . $active_variant . '.css';
$skin_css_url = BASE_URL . 'skins/' . $skin_slug . '/style.css';
?>
<link rel="stylesheet" href="<?php echo $variant_url; ?>?v=<?php echo time(); ?>">
<link rel="stylesheet" href="<?php echo $skin_css_url; ?>?v=<?php echo time(); ?>">
