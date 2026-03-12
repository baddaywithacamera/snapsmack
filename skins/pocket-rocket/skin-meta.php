<?php
/**
 * SNAPSMACK - Meta tags for the pocket-rocket skin
 * Alpha v0.7.2
 */

// Include core meta tags for SEO and CSS
include(dirname(__DIR__, 2) . '/core/meta.php');

// Load variant stylesheet (only one, but the system expects it)
$active_variant = $settings['active_skin_variant'] ?? 'default';
$variant_url = BASE_URL . 'skins/' . ($settings['active_skin'] ?? 'pocket-rocket') . '/variant-' . $active_variant . '.css';
?>
<link rel="stylesheet" href="<?php echo $variant_url; ?>?v=<?php echo time(); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
