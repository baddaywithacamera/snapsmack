<?php
/**
 * SNAPSMACK - Photogram Archive Layout
 * Alpha v0.7.3
 *
 * 3-column square-crop grid. Used for paginated archive pages and tag pages.
 * Also embedded inline in landing.php for the profile grid.
 *
 * Variables from archive.php:
 *   $images       — array of image rows
 *   $settings     — snap_settings key-value array
 *   $all_cats     — all categories (for filter UI)
 *   $all_albums   — all albums
 *   $cat_filter   — active category filter
 *   $album_filter — active album filter
 */
?>

<?php if (!empty($images)): ?>
<div class="pg-grid">
    <?php foreach ($images as $img):
        $link = BASE_URL . htmlspecialchars($img['img_slug']);

        // Prefer square thumbnail; fall back to constructing from full image path
        if (!empty($img['img_thumb_square'])) {
            $thumb = BASE_URL . ltrim($img['img_thumb_square'], '/');
        } elseif (!empty($img['img_file'])) {
            $fp    = pathinfo(ltrim($img['img_file'], '/'));
            $thumb = BASE_URL . $fp['dirname'] . '/thumbs/t_' . $fp['basename'];
        } else {
            $thumb = '';
        }
    ?>
    <a href="<?php echo $link; ?>"
       class="pg-grid-cell"
       title="<?php echo htmlspecialchars($img['img_title']); ?>"
       aria-label="<?php echo htmlspecialchars($img['img_title']); ?>">
        <?php if ($thumb): ?>
            <img src="<?php echo $thumb; ?>"
                 alt=""
                 loading="lazy">
        <?php endif; ?>
        <!-- Carousel badge — Phase 2 -->
    </a>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="pg-grid">
    <div class="pg-grid-empty">No photos yet.</div>
</div>
<?php endif; ?>
