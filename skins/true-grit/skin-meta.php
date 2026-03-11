<?php
/**
 * SNAPSMACK - Meta tags for the true-grit skin
 * Alpha v0.7.1
 *
 * Includes core meta tags and loads the appropriate variant stylesheet.
 */

// Include core meta tags for SEO and CSS
include(dirname(__DIR__, 2) . '/core/meta.php');

// Load the appropriate variant stylesheet
$allowed_variants = ['dark', 'light'];
$active_variant   = $settings['active_skin_variant'] ?? 'dark';
if (!in_array($active_variant, $allowed_variants)) {
    $active_variant = 'dark';
}
$variant_url = BASE_URL . 'skins/' . ($settings['active_skin'] ?? 'true-grit') . '/variant-' . $active_variant . '.css';
?>
<link rel="stylesheet" href="<?php echo $variant_url; ?>?v=<?php echo time(); ?>">
