<?php
/**
 * SNAPSMACK - Meta tags + stylesheet loader for the STANLEY skin
 * v1.0.0
 *
 * Includes core meta (SEO, OG, canonical, and the auto-generated skin-option CSS
 * block via custom_css_public — e.g. :root{--stanley-accent} and .post-inner
 * width), then loads STANLEY's stylesheet. Fonts are web-safe (Trebuchet MS /
 * Georgia), matching the original Kubrick, so no font files are shipped.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

include dirname(__DIR__, 2) . '/core/meta.php';

$skin_slug = $settings['active_skin'] ?? 'stanley';
$skin_base = BASE_URL . 'skins/' . $skin_slug . '/';
$v         = SNAPSMACK_VERSION_SHORT;
?>
<link rel="stylesheet" href="<?php echo $skin_base; ?>style.css?v=<?php echo $v; ?>">
<?php // ===== SNAPSMACK EOF =====
