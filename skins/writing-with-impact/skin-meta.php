<?php
/**
 * SNAPSMACK - Meta tags + stylesheet loader for the WRITING WITH IMPACT skin
 * v1.1.0
 *
 * Includes core meta (SEO, OG, canonical, and the auto-generated skin-option CSS
 * block via custom_css_public — :root{--wwi-ink} and .post-inner width). The
 * DotMatrix and Tiny5 faces come from the shared CMS font inventory used by
 * IMPACT PRINTER and SUDDEN IMPACT; no font files are duplicated in this skin.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once dirname(__DIR__, 2) . '/core/font-loader.php';
if (function_exists('snapsmack_emit_font_tags')) {
    snapsmack_emit_font_tags([
        'Tiny5', 'Tiny5-Matrix',
        'DotMatrix', 'DotMatrix-Bold', 'DotMatrix-Italic', 'DotMatrix-BoldItalic',
        'DotMatrix-Condensed', 'DotMatrix-Condensed-Bold',
        'DotMatrix-Condensed-Italic', 'DotMatrix-Condensed-BoldItalic',
        'DotMatrix-Expanded', 'DotMatrix-Expanded-Bold',
        'DotMatrix-Expanded-Italic', 'DotMatrix-Expanded-BoldItalic',
        'DotMatrix-Quad', 'DotMatrix-Quad-Bold',
        'DotMatrix-Quad-Italic', 'DotMatrix-Quad-BoldItalic',
        'DotMatrix-Var', 'DotMatrix-Var-Condensed', 'DotMatrix-Var-Expanded',
        'DotMatrix-VarDuo', 'DotMatrix-VarDuo-Condensed',
        'DotMatrix-VarDuo-Expanded', 'DotMatrix-VarDuo-UltraCondensed',
        'DotMatrix-Duo', 'DotMatrix-Duo-Condensed',
        'DotMatrix-Duo-Expanded', 'DotMatrix-Duo-UltraCondensed',
    ]);
}

include dirname(__DIR__, 2) . '/core/meta.php';

// core/meta.php already emits this skin's style.css (with a version + skin-version
// cache-bust). We do NOT re-load it here: a second <link> double-loads the baseline
// AFTER the compiled customization CSS, which can override user customizations. (0.7.400)
// ===== SNAPSMACK EOF =====
