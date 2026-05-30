<?php
/**
 * SNAPSMACK - Slickr Skin Meta Templates
 * Spec v0.1 — Flickr visual idiom clone for archive migrations.
 *
 * @author Sean McCormick
 */

/**
 * SNAPSMACK_HEADER_PROTECTION
 * <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
?>
<link rel="stylesheet" href="<?php echo BASE_URL . 'skins/' . htmlspecialchars($settings['skin']) . '/style.css?v=' . htmlspecialchars($skin_manifest['version'] ?? '1.0'); ?>">
<?php // ===== SNAPSMACK EOF =====