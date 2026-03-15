<?php
/**
 * SNAPSMACK - Photogram Post View
 * Alpha v0.7.3a
 *
 * Single post/image view. Full-width image at native aspect ratio,
 * inline like button, comment trigger that opens the bottom sheet.
 * Double-tap on image fires a like + heart burst animation.
 *
 * Variables from index.php / layout_logic.php:
 *   $pdo, $settings, $img, $active_skin, $site_name, $exif_data, $comments
 */

require_once dirname(__DIR__, 2) . '/core/layout_logic.php';
require_once dirname(__DIR__, 2) . '/core/snap-tags.php';

// ── Profile data ──────────────────────────────────────────────────────────
$site_title  = $settings['site_title']       ?? $site_name ?? 'Photogram';
$avatar_file = $settings['site_avatar'] ?? $settings['site_logo'] ?? $settings['favicon_url'] ?? '';

// ── Like state for current viewer ─────────────────────────────────────────
// Uses the same community session pattern as ss-engine-community.js
require_once dirname(__DIR__, 2) . '/core/community-session.php';

$image_id     = (int)$img['id'];
$is_liked     = false;
$like_count   = 0;
$comment_count = count($comments);

// Fetch like count and check if current session already liked
// Wrapped in try/catch — snap_likes only exists if the community migration has been run.
try {
    $like_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_likes WHERE post_id = ?");
    $like_stmt->execute([$image_id]);
    $like_count = (int)$like_stmt->fetchColumn();

    $_pg_community_user = community_current_user();
    if ($_pg_community_user) {
        $lc_stmt = $pdo->prepare("SELECT 1 FROM snap_likes WHERE post_id = ? AND user_id = ?");
        $lc_stmt->execute([$image_id, (int)$_pg_community_user['id']]);
        $is_liked = (bool)$lc_stmt->fetchColumn();
    }
} catch (Exception $_pg_likes_ex) {
    // Community tables not yet migrated — like count stays 0, button shows unhighlighted.
    $_pg_community_user = null;
}

// ── Caption / description ─────────────────────────────────────────────────
$caption_raw = $img['img_description'] ?? $img['img_title'] ?? '';
// Phase 2: prefer snap_posts.caption when available

// ── Post date ─────────────────────────────────────────────────────────────
$post_time = '';
if (!empty($img['img_date'])) {
    $ts        = strtotime($img['img_date']);
    $diff_days = floor((time() - $ts) / 86400);
    if ($diff_days < 1) {
        $post_time = 'Today';
    } elseif ($diff_days === 1) {
        $post_time = 'Yesterday';
    } elseif ($diff_days < 7) {
        $post_time = $diff_days . ' days ago';
    } else {
        $post_time = date('F j, Y', $ts);
    }
}

// ── Image URL ─────────────────────────────────────────────────────────────
$img_url = BASE_URL . ltrim($img['img_file'], '/');

// ── Suppress likes in community component (Photogram handles them inline) ─
$pg_suppress_likes = true;

$pg_active_tab = 'home';
?>

<?php include __DIR__ . '/skin-header.php'; ?>

<div id="pg-app">
<div class="pg-content">

    <!-- ── Top Bar: Post ─────────────────────────────────────────────────── -->
    <header class="pg-top-bar">
        <button class="pg-top-bar-btn" onclick="history.back()" aria-label="Back">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </button>
        <span class="pg-top-bar-title">Post</span>
        <button class="pg-top-bar-btn" aria-label="More options">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                <circle cx="5"  cy="12" r="1.5"/>
                <circle cx="12" cy="12" r="1.5"/>
                <circle cx="19" cy="12" r="1.5"/>
            </svg>
        </button>
    </header>

    <!-- ── Author Row ───────────────────────────────────────────────────── -->
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
            <?php if (!empty($img['img_title']) && $img['img_title'] !== $caption_raw): ?>
                <span class="pg-post-author-sub"><?php echo htmlspecialchars($img['img_title']); ?></span>
            <?php endif; ?>
        </div>

        <button class="pg-post-more-btn" aria-label="More">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <circle cx="5"  cy="12" r="1.5"/>
                <circle cx="12" cy="12" r="1.5"/>
                <circle cx="19" cy="12" r="1.5"/>
            </svg>
        </button>
    </div>

    <!-- ── Image ────────────────────────────────────────────────────────── -->
    <?php
    // 0 = landscape, 1 = portrait, 2 = square — from upload-time classification
    $img_orient = (int)($img['img_orientation'] ?? 0);
    $orient_class = ($img_orient === 1) ? 'pg-orient-portrait' : 'pg-orient-landscape';
    ?>
    <div class="pg-post-image-wrap <?php echo $orient_class; ?>" id="pg-image-wrap"
         data-orientation="<?php echo $img_orient; ?>">
        <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
        <img src="<?php echo htmlspecialchars($img_url); ?>"
             alt="<?php echo htmlspecialchars($img['img_title']); ?>"
             class="pg-post-image"
             id="pg-post-image"
             draggable="false">
        <?php echo $download_button ?? ''; ?>
        <!-- Heart burst injected by JS on double-tap -->
    </div>

    <!-- ── Action Bar ───────────────────────────────────────────────────── -->
    <div class="pg-action-bar">

        <!-- Like button -->
        <button class="pg-action-btn pg-like-btn<?php echo $is_liked ? ' liked' : ''; ?>"
                id="pg-like-btn"
                data-image-id="<?php echo $image_id; ?>"
                data-liked="<?php echo $is_liked ? '1' : '0'; ?>"
                aria-label="<?php echo $is_liked ? 'Unlike' : 'Like'; ?>">
            <!-- Outline heart -->
            <svg class="pg-heart-outline" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
            <!-- Filled heart (shown when liked) -->
            <svg class="pg-heart-filled" width="26" height="26" viewBox="0 0 24 24" fill="#ED4956" stroke="#ED4956" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
            </svg>
        </button>

        <!-- Comment button -->
        <button class="pg-action-btn pg-comment-btn"
                id="pg-comment-btn"
                aria-label="Comment">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
        </button>

    </div>

    <!-- ── Like Count ───────────────────────────────────────────────────── -->
    <?php if ($like_count > 0): ?>
        <div class="pg-like-count" id="pg-like-count">
            <?php echo number_format($like_count); ?> <?php echo $like_count === 1 ? 'like' : 'likes'; ?>
        </div>
    <?php else: ?>
        <div class="pg-like-count" id="pg-like-count" style="display:none;">0 likes</div>
    <?php endif; ?>

    <!-- ── Caption ──────────────────────────────────────────────────────── -->
    <?php if (!empty($caption_raw)): ?>
        <div class="pg-caption">
            <span class="pg-caption-username"><?php echo htmlspecialchars($site_title); ?></span><?php
            echo snap_render_caption_html($caption_raw, BASE_URL, 'pg-caption-hashtag');
            ?>
        </div>
    <?php endif; ?>

    <!-- ── Comment preview ──────────────────────────────────────────────── -->
    <?php if ($comment_count > 0): ?>
        <button class="pg-view-comments" id="pg-open-comments" aria-label="Open comments">
            View all <?php echo $comment_count; ?> comment<?php echo $comment_count === 1 ? '' : 's'; ?>
        </button>
    <?php else: ?>
        <button class="pg-view-comments" id="pg-open-comments" aria-label="Add a comment">
            Add a comment&hellip;
        </button>
    <?php endif; ?>

    <!-- ── Post timestamp ────────────────────────────────────────────────── -->
    <?php if ($post_time): ?>
        <div class="pg-post-time"><?php echo htmlspecialchars($post_time); ?></div>
    <?php endif; ?>

</div><!-- /.pg-content -->


<!-- ══════════════════════════════════════════════════════════════════════════
     COMMENTS BOTTOM SHEET
     ══════════════════════════════════════════════════════════════════════════ -->
<div id="pg-sheet-backdrop"></div>

<div id="pg-comments-sheet" role="dialog" aria-modal="true" aria-label="Comments">

    <div class="pg-sheet-handle" id="pg-sheet-handle"></div>

    <div class="pg-sheet-header">
        <span class="pg-sheet-title">Comments</span>
        <button class="pg-sheet-close" id="pg-sheet-close" aria-label="Close comments">&times;</button>
    </div>

    <div class="pg-sheet-body" id="pg-sheet-body">
        <?php
        // Community component — likes suppressed (Photogram handles inline).
        // $pg_suppress_likes is set above.
        // Wrapped in try/catch: community-component.php queries snap_community_comments
        // and snap_likes which may not exist if the migration hasn't been run, even
        // though snap_community_ready() only probes snap_community_sessions.
        try {
            include dirname(__DIR__, 2) . '/core/community-component.php';
        } catch (Throwable $_cc_err) {
            // Community tables incomplete or unavailable — sheet renders empty.
        }
        ?>
    </div>

    <!-- Pinned comment input — shown when user can comment -->
    <div class="pg-sheet-input-row">
        <input type="text"
               class="pg-sheet-input"
               id="pg-sheet-input"
               placeholder="Add a comment…"
               maxlength="500"
               autocomplete="off">
        <button class="pg-sheet-send" id="pg-sheet-send" aria-label="Post comment">Post</button>
    </div>

</div>

</div><!-- /#pg-app -->

<?php include __DIR__ . '/skin-footer.php'; ?>
