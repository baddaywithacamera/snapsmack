<?php
/**
 * SNAPSMACK - Meta tags and stylesheet loader for the Alfred skin
 * v1.0.0
 *
 * Includes core meta tags (SEO, OG, custom_css_public which carries
 * skin option CSS such as --alfred-accent and .post-inner width),
 * then loads Alfred's self-hosted fonts and skin stylesheet.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// Core meta: SEO, OG tags, canonical URL, and custom_css_public output
// (custom_css_public contains the auto-generated skin option CSS block,
// including :root { --alfred-accent: ... } and .post-inner { width: ...px })
include dirname(__DIR__, 2) . '/core/meta.php';

$skin_slug = $settings['active_skin'] ?? 'alfred';
$skin_base = BASE_URL . 'skins/' . $skin_slug . '/';
$v         = SNAPSMACK_VERSION_SHORT;
?>
<link rel="stylesheet" href="<?php echo $skin_base; ?>assets/css/font-awesome.css?v=<?php echo $v; ?>">
<link rel="stylesheet" href="<?php echo $skin_base; ?>assets/css/fonts.css?v=<?php echo $v; ?>">
<?php // NOTE: style.css is emitted by core/meta.php (above) with a version+skin-version
      // cache-bust. Do NOT re-load it here — a second <link> double-loads the baseline
      // AFTER the compiled customization CSS and can override user tweaks. (0.7.400) ?>
<?php // ===== SNAPSMACK EOF =====
