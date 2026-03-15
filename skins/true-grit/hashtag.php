<?php
/**
 * SNAPSMACK - True Grit Hashtag Archive
 * Alpha v0.7.4
 *
 * Displays a grid of all published images tagged with $requested_tag.
 * $requested_tag is set and validated by index.php before this file is included.
 * Uses the same grid engine as the archive page (square/cropped/masonry).
 */

require_once dirname(__DIR__, 2) . '/core/skin-settings.php';
snapsmack_apply_skin_settings($settings, $active_skin);

$tag_slug    = $requested_tag; // already normalised by index.php
$tag_display = '#' . $tag_slug;

// ── Fetch tag record ──────────────────────────────────────────────────────────
$tag_stmt = $pdo->prepare("SELECT id, use_count FROM snap_tags WHERE slug = ? LIMIT 1");
$tag_stmt->execute([$tag_slug]);
$tag_row = $tag_stmt->fetch(PDO::FETCH_ASSOC);

// ── Fetch images for this tag ─────────────────────────────────────────────────
$per_page  = 60;
$curr_page = max(1, (int)($_GET['p'] ?? 1));
$offset    = ($curr_page - 1) * $per_page;

if ($tag_row) {
    $grid_stmt = $pdo->prepare("
        SELECT i.id, i.img_title, i.img_slug, i.img_file,
               i.img_thumb_square, i.img_thumb_aspect,
               i.img_width, i.img_height
        FROM snap_images i
        JOIN snap_image_tags it ON it.image_id = i.id
        WHERE it.tag_id = ?
          AND i.img_status = 'published'
          AND i.img_date   <= ?
        ORDER BY i.img_date DESC
        LIMIT ? OFFSET ?
    ");
    $grid_stmt->execute([$tag_row['id'], date('Y-m-d H:i:s'), $per_page, $offset]);
    $images      = $grid_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_count = (int)$tag_row['use_count'];
} else {
    $images      = [];
    $total_count = 0;
}
$has_more    = ($offset + count($images)) < $total_count;
$total_pages = ceil($total_count / $per_page);

// ── Archive layout mode from skin settings ────────────────────────────────────
$archive_layout = $settings['archive_layout'] ?? 'square';
if (!in_array($archive_layout, ['square', 'cropped', 'masonry'])) {
    $archive_layout = 'square';
}
$justified_row_height = (int)($settings['justified_row_height'] ?? 280);
?>

<?php include __DIR__ . '/skin-header.php'; ?>

<div id="infobox">
    <div class="nav-links">
        <div class="center">
            <a href="<?php echo BASE_URL; ?>" class="inactive">HOME</a>
            <span class="sep">/</span>
            <a href="archive.php" class="inactive">ARCHIVE</a>
            <span class="sep">/</span>
            <span class="dim"><?php echo htmlspecialchars(strtoupper($tag_display)); ?></span>
            <span class="sep">—</span>
            <span class="dim"><?php echo number_format($total_count); ?> POST<?php echo $total_count !== 1 ? 'S' : ''; ?></span>
        </div>
    </div>
</div>

<div id="scroll-stage" style="display: block; overflow-y: auto;">

<?php if ($archive_layout === 'masonry'): ?>
    <div id="justified-grid" style="--justified-row-height: <?php echo $justified_row_height; ?>px;">
        <?php if (!empty($images)):
            foreach ($images as $img):
                $link = BASE_URL . htmlspecialchars($img['img_slug']);
                if (!empty($img['img_thumb_aspect'])) {
                    $thumb = BASE_URL . ltrim($img['img_thumb_aspect'], '/');
                } elseif (!empty($img['img_file'])) {
                    $fp    = pathinfo(ltrim($img['img_file'], '/'));
                    $thumb = BASE_URL . $fp['dirname'] . '/thumbs/t_' . $fp['basename'];
                } else {
                    $thumb = '';
                }
                $iw = (int)($img['img_width'] ?? 0);
                $ih = (int)($img['img_height'] ?? 0);
        ?>
            <a href="<?php echo $link; ?>" class="justified-item"
               title="<?php echo htmlspecialchars($img['img_title']); ?>"
               <?php if ($iw && $ih): ?>data-width="<?php echo $iw; ?>" data-height="<?php echo $ih; ?>"<?php endif; ?>>
                <img src="<?php echo $thumb; ?>"
                     alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                     loading="lazy">
            </a>
        <?php endforeach; endif; ?>
    </div>
<?php else: ?>
    <div id="browse-grid" class="<?php echo $archive_layout; ?>-grid">
        <?php if (!empty($images)):
            foreach ($images as $img):
                $link = BASE_URL . htmlspecialchars($img['img_slug']);
                if (!empty($img['img_thumb_square'])) {
                    $thumb = BASE_URL . ltrim($img['img_thumb_square'], '/');
                } elseif (!empty($img['img_file'])) {
                    $fp    = pathinfo(ltrim($img['img_file'], '/'));
                    $thumb = BASE_URL . $fp['dirname'] . '/thumbs/t_' . $fp['basename'];
                } else {
                    $thumb = '';
                }
        ?>
            <div class="thumb-container">
                <a href="<?php echo $link; ?>" class="thumb-link"
                   title="<?php echo htmlspecialchars($img['img_title']); ?>">
                    <img src="<?php echo $thumb; ?>"
                         alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                         loading="lazy">
                </a>
            </div>
        <?php endforeach;
        else: ?>
            <div class="empty-sector-msg">No textures tagged <?php echo htmlspecialchars($tag_display); ?> yet.</div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($total_pages > 1): ?>
    <div class="tg-pagination">
        <?php if ($curr_page > 1): ?>
            <a href="?tag=<?php echo rawurlencode($tag_slug); ?>&p=<?php echo $curr_page - 1; ?>">&laquo; Previous</a>
        <?php endif; ?>
        <span class="tg-page-info">Page <?php echo $curr_page; ?> of <?php echo $total_pages; ?></span>
        <?php if ($has_more): ?>
            <a href="?tag=<?php echo rawurlencode($tag_slug); ?>&p=<?php echo $curr_page + 1; ?>">Next &raquo;</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

</div>

<?php include __DIR__ . '/skin-footer.php'; ?>
