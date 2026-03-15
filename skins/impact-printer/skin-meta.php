<?php
/**
 * SNAPSMACK - Meta tags for the impact-printer skin
 * Alpha v0.7.3a
 *
 * Includes core meta tags and loads the appropriate paper texture variant stylesheet.
 */

// Include core meta tags for SEO and CSS
include(dirname(__DIR__, 2) . '/core/meta.php');

// Load the appropriate variant stylesheet
$allowed_variants = ['greenbar', 'plain'];
$active_variant   = $settings['active_skin_variant'] ?? 'greenbar';
if (!in_array($active_variant, $allowed_variants)) {
    $active_variant = 'greenbar';
}
$variant_url = BASE_URL . 'skins/' . ($settings['active_skin'] ?? 'impact-printer') . '/variant-' . $active_variant . '.css';
?>
<link rel="stylesheet" href="<?php echo $variant_url; ?>?v=<?php echo time(); ?>">
