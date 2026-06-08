<?php
/**
 * SNAPSMACK - Photogram Archive Layout
 * Alpha v0.7.9
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

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */



// ── Carousel image counts — single query for all posts in this page ─────────
$_pg_post_image_counts = [];
if (!empty($images)) {
    $_pg_post_ids = array_filter(array_unique(array_column($images, 'post_id')));
    if ($_pg_post_ids) {
        $_pg_ph  = implode(',', array_fill(0, count($_pg_post_ids), '?'));
        $_pg_cnt = $pdo->prepare(
            "SELECT post_id, COUNT(*) AS cnt FROM snap_post_images WHERE post_id IN ($_pg_ph) GROUP BY post_id"
        );
        $_pg_cnt->execute(array_values($_pg_post_ids));
        $_pg_post_image_counts = $_pg_cnt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
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

        $_pg_img_count = (!empty($img['post_id']) && isset($_pg_post_image_counts[$img['post_id']]))
            ? (int)$_pg_post_image_counts[$img['post_id']]
            : 1;
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
        <?php if ($_pg_img_count > 1): ?>
        <span class="pg-carousel-badge" aria-label="<?php echo $_pg_img_count; ?> images">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="2" y="6" width="14" height="14" rx="2"/>
                <path d="M6 2h14a2 2 0 0 1 2 2v14"/>
            </svg>
            <?php echo $_pg_img_count; ?>
        </span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="pg-grid">
    <div class="pg-grid-empty">No photos yet.</div>
</div>
<?php endif; ?>
<?php // ===== SNAPSMACK EOF =====
