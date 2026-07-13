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

// core/meta.php already emits this skin's style.css (with a version + skin-version
// cache-bust). We do NOT re-load it here: a second <link> double-loads the baseline
// AFTER the compiled customization CSS, which can override user customizations. (0.7.400)
// ===== SNAPSMACK EOF =====
