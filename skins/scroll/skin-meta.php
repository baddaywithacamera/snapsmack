<?php
/**
 * SNAPSMACK — SCROLL skin metadata.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once dirname(__DIR__, 2) . '/core/font-loader.php';
snapsmack_emit_font_tags([
    $settings['scroll_masthead_font'] ?? 'Archivo Black',
    'DM Sans'
], BASE_URL);
include dirname(__DIR__, 2) . '/core/meta.php';
// SCROLL draws the social dock INLINE in its header on every page. Core only loads the
// dock stylesheet from footer-scripts.php (landing page). Load it here so archive /
// about / blogroll get the same dock as the landing. (SCROLL 0.1.49)
echo '<link rel="stylesheet" href="' . BASE_URL . 'assets/css/ss-engine-social-dock.css?v=' . SNAPSMACK_VERSION_SHORT . '">' . "
";
// ===== SNAPSMACK EOF =====
