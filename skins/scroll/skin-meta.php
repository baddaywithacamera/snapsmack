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
// ===== SNAPSMACK EOF =====
