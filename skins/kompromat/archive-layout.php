<?php
/**
 * SNAPSMACK - JIVE TURKEY Archive Layout
 * Alpha v0.7.9
 *
 * Category and album archive view. Renders the same 3-column grid as the
 * landing page, scoped to the current category or album.
 *
 * Variables from archive.php: $pdo, $settings, $active_skin,
 *   $archive_type ('category' | 'album'), $archive_id, $archive_name,
 *   $archive_desc, $images (pre-fetched image array), $total_images,
 *   $curr_page, $total_pages
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$show_profile  = false; // No profile header in archive views
$carousel_ind  = $settings['jt_carousel_indicator'] ?? 'icon';
$hover_overlay = $settings['jt_hover_overlay']      ?? 'title';

$page_title = htmlspecialchars($archive_name ?? 'Archive');

include dirname(__DIR__, 2) . '/core/meta.php';
?>
<div class="jt-content-wrap">

<?php include __DIR__ . '/skin-profile.php'; ?>

<!-- ── Archive Header ──────────────────────────────────────────────────── -->
<div class="jt-archive-header">
    <h1 class="jt-archive-title"><?php echo htmlspecialchars($archive_name ?? 'Archive'); ?></h1>
    <?php if (!empty($archive_desc)): ?>
        <p class="jt-archive-meta"><?php echo htmlspecialchars($archive_desc); ?></p>
    <?php endif; ?>
    <?php if (!empty($total_images)): ?>
        <p class="jt-archive-meta"><?php echo number_format($total_images); ?> post<?php echo $total_images !== 1 ? 's' : ''; ?></p>
    <?php endif; ?>
</div>

<!-- ── 3-Column Grid (archive scope) ──────────────────────────────────── -->
<main>
    <?php
    // Archive.php passes a flat $images array (snap_images rows).
    // For JIVE TURKEY, look up each image's cover post_id to get post metadata,
    // then render a tile. Fall back to the image itself if no post container.

    // Build a list of (post_id or img_id) → tile data
    $tiles = [];
    $seen_post_ids = [];

    foreach ($images as $img_row) {
        if (!empty($img_row['post_id'])) {
            // Avoid duplicate tiles for multi-image posts (archive may return
            // multiple images from the same post if they all share the same cat)
            if (isset($seen_post_ids[$img_row['post_id']])) continue;
            $seen_post_ids[$img_row['post_id']] = true;

            // Fetch cover image + image count for this post
            $cover_stmt = $pdo->prepare("
                SELECT i.id AS img_id, i.img_file, i.img_thumb_square, i.img_slug,
                       p.title, p.slug AS post_slug, p.post_type,
                       (SELECT COUNT(*) FROM snap_post_images spi
                        WHERE spi.post_id = p.id AND spi.sort_position >= 0) AS image_count
                FROM snap_posts p
                JOIN snap_post_images pi ON pi.post_id = p.id AND pi.is_cover = 1
                JOIN snap_images i ON i.id = pi.image_id
                WHERE p.id = ?
            ");
            $cover_stmt->execute([$img_row['post_id']]);
            $tile = $cover_stmt->fetch();
            if ($tile) $tiles[] = $tile;
        } else {
            // Legacy image with no post container
            $tiles[] = [
                'img_id'      => $img_row['id'],
                'img_file'    => $img_row['img_file'],
                'img_thumb_square' => $img_row['img_thumb_square'],
                'img_slug'    => $img_row['img_slug'],
                'title'       => $img_row['img_title'],
                'post_slug'   => null,
                'post_type'   => 'single',
                'image_count' => 1,
            ];
        }
    }
    ?>

    <div class="jt-grid">
        <?php $jt_idx = 0; foreach ($tiles as $tile):
            $thumb_src   = $tile['img_thumb_square'] ?: $tile['img_file'];
            $post_url    = BASE_URL . '?s=' . urlencode($tile['img_slug'] ?? '');
            $image_count = (int)($tile['image_count'] ?? 1);
            $is_carousel = $image_count > 1;
            $title_safe  = htmlspecialchars($tile['title'] ?? '');
        ?>
        <div class="jt-tile"
             data-row="<?php echo intdiv($jt_idx, 3); ?>" data-col="<?php echo $jt_idx % 3; ?>">
            <div class="jt-ring" aria-hidden="true"></div>
            <a href="<?php echo $post_url; ?>" title="<?php echo $title_safe; ?>">
                <img src="<?php echo htmlspecialchars($thumb_src); ?>"
                     alt="<?php echo $title_safe; ?>"
                     loading="lazy">
            </a>

            <?php if ($is_carousel && $carousel_ind !== 'none'): ?>
                <div class="jt-tile-indicator">
                    <?php if ($carousel_ind === 'icon'): ?>
                        <span class="jt-tile-indicator--icon" aria-label="<?php echo $image_count; ?> images">⧉</span>
                    <?php else: ?>
                        <span class="jt-tile-indicator--count"><?php echo $image_count; ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($hover_overlay === 'title' || $hover_overlay === 'count'): ?>
                <div class="jt-tile-overlay" aria-hidden="true">
                    <span class="jt-tile-overlay-text">
                        <?php if ($hover_overlay === 'title'): ?>
                            <?php echo $title_safe; ?>
                        <?php else: ?>
                            <?php echo $image_count; ?> image<?php echo $image_count !== 1 ? 's' : ''; ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php elseif ($hover_overlay === 'dark'): ?>
                <div class="jt-tile-overlay jt-tile-overlay--dark" aria-hidden="true"></div>
            <?php endif; ?>
        </div>
        <?php $jt_idx++; endforeach; ?>

        <?php if (empty($tiles)): ?>
        <div style="grid-column: 1/-1; padding: 60px 20px; text-align: center; color: var(--text-secondary);">
            <p>No posts in this <?php echo $archive_type === 'album' ? 'album' : 'category'; ?> yet.</p>
        </div>
        <?php endif; ?>
    </div>

</main>

</div><!-- /.jt-content-wrap -->

<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
