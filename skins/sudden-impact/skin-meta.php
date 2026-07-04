<?php
/**
 * SNAPSMACK - The Grid Skin Meta
 * Alpha v0.7.9
 *
 * Page title tag. Included by core/meta.php.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// SUDDEN IMPACT: load the DotMatrix family (thermal dot-matrix type) from the
// shared CMS font-loader — the same mechanism IMPACT PRINTER uses, no skin-local
// font files. Emitted before core/meta.php so the faces are in <head>.
require_once dirname(__DIR__, 2) . '/core/font-loader.php';
if (function_exists('snapsmack_emit_font_tags')) {
    snapsmack_emit_font_tags(['DotMatrix-Expanded-Bold', 'DotMatrix', 'DotMatrix-Bold']);
}

include dirname(__DIR__, 2) . '/core/meta.php';
// ===== SNAPSMACK EOF =====
