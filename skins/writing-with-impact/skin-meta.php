<?php
/**
 * SNAPSMACK - Meta tags + stylesheet loader for the WRITING WITH IMPACT skin
 * v1.0.0
 *
 * Includes core meta (SEO, OG, canonical, and the auto-generated skin-option CSS
 * block via custom_css_public — :root{--wwi-ink} and .post-inner width). The
 * dot-matrix @font-face declarations are emitted globally by core/meta.php from
 * manifest-inventory local_fonts, and are also declared defensively in style.css.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

include dirname(__DIR__, 2) . '/core/meta.php';

$skin_slug = $settings['active_skin'] ?? 'writing-with-impact';
$skin_base = BASE_URL . 'skins/' . $skin_slug . '/';
$v         = SNAPSMACK_VERSION_SHORT;
?>
<link rel="stylesheet" href="<?php echo $skin_base; ?>style.css?v=<?php echo $v; ?>">
<?php // ===== SNAPSMACK EOF =====
