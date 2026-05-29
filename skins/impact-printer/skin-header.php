<?php
/**
 * SNAPSMACK - Skin header for the impact-printer skin
 * Alpha v0.7.7
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */



require_once dirname(__DIR__, 2) . '/core/font-loader.php';
snapsmack_emit_font_tags([
    $settings['header_font_family']  ?? 'DotMatrix-Expanded-Bold',
    $settings['body_font_family']    ?? 'DotMatrix',
    $settings['static_heading_font'] ?? 'DotMatrix-Bold',
    $settings['footer_font_family']  ?? 'DotMatrix',
], BASE_URL);
?>
<div id="ip-header">
    <div class="ip-header-inside">
        <?php include(dirname(__DIR__, 2) . '/core/header.php'); ?>
    </div>
</div>
<?php // ===== SNAPSMACK EOF =====
