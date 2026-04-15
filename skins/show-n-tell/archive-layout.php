<?php
/**
 * Show N Tell - Archive Layout
 *
 * Justified grid archive. Uses fjGallery engine.
 * Variables available from archive.php: $images, $settings, $all_cats, $all_albums,
 * $cat_filter, $album_filter, $archive_layout
 */

$row_height = (int)($settings['htbs_grid_row_height'] ?? 280);
?>

<div class="snt-archive-grid">
    <div class="snt-justified-grid" data-justified data-row-height="<?php echo $row_height; ?>" data-gap="6">
        <?php if ($images): ?>
            <?php foreach ($images as $img):
                $link = BASE_URL . htmlspecialchars($img['img_slug']);
                $src = !empty($img['img_thumb_aspect'])
                    ? BASE_URL . ltrim($img['img_thumb_aspect'], '/')
                    : BASE_URL . ltrim($img['img_file'], '/');
                $w = (int)($img['img_width'] ?: 800);
                $h = (int)($img['img_height'] ?: 600);
            ?>
                <a href="<?php echo $link; ?>" data-width="<?php echo $w; ?>" data-height="<?php echo $h; ?>">
                    <img src="<?php echo $src; ?>"
                         alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                         loading="lazy">
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="dim" style="text-align:center;padding:40px;">No images found.</p>
        <?php endif; ?>
    </div>
</div>
