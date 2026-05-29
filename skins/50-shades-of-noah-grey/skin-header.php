<?php
/**
 * SNAPSMACK - Skin header for the 50-shades-of-noah-grey skin
 * Alpha v0.7.9c
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */



require_once dirname(__DIR__, 2) . '/core/font-loader.php';
snapsmack_emit_font_tags([
    $settings['header_font_family']  ?? 'Raleway',
    $settings['static_heading_font'] ?? 'Raleway',
    $settings['static_body_font']    ?? 'DM Sans',
    $settings['footer_font_family']  ?? 'Raleway',
], BASE_URL);
?>
<div id="fsog-header" data-sticky-header>
    <div class="fsog-header-inside">
        <?php include(dirname(__DIR__, 2) . '/core/header.php'); ?>
    </div>
</div>
<?php // ===== SNAPSMACK EOF =====
