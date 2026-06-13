<?php
/**
 * SNAPSMACK - Slickr Skin Header
 * Flickr-idiom: clean light header, site name left, nav right.
 *
 * @author Sean McCormick
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$site_display_name = $site_name ?? 'SNAPSMACK';
?>
<header id="sl-header">
    <div class="sl-header-inner">
        <a href="<?php echo BASE_URL; ?>" class="sl-logo-link">
            <span class="sl-masthead"><?php echo htmlspecialchars($site_display_name); ?></span>
        </a>
        <nav class="sl-header-nav">
            <?php include(dirname(__DIR__, 2) . '/core/header.php'); ?>
        </nav>
    </div>
</header>
<?php // ===== SNAPSMACK EOF =====
