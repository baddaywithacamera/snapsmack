<?php
/**
 * SNAPSMACK - Meta tags + palette loader for the ONYX skin
 * ONYX 0.1.6
 *
 * ONYX is a minimal dark editorial skin. Geometry lives in style.css; colour is
 * a swappable palette. Each palette is a variant-<name>.css that sets only the
 * accent vars, loaded AFTER core/meta.php so it layers over the geometry. The
 * manifest "variants" block auto-spawns the SKIN PALETTE dropdown; the choice
 * persists as active_skin_variant. (Crimson is simply the default palette now --
 * this replaces the old single-look CRIMSON ONYX.)
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// Core meta tags, SEO, and the compiled skin stylesheet (style.css = geometry).
include(dirname(__DIR__, 2) . '/core/meta.php');

$onyx_skin_url = BASE_URL . 'skins/' . ($settings['active_skin'] ?? 'onyx') . '/';

// Colour palette: load the selected variant AFTER core/meta.php so it sits on
// top of the geometry. Unknown value falls back to the default palette.
$onyx_variants = ['crimson', 'sapphire', 'emerald'];
$onyx_variant  = $settings['active_skin_variant'] ?? 'crimson';
if (!in_array($onyx_variant, $onyx_variants, true)) { $onyx_variant = 'crimson'; }
?>
<link rel="stylesheet" href="<?php echo $onyx_skin_url; ?>variant-<?php echo $onyx_variant; ?>.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<link rel="icon" type="image/png" sizes="32x32" href="<?php echo $onyx_skin_url; ?>img/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="<?php echo $onyx_skin_url; ?>img/favicon-16.png">
<link rel="icon" href="<?php echo $onyx_skin_url; ?>img/favicon.ico" sizes="any">
<link rel="apple-touch-icon" sizes="180x180" href="<?php echo $onyx_skin_url; ?>img/apple-touch-icon.png">
<?php // ===== SNAPSMACK EOF =====
