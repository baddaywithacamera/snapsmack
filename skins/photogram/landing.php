<?php
/**
 * SNAPSMACK - Photogram Landing Page
 * Alpha v0.7.9
 *
 * Profile header (avatar, post count, bio, website) followed immediately
 * by the 3-column square archive grid. Entry point for all visitors.
 *
 * Variables available from index.php: $pdo, $settings, $active_skin, $site_name
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
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

// Community profile totals — guarded: tables may not exist on older installs
$like_count    = 0;
$comment_count = 0;
try {
    $like_total_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM snap_likes l
        JOIN snap_images i ON i.id = l.post_id
        WHERE i.img_status = 'published' AND i.img_date <= ?
    ");
    $like_total_stmt->execute([$now_local]);
    $like_count = (int)$like_total_stmt->fetchColumn();

    $comment_total_stmt = $pdo->prepare("
        SELECT COUNT(*) FROM snap_community_comments cc
        JOIN snap_images i ON i.id = cc.post_id
        WHERE cc.status = 'visible' AND i.img_status = 'published' AND i.img_date <= ?
    ");
    $comment_total_stmt->execute([$now_local]);
    $comment_count = (int)$comment_total_stmt->fetchColumn();
} catch (PDOException $e) {}

// ── Fetch images for grid (paginated / infinite scroll) ───────────────────
$per_page   = 30;
$curr_page  = max(1, (int)($_GET['p'] ?? 1));
$offset     = ($curr_page - 1) * $per_page;
$is_json    = ($_GET['format'] ?? '') === 'json';

// Base query — no community joins so it always works regardless of schema state
$grid_stmt = $pdo->prepare("
    SELECT id, img_title, img_slug, img_file, img_thumb_square
    FROM snap_images
    WHERE img_status = 'published' AND img_date <= ?
    ORDER BY sort_order ASC, img_date DESC
    LIMIT ? OFFSET ?
");
$grid_stmt->execute([$now_local, $per_page, $offset]);
$grid_images = $grid_stmt->fetchAll(PDO::FETCH_ASSOC);

$has_more = ($offset + count($grid_images)) < $post_count;

// ── Helper: render one grid cell ─────────────────────────────────────────
function pg_grid_cell(array $gi): string {
    $link = BASE_URL . htmlspecialchars($gi['img_slug']);
    if (!empty($gi['img_thumb_square'])) {
        $thumb = BASE_URL . ltrim($gi['img_thumb_square'], '/');
    } elseif (!empty($gi['img_file'])) {
        $fp    = pathinfo(ltrim($gi['img_file'], '/'));
        $thumb = BASE_URL . $fp['dirname'] . '/thumbs/t_' . $fp['basename'];
    } else {
        $thumb = '';
    }
    $title = htmlspecialchars($gi['img_title']);
    $html  = '<a href="' . $link . '" class="pg-grid-cell" title="' . $title . '" aria-label="' . $title . '">';
    if ($thumb) {
        $html .= '<img src="' . $thumb . '" alt="' . $title . '" loading="lazy">';
    }
    $html .= '</a>';
    return $html;
}

// ── JSON response for infinite scroll ────────────────────────────────────
if ($is_json) {
    $html = '';
    foreach ($grid_images as $gi) {
        $html .= pg_grid_cell($gi);
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['html' => $html, 'has_more' => $has_more]);
    exit;
}

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
    <main class="pg-grid" id="pg-grid" aria-label="Photos">
        <?php if (!empty($grid_images)): ?>
            <?php foreach ($grid_images as $gi): ?>
                <?php echo pg_grid_cell($gi); ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="pg-grid-empty">No photos yet.</div>
        <?php endif; ?>
    </main>

    <!-- ── Infinite scroll sentinel ─────────────────────────────────────── -->
    <div id="pg-grid-sentinel"
         class="pg-feed-sentinel<?php echo $has_more ? ' pg-feed-loading' : ' pg-feed-end'; ?>"
         data-page="<?php echo $curr_page + 1; ?>"
         data-has-more="<?php echo $has_more ? '1' : '0'; ?>">
    </div>

</div><!-- /.pg-content -->
</div><!-- /#pg-app -->

<script>
(function () {
    'use strict';
    var sentinel = document.getElementById('pg-grid-sentinel');
    var grid     = document.getElementById('pg-grid');
    if (!sentinel || !grid) return;

    var loading = false;

    function loadNext() {
        if (loading || sentinel.dataset.hasMore === '0') return;
        loading = true;
        sentinel.className = 'pg-feed-sentinel pg-feed-loading';

        var page = parseInt(sentinel.dataset.page, 10) || 2;
        fetch('?format=json&p=' + page)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = data.html;
                    while (tmp.firstChild) grid.appendChild(tmp.firstChild);
                }
                sentinel.dataset.page     = page + 1;
                sentinel.dataset.hasMore  = data.has_more ? '1' : '0';
                sentinel.className        = data.has_more
                    ? 'pg-feed-sentinel pg-feed-loading'
                    : 'pg-feed-sentinel pg-feed-end';
                loading = false;
            })
            .catch(function () {
                loading = false;
                sentinel.className = 'pg-feed-sentinel';
            });
    }

    if ('IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting) loadNext();
        }, { rootMargin: '300px' });
        observer.observe(sentinel);
    } else {
        // Fallback: load all at once for older browsers
        loadNext();
    }
}());
</script>

<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
