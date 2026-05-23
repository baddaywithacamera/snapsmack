<?php
/**
 * 52 Card Pickup - Archive Grid Layout
 *
 * Simple square-thumbnail grid for browsing the full archive.
 * Variables available from archive.php: $images, $settings, $all_cats, $all_albums,
 * $cat_filter, $album_filter, $archive_layout
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


?>

<div class="pickup-archive-grid">
    <?php if ($images): ?>
        <?php foreach ($images as $img):
            $link = BASE_URL . htmlspecialchars($img['img_slug']);
            if (!empty($img['img_thumb_square'])) {
                $thumb_url = BASE_URL . ltrim($img['img_thumb_square'], '/');
            } else {
                $full_img_path = ltrim($img['img_file'], '/');
                $filename = basename($full_img_path);
                $folder = str_replace($filename, '', $full_img_path);
                $thumb_url = BASE_URL . $folder . 'thumbs/t_' . $filename;
            }
        ?>
            <a href="<?php echo $link; ?>" class="pickup-archive-item">
                <img src="<?php echo $thumb_url; ?>"
                     alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                     loading="lazy">
            </a>
        <?php endforeach; ?>
    <?php else: ?>
        <p style="grid-column:1/-1;text-align:center;color:var(--text-secondary);">No images found.</p>
    <?php endif; ?>
</div>
<?php // ===== SNAPSMACK EOF =====
