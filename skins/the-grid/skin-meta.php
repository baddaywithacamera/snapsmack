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


?>
<title><?php
    if (!empty($page_title)) {
        echo htmlspecialchars($page_title) . ' &mdash; ';
    }
    echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack');
?></title>
<?php // ===== SNAPSMACK EOF =====
