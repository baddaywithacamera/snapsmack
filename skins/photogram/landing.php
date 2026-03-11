<?php
/**
 * SNAPSMACK - Photogram Landing Page
 * Alpha v0.7
 *
 * Profile header (avatar, post count, bio, website) followed immediately
 * by the 3-column square archive grid. Entry point for all visitors.
 *
 * Variables available from index.php: $pdo, $settings, $active_skin, $site_name
 */

// ── Image count for profile stats ─────────────────────────────────────────
$now_local  = date('Y-m-d H:i:s');
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published' AND img_date <= ?");
$count_stmt->execute([$now_local]);
$post_count = (int)$count_stmt->fetchColumn();

// ── Fetch images for grid (paginated) ─────────────────────────────────────
$per_page   = 30;
$curr_page  = max(1, (int)($_GET['p'] ?? 1));
$offset     = ($curr_page - 1) * $per_page;

$grid_stmt = $pdo->prepare("
    SELECT id, img_title, img_slug, img_file, img_thumb_square
    FROM snap_images
    WHERE img_status = 'published' AND img_date <= ?
    ORDER BY img_date DESC
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
$avatar_file = $settings['site_avatar']      ?? $settings['site_logo'] ?? '';

$pg_active_tab = 'home';
?>

<?php include('skin-meta.php'); ?>
<?php include('skin-header.php'); ?>

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
                <!-- Followers and Following: Phase 2 (federation) — omitted in Phase 1 -->
            </div>

        </div>

        <!-- Bio block -->
        <div class="pg-profile-bio">
            <span class="pg-display-name"><?php echo htmlspecialchars($site_title); ?></span>
            <?php if (!empty($site_desc)): ?>
                <p class="pg-bio-text"><?php echo htmlspecialchars($site_desc); ?></p>
            <?php endif; ?>
            <?php if (!empty($site_url)): ?>
                <a href="<?php echo htmlspecialchars($site_url); ?>"
                   class="pg-site-url"
                   target="_blank"
                   rel="noopener noreferrer"><?php echo htmlspecialchars($site_url); ?></a>
            <?php endif; ?>
        </div>
    </section>

    <div class="pg-divider"></div>

    <!-- ── Archive Grid ─────────────────────────────────────────────────── -->
    <main class="pg-grid" aria-label="Photos">
        <?php if (!empty($grid_images)): ?>
            <?php foreach ($grid_images as $gi):
                $link      = BASE_URL . htmlspecialchars($gi['img_slug']);

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
                <!-- Carousel badge — Phase 2, rendered here when snap_posts available -->
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

<?php include('skin-footer.php'); ?>
