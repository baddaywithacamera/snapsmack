<?php
/**
 * SNAPSMACK - Photogram Landing Page
 * Alpha v0.7.3
 *
 * Profile header (avatar, post count, bio, website) followed immediately
 * by the 3-column square archive grid. Entry point for all visitors.
 *
 * Variables available from index.php: $pdo, $settings, $active_skin, $site_name
 */

// ── Sub-page routing: ?pg=feed | ?pg=search ──────────────────────────────
$_pg_page = $_GET['pg'] ?? '';
if ($_pg_page === 'feed') {
    include __DIR__ . '/feed.php';
    return; // feed.php includes its own header/footer
}
if ($_pg_page === 'search' && ($settings['search_enabled'] ?? '0') === '1') {
    include __DIR__ . '/search.php';
    return; // search.php includes its own header/footer
}

// ── Profile stats ────────────────────────────────────────────────────────
$now_local  = date('Y-m-d H:i:s');
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published' AND img_date <= ?");
$count_stmt->execute([$now_local]);
$post_count = (int)$count_stmt->fetchColumn();

// Total likes across all published posts
$like_total_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM snap_likes l
    JOIN snap_images i ON i.id = l.post_id
    WHERE i.img_status = 'published' AND i.img_date <= ?
");
$like_total_stmt->execute([$now_local]);
$like_count = (int)$like_total_stmt->fetchColumn();

// Total visible comments across all published posts
$comment_total_stmt = $pdo->prepare("
    SELECT COUNT(*) FROM snap_community_comments cc
    JOIN snap_images i ON i.id = cc.post_id
    WHERE cc.status = 'visible' AND i.img_status = 'published' AND i.img_date <= ?
");
$comment_total_stmt->execute([$now_local]);
$comment_count = (int)$comment_total_stmt->fetchColumn();

// ── Fetch images for grid (paginated) ─────────────────────────────────────
$per_page   = 30;
$curr_page  = max(1, (int)($_GET['p'] ?? 1));
$offset     = ($curr_page - 1) * $per_page;

$grid_stmt = $pdo->prepare("
    SELECT i.id, i.img_title, i.img_slug, i.img_file, i.img_thumb_square,
           COALESCE(c.comment_count, 0) AS comment_count,
           COALESCE(lk.like_count, 0)   AS like_count
    FROM snap_images i
    LEFT JOIN (
        SELECT post_id, COUNT(*) AS comment_count
        FROM snap_community_comments
        WHERE status = 'visible'
        GROUP BY post_id
    ) c ON c.post_id = i.id
    LEFT JOIN (
        SELECT post_id, COUNT(*) AS like_count
        FROM snap_likes
        GROUP BY post_id
    ) lk ON lk.post_id = i.id
    WHERE i.img_status = 'published' AND i.img_date <= ?
    ORDER BY i.img_date DESC
    LIMIT ? OFFSET ?
");
$grid_stmt->execute([$now_local, $per_page, $offset]);
$grid_images = $grid_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_stmt = $count_stmt; // already ran
$has_more   = ($offset + count($grid_images)) < $post_count;

// ── Profile data from settings ────────────────────────────────────────────
$site_title  = $settings['site_title']       ?? $site_name ?? 'Photogram';
$site_desc   = $settings['site_description'] ?? '';
$site_url    = $settings['site_url']         ?? '';
$avatar_file = $settings['site_avatar'] ?? $settings['site_logo'] ?? $settings['favicon_url'] ?? '';

$pg_active_tab = 'home';
?>

<?php include __DIR__ . '/skin-meta.php'; ?>
<?php include __DIR__ . '/skin-header.php'; ?>

<div id="pg-app">
<div class="pg-content">

    <!-- ── Profile Header Bar ──────────────────────────────────────────── -->
    <header class="pg-profile-header">
        <span class="pg-profile-header-title"><?php echo htmlspecialchars($site_title); ?></span>
    </header>

    <!-- ── Profile Block ───────────────────────────────────────────────── -->
    <section class="pg-profile" aria-label="Profile">
        <div class="pg-profile-top">

            <!-- Avatar -->
            <?php if (!empty($avatar_file)): ?>
                <div class="pg-avatar">
                    <img src="<?php echo BASE_URL . ltrim($avatar_file, '/'); ?>"
                         alt="<?php echo htmlspecialchars($site_title); ?>"
                         width="80" height="80">
                </div>
            <?php else: ?>
                <div class="pg-avatar-placeholder" aria-hidden="true">
                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="pg-profile-stats">
                <div class="pg-stat">
                    <span class="pg-stat-count"><?php echo number_format($post_count); ?></span>
                    <span class="pg-stat-label">Posts</span>
                </div>
                <div class="pg-stat">
                    <span class="pg-stat-count"><?php echo number_format($like_count); ?></span>
                    <span class="pg-stat-label">Likes</span>
                </div>
                <div class="pg-stat">
                    <span class="pg-stat-count"><?php echo number_format($comment_count); ?></span>
                    <span class="pg-stat-label">Comments</span>
                </div>
            </div>

        </div>

        <!-- Bio block -->
        <?php if (!empty($site_desc)): ?>
        <div class="pg-profile-bio">
            <p class="pg-bio-text"><?php echo htmlspecialchars($site_desc); ?></p>
        </div>
        <?php endif; ?>
    </section>

    <div class="pg-divider"></div>

    <!-- ── Archive Grid ─────────────────────────────────────────────────── -->
    <main class="pg-grid" aria-label="Photos">
        <?php if (!empty($grid_images)): ?>
            <?php foreach ($grid_images as $gi):
                // Tap grid cell → feed view starting at this image
                $link = BASE_URL . '?pg=feed&from=' . (int)$gi['id'];

                // Prefer square thumbnail; fall back to constructing path from full image
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
                <?php if ((int)$gi['like_count'] > 0 || (int)$gi['comment_count'] > 0): ?>
                    <span class="pg-grid-overlay" aria-hidden="true">
                        <?php if ((int)$gi['like_count'] > 0): ?>
                        <span class="pg-grid-stat">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" stroke="none">
                                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                            </svg>
                            <?php echo (int)$gi['like_count']; ?>
                        </span>
                        <?php endif; ?>
                        <?php if ((int)$gi['comment_count'] > 0): ?>
                        <span class="pg-grid-stat">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                            </svg>
                            <?php echo (int)$gi['comment_count']; ?>
                        </span>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="pg-grid-empty">No photos yet.</div>
        <?php endif; ?>
    </main>

    <!-- ── Load More ────────────────────────────────────────────────────── -->
    <?php if ($has_more): ?>
        <div class="pg-load-more-wrap">
            <a href="<?php echo BASE_URL . '?p=' . ($curr_page + 1); ?>"
               class="pg-load-more-btn">Load more</a>
        </div>
    <?php endif; ?>

</div><!-- /.pg-content -->
</div><!-- /#pg-app -->

<?php include __DIR__ . '/skin-footer.php'; ?>
