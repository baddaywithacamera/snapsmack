<?php
/**
 * SNAPSMACK - Skin header for the new-horizon skin
 * Alpha v0.7.9
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */



require_once dirname(__DIR__, 2) . '/core/font-loader.php';
snapsmack_emit_font_tags([
    $settings['header_font_family']   ?? 'Playfair Display',
    $settings['static_heading_font']  ?? 'Helvetica Neue',
    $settings['static_body_font']     ?? 'Georgia',
    $settings['footer_font_family']   ?? 'Inter',
], BASE_URL);
?>
<div id="header">
    <div class="inside">
        <?php include(dirname(__DIR__, 2) . '/core/header.php'); ?>
    </div>
</div>
<?php // ===== SNAPSMACK EOF =====
