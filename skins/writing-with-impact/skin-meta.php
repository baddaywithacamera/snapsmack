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

// core/meta.php already emits this skin's style.css (with a version + skin-version
// cache-bust). We do NOT re-load it here: a second <link> double-loads the baseline
// AFTER the compiled customization CSS, which can override user customizations. (0.7.400)
// ===== SNAPSMACK EOF =====
