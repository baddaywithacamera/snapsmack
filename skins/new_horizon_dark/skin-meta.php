<?php
/**
 * SNAPSMACK - Meta tags for the new_horizon_dark skin
 * Alpha v0.6
 *
 * Includes core meta tags and loads the skin stylesheet.
 */

// Include core meta tags for SEO and CSS
include(dirname(__DIR__, 2) . '/core/meta.php');

// Load the skin's main stylesheet
$skin_css_url = BASE_URL . 'skins/' . ($settings['active_skin'] ?? 'new_horizon_dark') . '/style.css';
?>
<link rel="stylesheet" href="<?php echo $skin_css_url; ?>?v=<?php echo time(); ?>">
