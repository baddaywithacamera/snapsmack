<?php
/**
 * SNAPSMACK - The Grid Hashtag Archive
 * Alpha v0.7.9
 *
 * 3-column square tile grid scoped to $requested_tag.
 * $requested_tag is set and validated by index.php before inclusion.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$tag_slug    = $requested_tag;
$tag_display = '#' . $tag_slug;

$now_local = date('Y-m-d H:i:s');
$per_page  = 30;
$curr_page = max(1, (int)($_GET['p'] ?? 1));
$offset    = ($curr_page - 1) * $per_page;

$carousel_ind  = $settings['tg_carousel_indicator'] ?? 'icon';
$hover_overlay = $settings['tg_hover_overlay']      ?? 'dark';

// ── Fetch tag record ──────────────────────────────────────────────────────────
$tag_stmt = $pdo->prepare("SELECT id, use_count FROM snap_tags WHERE slug = ? LIMIT 1");
$tag_stmt->execute([$tag_slug]);
$tag_row = $tag_stmt->fetch(PDO::FETCH_ASSOC);

// ── Fetch tagged images (with post_id for dedup) ──────────────────────────────
if ($tag_row) {
    $img_stmt = $pdo->prepare("
        SELECT
            i.id              AS img_id,
            i.img_title       AS title,
            i.img_slug,
            i.img_file,
            i.img_thumb_square,
            i.post_id
        FROM snap_images i
        JOIN snap_image_tags it ON it.image_id = i.id
        WHERE it.tag_id    = ?
          AND i.img_status = 'published'
          AND i.img_date  <= ?
        ORDER BY i.sort_order ASC, i.id DESC
        LIMIT ? OFFSET ?
    ");
    $img_stmt->execute([$tag_row['id'], $now_local, $per_page, $offset]);
    $raw_images  = $img_stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_count = (int)$tag_row['use_count'];
} else {
    $raw_images  = [];
    $total_count = 0;
}

// ── Build tile list: deduplicate carousel posts, fetch cover + count ──────────
$tiles         = [];
$seen_post_ids = [];

foreach ($raw_images as $img_row) {
    if (!empty($img_row['post_id'])) {
        if (isset($seen_post_ids[$img_row['post_id']])) continue;
        $seen_post_ids[$img_row['post_id']] = true;

        $cover_stmt = $pdo->prepare("
            SELECT i.id AS img_id, i.img_file, i.img_thumb_square, i.img_slug,
                   p.title,
                   (SELECT COUNT(*) FROM snap_post_images spi
                    WHERE spi.post_id = p.id AND spi.sort_position >= 0) AS image_count
            FROM snap_posts p
            JOIN snap_post_images pi ON pi.post_id = p.id AND pi.is_cover = 1
            JOIN snap_images i ON i.id = pi.image_id
            WHERE p.id = ?
        ");
        $cover_stmt->execute([$img_row['post_id']]);
        $tile = $cover_stmt->fetch(PDO::FETCH_ASSOC);
        if ($tile) $tiles[] = $tile;
    } else {
        // Standalone image — no post container
        $tiles[] = [
            'img_id'           => $img_row['img_id'],
            'img_file'         => $img_row['img_file'],
            'img_thumb_square' => $img_row['img_thumb_square'],
            'img_slug'         => $img_row['img_slug'],
            'title'            => $img_row['title'],
            'image_count'      => 1,
        ];
    }
}

$has_more = ($offset + count($raw_images)) < $total_count;
?>

<?php include(__DIR__ . '/skin-meta.php'); ?>

<div class="tg-content-wrap">

<?php include __DIR__ . '/skin-profile.php'; ?>

<div id="tg-app">

    <!-- ── Tag Header ──────────────────────────────────────────────────────── -->
    <div class="tg-archive-header">
        <a href="<?php echo BASE_URL; ?>" class="tg-back-link">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            Back
        </a>
        <h2><?php echo htmlspecialchars($tag_display); ?></h2>
        <p><?php echo number_format($total_count); ?> post<?php echo $total_count !== 1 ? 's' : ''; ?></p>
    </div>

    <!-- ── Grid ────────────────────────────────────────────────────────────── -->
    <main class="tg-grid" aria-label="<?php echo htmlspecialchars($tag_display); ?> photos">

        <?php if (!empty($tiles)): ?>
            <?php foreach ($tiles as $tile):
                $thumb_src   = $tile['img_thumb_square'] ?: $tile['img_file'];
                $post_url    = BASE_URL . '?s=' . urlencode($tile['img_slug'] ?? '');
                $image_count = (int)($tile['image_count'] ?? 1);
                $is_carousel = $image_count > 1;
                $title_safe  = htmlspecialchars($tile['title'] ?? '');
            ?>
            <div class="tg-tile">
                <a href="<?php echo $post_url; ?>"
                   title="<?php echo $title_safe; ?>"
                   aria-label="<?php echo $title_safe; ?>">
                    <img src="<?php echo htmlspecialchars($thumb_src); ?>"
                         alt="<?php echo $title_safe; ?>"
                         loading="lazy">
                </a>

                <?php if ($is_carousel && $carousel_ind !== 'none'): ?>
                    <div class="tg-tile-indicator">
                        <?php if ($carousel_ind === 'icon'): ?>
                            <span class="tg-tile-indicator--icon" aria-label="<?php echo $image_count; ?> images">⧉</span>
                        <?php else: ?>
                            <span class="tg-tile-indicator--count"><?php echo $image_count; ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($hover_overlay === 'title' || $hover_overlay === 'count'): ?>
                    <div class="tg-tile-overlay" aria-hidden="true">
                        <span class="tg-tile-overlay-text">
                            <?php if ($hover_overlay === 'title'): ?>
                                <?php echo $title_safe; ?>
                            <?php else: ?>
                                <?php echo $image_count; ?> image<?php echo $image_count !== 1 ? 's' : ''; ?>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php elseif ($hover_overlay === 'dark'): ?>
                    <div class="tg-tile-overlay tg-tile-overlay--dark" aria-hidden="true"></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="tg-grid-empty">
                No photos tagged <?php echo htmlspecialchars($tag_display); ?> yet.
            </div>
        <?php endif; ?>

    </main>

    <?php if ($has_more): ?>
        <div id="tg-sentinel"
             data-tag="<?php echo htmlspecialchars($tag_slug); ?>"
             data-next="<?php echo $curr_page + 1; ?>"
             data-base="<?php echo htmlspecialchars(BASE_URL); ?>"
             style="height:1px;"></div>
    <?php endif; ?>

</div><!-- /#tg-app -->

</div><!-- /.tg-content-wrap -->

<?php /* Hashtag infinite scroll moved to assets/js/ss-engine-tag-infinite.js
   (shared, prefix-derived; loaded via the skin manifest). The #tg-sentinel
   div above is its hook — no inline JS in skins. */ ?>

<?php include('skin-footer.php'); ?>
<?php // ===== SNAPSMACK EOF =====
