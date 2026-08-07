<?php
/**
 * SNAPSMACK - Skin header for the CRIMSON ONYX skin
 * CRIMSON ONYX 0.1.0
 *
 * The photofri.day top bar: brand on the left, page links on the right, hairline
 * rule underneath. The links are NOT hardcoded — core/header.php builds the nav
 * from the active static pages in menu_order, so HOW IT WORKS / WHUT THE WHUT /
 * JOIN THE PARTY appear because those pages exist, and Sean can add a fourth
 * without touching this skin.
 *
 * No font loader: the design is Arial Black / Georgia / Courier New, all system
 * faces. Porting it verbatim means not fetching a web font it never used.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
?>
<div id="pfd-header" data-sticky-header>
    <div class="pfd-header-inside">
        <?php include(dirname(__DIR__, 2) . '/core/header.php'); ?>
    </div>
</div>
<?php // ===== SNAPSMACK EOF =====
