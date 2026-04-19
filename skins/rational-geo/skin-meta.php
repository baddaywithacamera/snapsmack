<?php
/**
 * SNAPSMACK - Meta tags for the Rational Geo skin
 * v1.0
 *
 * Includes core meta, loads variant stylesheet, loads Marcellus + editorial fonts.
 */

include(dirname(__DIR__, 2) . '/core/meta.php');

$allowed_variants = ['light', 'dark'];
$active_variant   = $settings['active_skin_variant'] ?? 'light';
if (!in_array($active_variant, $allowed_variants)) {
    $active_variant = 'light';
}
$variant_url = BASE_URL . 'skins/' . ($settings['active_skin'] ?? 'rational-geo') . '/variant-' . $active_variant . '.css';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Marcellus&family=Playfair+Display:ital,wght@0,400;0,700;0,900;1,400&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo $variant_url; ?>?v=<?php echo time(); ?>">
