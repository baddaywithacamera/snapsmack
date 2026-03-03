<?php
/**
 * SNAPSMACK - Meta tags for the fifty-shades-of-grey skin
 * Alpha v0.6
 *
 * Includes core meta tags and loads the appropriate greyscale variant stylesheet.
 */

// Include core meta tags for SEO and CSS
include(dirname(__DIR__, 2) . '/core/meta.php');

// Load the appropriate variant stylesheet
$allowed_variants = ['dark', 'medium', 'light'];
$active_variant   = $settings['active_skin_variant'] ?? 'dark';
if (!in_array($active_variant, $allowed_variants)) {
    $active_variant = 'dark';
}
$variant_url = BASE_URL . 'skins/' . ($settings['active_skin'] ?? 'fifty_shades_of_grey') . '/variant-' . $active_variant . '.css';
?>
<link rel="stylesheet" href="<?php echo $variant_url; ?>?v=<?php echo time(); ?>">
