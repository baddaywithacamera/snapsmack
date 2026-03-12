<?php
/**
 * SNAPSMACK - Photogram Hashtag Archive
 * Alpha v0.7.3
 *
 * Displays a 3-column grid of all published images tagged with $requested_tag.
 * $requested_tag is set and validated by index.php before this file is included.
 */

$tag_slug    = $requested_tag; // already normalised and validated by index.php
$tag_display = '#' . $tag_slug;

// ── Fetch tag record ──────────────────────────────────────────────────────────
$tag_stmt = $pdo->prepare("SELECT id, use_count FROM snap_tags WHERE slug = ? LIMIT 1");
$tag_stmt->execute([$tag_slug]);
$tag_row = $tag_stmt->fetch(PDO::FETCH_ASSOC);

// ── Fetch images for this tag (paginated) ────────────────────────────────────
$per_page  = 30;
$curr_page = max(1, (int)($_GET['p'] ?? 1));
$offset    = ($curr_page - 1) * $per_page;

if ($tag_row) {
    $grid_stmt = $pdo->prepare("
        SELECT i.id, i.img_title, i.img_slug, i.img_file, i.img_thumb_square
        FROM snap_images i
        JOIN snap_image_tags it ON it.image_id = i.id
        WHERE it.tag_id = ?
          AND i.img_status = 'published'
          AND i.img_date   <= ?
        ORDER BY i.img_date DESC
        LIMIT ? OFFSET ?
    ");
    $grid_stmt->execute([$tag_row['id'], date('Y-m-d H:i:s'), $per_page, $offset]);
    $grid_images = $grid_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_count = (int)$tag_row['use_count'];
} else {
    $grid_images = [];
    $total_count = 0;
}
$has_more = ($offset + count($grid_images)) < $total_count;

// ── Profile / skin data ───────────────────────────────────────────────────────
$site_title  = $settings['site_title'] ?? $site_name ?? 'Photogram';
$pg_active_tab = 'discover'; // nearest semantic match
?>

<?php include('skin-meta.php'); ?>
<?php include('skin-header.php'); ?>

<div id="pg-app">
<div class="pg-content">

    <!-- ── Tag Header ──────────────────────────────────────────────────────── -->
    <header class="pg-profile-header">
        <a href="<?php echo BASE_URL; ?>" class="pg-back-btn" aria-label="Back">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
        <span class="pg-profile-header-title"><?php echo htmlspecialchars($tag_display); ?></span>
    </header>

    <!-- ── Tag Stats ───────────────────────────────────────────────────────── -->
    <section class="pg-profile" aria-label="Tag info">
        <div class="pg-profile-top">
            <div class="pg-avatar-placeholder pg-tag-icon" aria-hidden="true">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/>
                    <line x1="7" y1="7" x2="7.01" y2="7"/>
                </svg>
            </div>
            <div class="pg-profile-stats">
                <div class="pg-stat">
                    <span class="pg-stat-count"><?php echo number_format($total_count); ?></span>
                    <span class="pg-stat-label">Posts</span>
                </div>
            </div>
        </div>
        <div class="pg-profile-bio">
            <span class="pg-display-name"><?php echo htmlspecialchars($tag_display); ?></span>
        </div>
    </section>

    <div class="pg-divider"></div>

    <!-- ── Grid ────────────────────────────────────────────────────────────── -->
    <main class="pg-grid" aria-label="<?php echo htmlspecialchars($tag_display); ?> photos">
        <?php if (!empty($grid_images)): ?>
            <?php foreach ($grid_images as $gi):
                $link = BASE_URL . htmlspecialchars($gi['img_slug']);
                if (!empty($gi['img_thumb_square'])) {
                    $thumb = BASE_URL . ltrim($gi['img_thumb_square'], '/');
                } elseif (!empty($gi['img_file'])) {
                    $fp    = pathinfo(ltrim($gi['img_file'], '/'));
                    $thumb = BASE_URL . $fp['dirname'] . '/thumbs/t_' . $fp['basename'];
                } else {
                    $thumb = '';
                }
            ?>
            <a href="<?php echo $link; ?>"
               class="pg-grid-cell"
               title="<?php echo htmlspecialchars($gi['img_title']); ?>"
               aria-label="<?php echo htmlspecialchars($gi['img_title']); ?>">
                <?php if ($thumb): ?>
                    <img src="<?php echo $thumb; ?>"
                         alt="<?php echo htmlspecialchars($gi['img_title']); ?>"
                         loading="lazy">
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="pg-grid-empty">No photos tagged <?php echo htmlspecialchars($tag_display); ?> yet.</div>
        <?php endif; ?>
    </main>

    <?php if ($has_more): ?>
        <div class="pg-load-more-wrap">
            <a href="<?php echo BASE_URL . '?tag=' . rawurlencode($tag_slug) . '&p=' . ($curr_page + 1); ?>"
               class="pg-load-more-btn">Load more</a>
        </div>
    <?php endif; ?>

</div><!-- /.pg-content -->
</div><!-- /#pg-app -->

<?php include('skin-footer.php'); ?>
