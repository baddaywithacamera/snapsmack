<?php
/**
 * SNAPSMACK - The Grid Landing Page
 * Alpha v0.7.1
 *
 * Classic 3-column photo grid with optional profile header.
 * Queries snap_posts joined to snap_post_images (cover only for
 * single/carousel posts) to build the grid tile list.
 * Panorama posts are rendered as a single cover tile until server-side
 * slicing is implemented in a future build.
 *
 * Variables from index.php: $pdo, $settings, $active_skin, $site_name
 */

$now_local   = date('Y-m-d H:i:s');
$per_page    = 30;
$curr_page   = max(1, (int)($_GET['p'] ?? 1));
$offset      = ($curr_page - 1) * $per_page;

// Read skin settings
$show_profile   = ($settings['tg_profile_header']     ?? '1') === '1';
$carousel_ind   = $settings['tg_carousel_indicator']  ?? 'icon';
$hover_overlay  = $settings['tg_hover_overlay']       ?? 'title';

// ── Post count (for profile header) ──────────────────────────────────────
$count_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM snap_posts WHERE status = 'published' AND created_at <= ?"
);
$count_stmt->execute([$now_local]);
$post_count = (int)$count_stmt->fetchColumn();

// ── Total pages ───────────────────────────────────────────────────────────
$total_pages = max(1, (int)ceil($post_count / $per_page));

// ── Fetch posts with cover image ──────────────────────────────────────────
// One row per post: cover image + image count.
$grid_stmt = $pdo->prepare("
    SELECT
        p.id          AS post_id,
        p.title,
        p.slug        AS post_slug,
        p.post_type,
        p.created_at,
        i.id          AS img_id,
        i.img_file,
        i.img_thumb_square,
        i.img_slug,
        (SELECT COUNT(*)
         FROM snap_post_images spi
         WHERE spi.post_id = p.id
           AND spi.sort_position >= 0)  AS image_count
    FROM snap_posts p
    JOIN snap_post_images pi ON pi.post_id = p.id AND pi.is_cover = 1
    JOIN snap_images i       ON i.id = pi.image_id
    WHERE p.status = 'published'
      AND p.created_at <= ?
    ORDER BY p.created_at DESC
    LIMIT ? OFFSET ?
");
$grid_stmt->execute([$now_local, $per_page, $offset]);
$grid_posts = $grid_stmt->fetchAll();

include dirname(__DIR__, 2) . '/core/meta.php';
include __DIR__ . '/skin-header.php';
?>

<?php if ($show_profile): ?>
<!-- ── Profile Header ──────────────────────────────────────────────────── -->
<section class="tg-profile">
    <div class="tg-profile-avatar">
        <?php
        $avatar_path = $settings['profile_avatar'] ?? '';
        if ($avatar_path && file_exists(__DIR__ . '/../../' . $avatar_path)):
        ?>
            <img src="<?php echo BASE_URL . htmlspecialchars($avatar_path); ?>" alt="Profile avatar">
        <?php else:
            $initials = strtoupper(substr($settings['site_name'] ?? 'S', 0, 1));
        ?>
            <span class="tg-profile-avatar-initials"><?php echo htmlspecialchars($initials); ?></span>
        <?php endif; ?>
    </div>

    <div class="tg-profile-info">
        <h1 class="tg-profile-username"><?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?></h1>

        <div class="tg-profile-stats">
            <div class="tg-profile-stat">
                <span class="tg-profile-stat-num"><?php echo number_format($post_count); ?></span>
                <span class="tg-profile-stat-label">post<?php echo $post_count !== 1 ? 's' : ''; ?></span>
            </div>
        </div>

        <?php
        $bio = trim($settings['site_description'] ?? '');
        if ($bio):
        ?>
            <p class="tg-profile-bio"><?php echo nl2br(htmlspecialchars($bio)); ?></p>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- ── 3-Column Grid ───────────────────────────────────────────────────── -->
<main>
    <div class="tg-grid">
        <?php foreach ($grid_posts as $post):
            $thumb_src   = $post['img_thumb_square'] ?: $post['img_file'];
            $post_url    = BASE_URL . '?id=' . (int)$post['img_id'];
            $image_count = (int)$post['image_count'];
            $is_carousel = $image_count > 1;
            $title_safe  = htmlspecialchars($post['title']);
        ?>
        <div class="tg-tile">
            <a href="<?php echo $post_url; ?>" title="<?php echo $title_safe; ?>">
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

            <?php if ($hover_overlay !== 'none'): ?>
                <div class="tg-tile-overlay" aria-hidden="true">
                    <span class="tg-tile-overlay-text">
                        <?php if ($hover_overlay === 'title'): ?>
                            <?php echo $title_safe; ?>
                        <?php else: ?>
                            <?php echo $image_count; ?> image<?php echo $image_count !== 1 ? 's' : ''; ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <?php if (empty($grid_posts)): ?>
        <div style="grid-column: 1/-1; padding: 60px 20px; text-align: center; color: var(--text-secondary);">
            <p>No posts yet. Start by uploading your first photograph.</p>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="tg-pagination">
        <?php if ($curr_page > 1): ?>
            <a href="?p=<?php echo $curr_page - 1; ?>" class="tg-page-btn">← Older</a>
        <?php endif; ?>
        <?php for ($pg = max(1, $curr_page - 2); $pg <= min($total_pages, $curr_page + 2); $pg++): ?>
            <a href="?p=<?php echo $pg; ?>"
               class="tg-page-btn<?php echo $pg === $curr_page ? ' is-current' : ''; ?>">
                <?php echo $pg; ?>
            </a>
        <?php endfor; ?>
        <?php if ($curr_page < $total_pages): ?>
            <a href="?p=<?php echo $curr_page + 1; ?>" class="tg-page-btn">Newer →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</main>

<?php include __DIR__ . '/skin-footer.php'; ?>
