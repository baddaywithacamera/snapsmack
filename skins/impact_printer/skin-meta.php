<?php
/**
 * Impact Printer — Skin Meta
 * Version: 1.0
 */

// 1. INCLUDE CORE META
include(dirname(__DIR__, 2) . '/core/meta.php');

// 2. VARIANT LOADER
$allowed_variants = ['greenbar', 'plain'];
$active_variant   = $settings['active_skin_variant'] ?? 'greenbar';
if (!in_array($active_variant, $allowed_variants)) {
    $active_variant = 'greenbar';
}
$variant_url = BASE_URL . 'skins/' . ($settings['active_skin'] ?? 'impact-printer') . '/variant-' . $active_variant . '.css';
?>
<link rel="stylesheet" href="<?php echo $variant_url; ?>?v=<?php echo time(); ?>">
