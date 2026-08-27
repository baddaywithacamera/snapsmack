<?php
/**
 * SNAPSMACK - Meta tags + palette loader for the ONYX skin
 * ONYX 0.1.9
 *
 * ONYX is a minimal dark editorial skin. Geometry lives in style.css; colour is
 * a swappable palette. Each palette is a variant-<name>.css that sets only the
 * accent vars, loaded AFTER core/meta.php so it layers over the geometry. The
 * manifest "variants" block auto-spawns the SKIN PALETTE dropdown; the choice
 * persists as active_skin_variant. Palettes are birthstones: garnet (the default,
 * the original crimson-red), topaz, emerald, amethyst, aquamarine, sapphire.
 * This lets sibling FEDISTRUCTURE blogs tell themselves apart at a glance.
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
$onyx_variants = ['garnet', 'topaz', 'emerald', 'amethyst', 'aquamarine', 'sapphire'];
$onyx_variant  = $settings['active_skin_variant'] ?? 'garnet';
if (!in_array($onyx_variant, $onyx_variants, true)) { $onyx_variant = 'garnet'; }
?>
<link rel="stylesheet" href="<?php echo $onyx_skin_url; ?>variant-<?php echo $onyx_variant; ?>.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<?php // Favicons are deliberately NOT hardcoded here. core/meta.php (included above)
      // emits them from the per-install favicon_url setting (Admin -> Global Vibe),
      // so a generic skin never stamps one site's brand icon onto every install. ?>
<?php // ===== SNAPSMACK EOF =====
