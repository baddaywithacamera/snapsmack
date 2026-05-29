<?php
/**
 * SNAPSMACK - Chaplin skin: Art Deco frame — line connector layer
 *
 * Renders the four line connector divs. Included AFTER <img> in layout.php so
 * that DOM order places lines in front of the image — no z-index needed.
 * Ornament divs are in frame-deco.php, included BEFORE <img>.
 *
 * Dimensions and background gradients are set by skin-header.php inline style.
 * CSS in style.css positions them via .chap-line-top/bot/lft/rgt.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
?>
<div class="chap-frame-lines" aria-hidden="true">
    <div class="chap-line chap-line-top"></div>
    <div class="chap-line chap-line-bot"></div>
    <div class="chap-line chap-line-lft"></div>
    <div class="chap-line chap-line-rgt"></div>
</div>
<?php // ===== SNAPSMACK EOF =====
