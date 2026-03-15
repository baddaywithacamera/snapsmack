<?php
/**
 * SNAPSMACK - Photogram Feed View
 * Alpha v0.7.4
 *
 * Vertical scroll feed of full-width posts. Sits between the landing grid
 * and the solo post page — the missing IG/Pixelfed middle layer.
 *
 * Entry:  ?pg=feed&from={image_id}   — initial page render, image {id} at top
 * Entry:  ?pg=feed                   — most recent post at top
 * AJAX:   ?pg=feed&format=json&cursor={image_id} — returns next batch as JSON
 *
 * Included from landing.php routing block. Has its own header/footer output.
 * Variables from landing.php / index.php: $pdo, $settings, $active_skin, $site_name
 */

require_once dirname(__DIR__, 2) . '/core/community-session.php';
require_once dirname(__DIR__, 2) . '/core/snap-tags.php';

$PG_FEED_PER_PAGE = 12;
$now_local        = date('Y-m-d H:i:s');
$from_id          = max(0, (int)($_GET['from']   ?? 0));
$cursor_id        = max(0, (int)($_GET['cursor'] ?? 0));
$is_json          = ($_GET['format'] ?? '') === 'json';
$load_newer       = ($_GET['newer'] ?? '') === '1';  // Load posts NEWER than cursor (upward scroll)

// ── Profile data for author row ─────────────────────────────────────────────
$site_title  = $settings['site_title']       ?? $site_name ?? 'Photogram';
$avatar_file = $settings['site_avatar'] ?? $settings['site_logo'] ?? $settings['favicon_url'] ?? '';


// ── Query helper ────────────────────────────────────────────────────────────

/**
 * Fetch a batch of feed images.
 *
 * @param PDO    $pdo
 * @param string $now_local  Current date string for scheduled-post gating
 * @param int    $start_id   Starting image ID. 0 = most recent.
 * @param bool   $exclusive  TRUE for cursor-based (id < start_id), FALSE for initial (id <= start_id)
 * @param int    $limit
 * @return array
 */
function pg_feed_fetch(PDO $pdo, string $now_local, int $start_id, bool $exclusive, int $limit): array {
    if ($start_id > 0) {
        $op   = $exclusive ? '<' : '<=';
        $stmt = $pdo->prepare(
            "SELECT id, img_title, img_slug, img_file, img_date,
                    img_description, img_orientation
             FROM   snap_images
             WHERE  img_status = 'published'
               AND  img_date  <= ?
               AND  id $op ?
             ORDER  BY id DESC
             LIMIT  ?"
        );
        $stmt->execute([$now_local, $start_id, $limit]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, img_title, img_slug, img_file, img_date,
                    img_description, img_orientation
             FROM   snap_images
             WHERE  img_status = 'published'
               AND  img_date  <= ?
             ORDER  BY id DESC
             LIMIT  ?"
        );
        $stmt->execute([$now_local, $limit]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Augment image rows with like counts, user-liked state, and comment counts.
 * All community queries are wrapped in try/catch — tables may not be migrated.
 *
 * @param PDO   $pdo
 * @param array $rows   Array of image rows (must have 'id' key)
 * @return array  Map of image_id => ['like_count' => int, 'is_liked' => bool, 'comment_count' => int]
 */
function pg_feed_community_data(PDO $pdo, array $rows): array {
    if (empty($rows)) return [];

    $ids    = array_column($rows, 'id');
    $ph     = implode(',', array_fill(0, count($ids), '?'));
    $result = [];
    foreach ($ids as $id) {
        $result[$id] = ['like_count' => 0, 'is_liked' => false, 'comment_count' => 0];
    }

    try {
        // Like counts
        $lc = $pdo->prepare("SELECT post_id, COUNT(*) AS cnt FROM snap_likes WHERE post_id IN ($ph) GROUP BY post_id");
        $lc->execute($ids);
        foreach ($lc->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $result[$r['post_id']]['like_count'] = (int)$r['cnt'];
        }

        // Comment counts
        $cc = $pdo->prepare("SELECT post_id, COUNT(*) AS cnt FROM snap_community_comments WHERE post_id IN ($ph) AND status = 'visible' GROUP BY post_id");
        $cc->execute($ids);
        foreach ($cc->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $result[$r['post_id']]['comment_count'] = (int)$r['cnt'];
        }

        // Current user's likes
        $viewer = community_current_user();
        if ($viewer) {
            $ul = $pdo->prepare("SELECT post_id FROM snap_likes WHERE post_id IN ($ph) AND user_id = ?");
            $ul->execute(array_merge($ids, [(int)$viewer['id']]));
            foreach ($ul->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $result[$pid]['is_liked'] = true;
            }
        }
    } catch (Exception $_fe) {
        // Community tables unavailable — all counts stay at zero.
    }

    return $result;
}


// ── Render a single feed item as HTML ───────────────────────────────────────

function pg_render_feed_item(array $img, array $comm, string $site_title, string $avatar_file): string {
    ob_start();

    $image_id     = (int)$img['id'];
    $like_count   = (int)$comm['like_count'];
    $comment_count = (int)$comm['comment_count'];
    $is_liked     = (bool)$comm['is_liked'];

    $img_url   = BASE_URL . ltrim($img['img_file'], '/');
    $solo_url  = BASE_URL . htmlspecialchars($img['img_slug']);

    $img_orient = (int)($img['img_orientation'] ?? 0);
    $orient_class = ($img_orient === 1) ? 'pg-orient-portrait' : 'pg-orient-landscape';

    $caption_raw = $img['img_description'] ?? $img['img_title'] ?? '';

    // Relative timestamp
    $post_time = '';
    if (!empty($img['img_date'])) {
        $ts        = strtotime($img['img_date']);
        $diff_days = floor((time() - $ts) / 86400);
        if ($diff_days < 1)     $post_time = 'Today';
        elseif ($diff_days === 1) $post_time = 'Yesterday';
        elseif ($diff_days < 7)  $post_time = $diff_days . ' days ago';
        else                     $post_time = date('F j, Y', $ts);
    }
    ?>
<article class="pg-feed-item" data-image-id="<?php echo $image_id; ?>">

    <!-- Author row -->
    <div class="pg-post-author">
        <?php if (!empty($avatar_file)): ?>
            <a href="<?php echo BASE_URL; ?>" class="pg-post-avatar" aria-label="<?php echo htmlspecialchars($site_title); ?>">
                <img src="<?php echo BASE_URL . ltrim($avatar_file, '/'); ?>"
                     alt="<?php echo htmlspecialchars($site_title); ?>"
                     width="36" height="36">
            </a>
        <?php else: ?>
            <a href="<?php echo BASE_URL; ?>" class="pg-post-avatar-placeholder" aria-label="<?php echo htmlspecialchars($site_title); ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
            </a>
        <?php endif; ?>

        <div class="pg-post-author-info">
            <a href="<?php echo BASE_URL; ?>" class="pg-post-author-name"><?php echo htmlspecialchars($site_title); ?></a>
        </div>

        <a href="<?php echo $solo_url; ?>" class="pg-post-more-btn pg-feed-solo-link" aria-label="View post">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <circle cx="5"  cy="12" r="1.5"/>
                <circle cx="12" cy="12" r="1.5"/>
                <circle cx="19" cy="12" r="1.5"/>
            </svg>
        </a>
    </div>

    <!-- Image — tap goes to solo page; double-tap fires JS like -->
    <a href="<?php echo $solo_url; ?>"
       class="pg-feed-image-link"
       aria-label="<?php echo htmlspecialchars($img['img_title']); ?>">
        <div class="pg-post-image-wrap <?php echo $orient_class; ?>">
            <img src="<?php echo htmlspecialchars($img_url); ?>"
                 alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                 class="pg-post-image pg-feed-image"
                 loading="lazy"
                 draggable="false">
        </div>
    </a>

    <!-- Action bar -->
    <div class="pg-action-bar">

        <!-- Like button -->
        <button class="pg-action-btn pg-like-btn pg-feed-like-btn<?php echo $is_liked ? ' liked' : ''; ?>"
                data-image-id="<?php echo $image_id; ?>"
                data-liked="<?php echo $is_liked ? '1' : '0'; ?>"
                aria-label="<?php echo $is_liked ? 'Unlike' : 'Like'; ?>">
            <svg class="pg-heart-outline" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
            <svg class="pg-heart-filled" width="26" height="26" viewBox="0 0 24 24" fill="#ED4956" stroke="#ED4956" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
        </button>

        <!-- Comment button — navigates to solo page (sheet lives there) -->
        <a href="<?php echo $solo_url; ?>"
           class="pg-action-btn pg-feed-solo-link"
           aria-label="Comment on this post">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
        </a>

    </div>

    <!-- Like count -->
    <div class="pg-like-count pg-feed-like-count"<?php echo $like_count < 1 ? ' style="display:none"' : ''; ?>>
        <?php echo number_format($like_count); ?> <?php echo $like_count === 1 ? 'like' : 'likes'; ?>
    </div>

    <!-- Caption: title in bold, then description -->
    <?php if (!empty($img['img_title']) || !empty($img['img_description'])): ?>
        <div class="pg-caption">
            <?php if (!empty($img['img_title'])): ?>
                <div class="pg-caption-title"><?php echo htmlspecialchars($img['img_title']); ?></div>
            <?php endif; ?>
            <?php if (!empty($img['img_description'])): ?>
                <div class="pg-caption-body"><?php echo snap_render_caption_html($img['img_description'], BASE_URL, 'pg-caption-hashtag'); ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Comment link -->
    <?php if ($comment_count > 0): ?>
        <a href="<?php echo $solo_url; ?>" class="pg-view-comments pg-feed-solo-link">
            View all <?php echo $comment_count; ?> comment<?php echo $comment_count === 1 ? '' : 's'; ?>
        </a>
    <?php else: ?>
        <a href="<?php echo $solo_url; ?>" class="pg-view-comments pg-feed-solo-link">Add a comment&hellip;</a>
    <?php endif; ?>

    <!-- Timestamp -->
    <?php if ($post_time): ?>
        <div class="pg-post-time"><?php echo htmlspecialchars($post_time); ?></div>
    <?php endif; ?>

</article>
    <?php
    return ob_get_clean();
}


// ── Absolute bounds: oldest and newest published post IDs ────────────────────
// Used to tell the JS upfront whether there are older/newer posts to load,
// preventing ghost AJAX calls and incorrect "no more posts" messages.
$bounds_stmt = $pdo->prepare(
    "SELECT MIN(id) AS min_id, MAX(id) AS max_id
     FROM snap_images
     WHERE img_status = 'published' AND img_date <= ?"
);
$bounds_stmt->execute([$now_local]);
$bounds   = $bounds_stmt->fetch(PDO::FETCH_ASSOC);
$min_id   = (int)($bounds['min_id'] ?? 0);
$max_id   = (int)($bounds['max_id'] ?? 0);

// ── Fetch images ─────────────────────────────────────────────────────────────

if ($is_json) {
    // AJAX infinite scroll
    if ($load_newer) {
        // Load NEWER posts (upward scroll): id > cursor, ORDER BY id ASC to get newest
        if ($cursor_id > 0) {
            $stmt = $pdo->prepare(
                "SELECT id, img_title, img_slug, img_file, img_date,
                        img_description, img_orientation
                 FROM   snap_images
                 WHERE  img_status = 'published'
                   AND  img_date  <= ?
                   AND  id > ?
                 ORDER  BY id ASC
                 LIMIT  ?"
            );
            $stmt->execute([$now_local, $cursor_id, $PG_FEED_PER_PAGE]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Reverse so newest appears first when appended
            $rows = array_reverse($rows);
        } else {
            $rows = [];
        }
    } else {
        // Load OLDER posts (downward scroll): id < cursor, ORDER BY id DESC
        $rows = pg_feed_fetch($pdo, $now_local, $cursor_id, true, $PG_FEED_PER_PAGE);
    }
} else {
    // Initial HTML render
    $rows = pg_feed_fetch($pdo, $now_local, $from_id, false, $PG_FEED_PER_PAGE);
}

$comm_data  = pg_feed_community_data($pdo, $rows);
$has_more   = count($rows) >= $PG_FEED_PER_PAGE;
// For newer loads, cursor is the highest ID; for older loads, it's the lowest
$next_cursor = $has_more ? ($load_newer ? (int)reset($rows)['id'] : (int)end($rows)['id']) : 0;

// ── JSON response (infinite scroll AJAX) ────────────────────────────────────

if ($is_json) {
    // Clear any buffered output from core/meta.php or other includes
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $html = '';
    foreach ($rows as $row) {
        $cd = $comm_data[(int)$row['id']] ?? ['like_count' => 0, 'is_liked' => false, 'comment_count' => 0];
        $html .= pg_render_feed_item($row, $cd, $site_title, $avatar_file);
    }
    header('Content-Type: application/json');
    echo json_encode([
        'html'        => $html,
        'has_more'    => $has_more,
        'next_cursor' => $next_cursor,
        'max_id'      => $max_id,
        'min_id'      => $min_id,
    ]);
    exit;
}


// ── HTML page render ─────────────────────────────────────────────────────────

$pg_active_tab = 'home';
include __DIR__ . '/skin-header.php';
?>

<div id="pg-app">
<div class="pg-content">

    <!-- ── Feed Top Bar ──────────────────────────────────────────────────── -->
    <header class="pg-top-bar">
        <a href="<?php echo BASE_URL; ?>" class="pg-top-bar-btn" aria-label="Back to grid">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </a>
        <span class="pg-top-bar-title">Posts</span>
        <span class="pg-top-bar-btn" aria-hidden="true"></span><!-- spacer -->
    </header>

    <!-- ── Feed ──────────────────────────────────────────────────────────── -->
    <div id="pg-feed"
         data-max-id="<?php echo $max_id; ?>"
         data-min-id="<?php echo $min_id; ?>">

        <!-- Infinite scroll sentinel (upward) — load newer posts -->
        <?php if (!empty($from_id)): ?>
        <div id="pg-feed-sentinel-top"
             class="pg-feed-sentinel"
             data-cursor="<?php echo ($from_id >= $max_id) ? 0 : $from_id; ?>">
        </div>
        <?php endif; ?>

        <?php if (!empty($rows)): ?>
            <?php foreach ($rows as $row):
                $cd = $comm_data[(int)$row['id']] ?? ['like_count' => 0, 'is_liked' => false, 'comment_count' => 0];
                echo pg_render_feed_item($row, $cd, $site_title, $avatar_file);
            endforeach; ?>
        <?php else: ?>
            <div class="pg-feed-empty">No posts yet.</div>
        <?php endif; ?>

        <!-- Infinite scroll sentinel (downward) — load older posts -->
        <div id="pg-feed-sentinel"
             class="pg-feed-sentinel"
             data-cursor="<?php echo $has_more ? $next_cursor : '0'; ?>">
        </div>

    </div><!-- /#pg-feed -->

</div><!-- /.pg-content -->
</div><!-- /#pg-app -->

<?php include __DIR__ . '/skin-footer.php'; ?>
