<?php
/**
 * SNAPSMACK - The Grid Hashtag Archive
 * Alpha v0.7.4
 *
 * 3-column square tile grid scoped to $requested_tag.
 * $requested_tag is set and validated by index.php before inclusion.
 */

$tag_slug    = $requested_tag;
$tag_display = '#' . $tag_slug;

$now_local = date('Y-m-d H:i:s');
$per_page  = 30;
$curr_page = max(1, (int)($_GET['p'] ?? 1));
$offset    = ($curr_page - 1) * $per_page;

// Read skin settings (already loaded by index.php but re-scoped here for clarity)
$tg_tile_gap     = (int)($settings['tg_tile_gap']     ?? 2);
$tg_tile_radius  = (int)($settings['tg_tile_radius']  ?? 0);
$tg_grid_width   = (int)($settings['tg_grid_max_width'] ?? 935);
$tg_hover_style  = $settings['tg_hover_overlay'] ?? 'count';

// ── Fetch tag record ──────────────────────────────────────────────────────────
$tag_stmt = $pdo->prepare("SELECT id, use_count FROM snap_tags WHERE slug = ? LIMIT 1");
$tag_stmt->execute([$tag_slug]);
$tag_row = $tag_stmt->fetch(PDO::FETCH_ASSOC);

// ── Fetch tagged images ───────────────────────────────────────────────────────
if ($tag_row) {
    $grid_stmt = $pdo->prepare("
        SELECT
            i.id          AS img_id,
            i.img_title   AS title,
            i.img_slug,
            i.img_file,
            i.img_thumb_square,
            1             AS image_count
        FROM snap_images i
        JOIN snap_image_tags it ON it.image_id = i.id
        WHERE it.tag_id     = ?
          AND i.img_status  = 'published'
          AND i.img_date   <= ?
        ORDER BY i.img_date DESC
        LIMIT ? OFFSET ?
    ");
    $grid_stmt->execute([$tag_row['id'], $now_local, $per_page, $offset]);
    $grid_posts  = $grid_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_count = (int)$tag_row['use_count'];
} else {
    $grid_posts  = [];
    $total_count = 0;
}
$has_more = ($offset + count($grid_posts)) < $total_count;
?>

<?php include('skin-meta.php'); ?>
<?php include('skin-header.php'); ?>

<div id="tg-app">

    <!-- ── Tag Header ──────────────────────────────────────────────────────── -->
    <div class="tg-archive-header" style="max-width:<?php echo $tg_grid_width; ?>px; margin:0 auto; padding:20px 12px 8px;">
        <a href="<?php echo BASE_URL; ?>" class="tg-back-link" style="display:inline-flex; align-items:center; gap:6px; text-decoration:none; opacity:.7; font-size:13px; margin-bottom:12px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            Back
        </a>
        <h2 style="font-size:22px; font-weight:700; margin:0 0 4px;"><?php echo htmlspecialchars($tag_display); ?></h2>
        <p style="font-size:13px; opacity:.6; margin:0;"><?php echo number_format($total_count); ?> post<?php echo $total_count !== 1 ? 's' : ''; ?></p>
    </div>

    <!-- ── Grid ────────────────────────────────────────────────────────────── -->
    <main class="tg-grid" style="
        max-width: <?php echo $tg_grid_width; ?>px;
        --tg-gap: <?php echo $tg_tile_gap; ?>px;
        --tg-radius: <?php echo $tg_tile_radius; ?>px;
    " aria-label="<?php echo htmlspecialchars($tag_display); ?> photos">

        <?php if (!empty($grid_posts)): ?>
            <?php foreach ($grid_posts as $post):
                $thumb_src   = $post['img_thumb_square'] ?: $post['img_file'];
                $post_url    = BASE_URL . $post['img_slug'];
                $title_safe  = htmlspecialchars($post['title']);
            ?>
            <div class="tg-tile">
                <a href="<?php echo htmlspecialchars($post_url); ?>"
                   title="<?php echo $title_safe; ?>"
                   aria-label="<?php echo $title_safe; ?>">
                    <?php if ($thumb_src): ?>
                        <img src="<?php echo BASE_URL . ltrim($thumb_src, '/'); ?>"
                             alt="<?php echo $title_safe; ?>"
                             loading="lazy">
                    <?php endif; ?>
                    <?php if ($tg_hover_style === 'title'): ?>
                        <div class="tg-tile-overlay"><span><?php echo $title_safe; ?></span></div>
                    <?php endif; ?>
                </a>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="tg-grid-empty" style="grid-column:1/-1; text-align:center; padding:60px 20px; opacity:.5;">
                No photos tagged <?php echo htmlspecialchars($tag_display); ?> yet.
            </div>
        <?php endif; ?>

    </main>

    <?php if ($has_more): ?>
        <div class="tg-load-more-wrap">
            <a href="<?php echo BASE_URL . '?tag=' . rawurlencode($tag_slug) . '&p=' . ($curr_page + 1); ?>"
               class="tg-load-more-btn">Load more</a>
        </div>
    <?php endif; ?>

</div><!-- /#tg-app -->

<?php include('skin-footer.php'); ?>
