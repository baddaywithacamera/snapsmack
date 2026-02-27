<?php
/**
 * Fifty Shades of Grey — Skin Meta
 * Version: 2.0
 */

// 1. INCLUDE CORE META
include(dirname(__DIR__, 2) . '/core/meta.php');

// 2. VARIANT LOADER
$allowed_variants = ['dark', 'medium', 'light'];
$active_variant   = $settings['active_skin_variant'] ?? 'dark';
if (!in_array($active_variant, $allowed_variants)) {
    $active_variant = 'dark';
}
$variant_url = BASE_URL . 'skins/' . ($settings['active_skin'] ?? 'fifty_shades_of_grey') . '/variant-' . $active_variant . '.css';
?>
<link rel="stylesheet" href="<?php echo $variant_url; ?>?v=<?php echo time(); ?>">
