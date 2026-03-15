<?php
/**
 * SNAPSMACK - Community Component
 * Alpha v0.7.3a
 *
 * Shared include for likes, reactions, and account-required comments.
 * Drop into any skin's layout.php after core/layout_logic.php has run.
 *
 * Requires these variables to be in scope (all provided by layout_logic.php):
 *   $pdo       — PDO connection
 *   $settings  — snap_settings key-value array
 *   $img       — current image/post row from snap_images
 *
 * Usage in a skin's layout.php:
 *   <?php include dirname(__DIR__, 2) . '/core/community-component.php'; ?>
 *
 * Skin manifest flags that control what appears:
 *   'community_comments'  => '1'   — show comment thread and form
 *   'community_likes'     => '1'   — show like button and count
 *   'community_reactions' => '0'   — show reaction picker (off until set finalised)
 *
 * If the manifest doesn't declare these keys, the global snap_settings values
 * are used as fallback. This lets the blog owner toggle community features
 * globally from the admin panel without needing to touch each skin.
 *
 * The component outputs a self-contained <div class="ss-community"> block
 * with no inline <script> or <style> tags — all behaviour is in
 * ss-engine-community.js and all styling is in assets/css/ss-community.css.
 * Both are enqueued via require_scripts[] in each skin's manifest.
 */

// --- GUARD: Don't run if community system is globally off ---
if (($settings['community_enabled'] ?? '1') !== '1') {
    return;
}

// --- GUARD: Require layout_logic.php to have run ---
if (empty($img['id'])) {
    return;
}

require_once __DIR__ . '/community-session.php';

// --- GUARD: Bail silently if community migration hasn't been run yet ---
if (!snap_community_ready()) return;

// --- MANIFEST FLAGS ---
// Skin manifest may override global settings per-skin.
// $skin_manifest is loaded by core/manifest.php before layout.php runs.
$manifest_data = $skin_manifest ?? [];

$show_comments  = (string)($manifest_data['community_comments']  ?? $settings['community_comments_enabled']  ?? '1') === '1';
$show_likes     = (string)($manifest_data['community_likes']     ?? $settings['community_likes_enabled']     ?? '1') === '1';
$show_reactions = (string)($manifest_data['community_reactions'] ?? $settings['community_reactions_enabled'] ?? '0') === '1';

// If the community dock is active (migration has run), it handles likes and reactions.
// The component becomes comments-only to avoid showing likes in two places.
if (array_key_exists('community_dock_position', $settings)) {
    $show_likes     = false;
    $show_reactions = false;
}

// If the calling skin handles likes inline (e.g. Photogram), suppress them here.
if (!empty($pg_suppress_likes)) {
    $show_likes     = false;
    $show_reactions = false;
}

// Nothing to show — bail silently
if (!$show_comments && !$show_likes && !$show_reactions) {
    return;
}

// --- PER-POST PERMISSION ---
// Respect the existing allow_comments flag on the image record.
// If comments are disabled for this post, hide the community block entirely.
$post_comments_on = (($img['allow_comments'] ?? '1') == '1');
if (!$post_comments_on) {
    return;
}

// --- COMMENT IDENTITY MODE ---
// open       — anyone can comment with just a name (default)
// hybrid     — account holders get full identity; guests still welcome
// registered — community account required (original behaviour)
$comment_identity = $settings['comment_identity'] ?? 'open';

// --- CURRENT USER ---
$community_user = community_current_user();
$post_id        = (int)$img['id'];
$auth_url       = '/community-auth.php?action=login&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/');

// --- LIKE COUNT AND STATE ---
$like_count   = 0;
$user_liked   = false;
$reaction_map = [];  // reaction_code => count
$user_reaction = null;

if ($show_likes || $show_reactions) {
    $like_count = (int)$pdo->prepare("SELECT COUNT(*) FROM snap_likes WHERE post_id = ?")
                            ->execute([$post_id]) ? $pdo->query("SELECT COUNT(*) FROM snap_likes WHERE post_id = {$post_id}")->fetchColumn() : 0;

    // Cleaner like count fetch
    $lc_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_likes WHERE post_id = ?");
    $lc_stmt->execute([$post_id]);
    $like_count = (int)$lc_stmt->fetchColumn();

    if ($community_user) {
        $ul_stmt = $pdo->prepare("SELECT id FROM snap_likes WHERE post_id = ? AND user_id = ? LIMIT 1");
        $ul_stmt->execute([$post_id, $community_user['id']]);
        $user_liked = (bool)$ul_stmt->fetchColumn();
    }
}

if ($show_reactions) {
    $rx_stmt = $pdo->prepare("SELECT reaction_code, COUNT(*) as cnt FROM snap_reactions WHERE post_id = ? GROUP BY reaction_code");
    $rx_stmt->execute([$post_id]);
    foreach ($rx_stmt->fetchAll() as $row) {
        $reaction_map[$row['reaction_code']] = (int)$row['cnt'];
    }
    if ($community_user) {
        $ur_stmt = $pdo->prepare("SELECT reaction_code FROM snap_reactions WHERE post_id = ? AND user_id = ? LIMIT 1");
        $ur_stmt->execute([$post_id, $community_user['id']]);
        $user_reaction = $ur_stmt->fetchColumn() ?: null;
    }
}

// --- COMMUNITY COMMENTS ---
// LEFT JOIN so guest comments (user_id IS NULL) are included alongside account comments.
$community_comments = [];
if ($show_comments) {
    $cc_stmt = $pdo->prepare("
        SELECT cc.id, cc.comment_text, cc.created_at, cc.edited_at,
               cu.username, cu.display_name, cu.avatar_url,
               cc.guest_name, cc.guest_email,
               CASE WHEN cc.user_id IS NULL THEN 1 ELSE 0 END AS is_guest
        FROM snap_community_comments cc
        LEFT JOIN snap_community_users cu ON cu.id = cc.user_id
        WHERE cc.post_id = ? AND cc.status = 'visible'
        ORDER BY cc.created_at ASC
    ");
    $cc_stmt->execute([$post_id]);
    $community_comments = $cc_stmt->fetchAll();
}

// --- REACTION SET ---
// Curated photography-appropriate set. Final set to be locked before UI ships.
// Codes are short slugs; emoji are the visual representation.
$reaction_set = [
    'fire'         => ['emoji' => '🔥', 'label' => 'Fire'],
    'chef-kiss'    => ['emoji' => '🤌', 'label' => 'Chef\'s kiss'],
    'wow'          => ['emoji' => '😮', 'label' => 'Wow'],
    'moody'        => ['emoji' => '🌧️', 'label' => 'Moody'],
    'sharp'        => ['emoji' => '💎', 'label' => 'Sharp'],
    'golden-hour'  => ['emoji' => '🌅', 'label' => 'Golden hour'],
    'cinematic'    => ['emoji' => '🎬', 'label' => 'Cinematic'],
    'peaceful'     => ['emoji' => '🕊️', 'label' => 'Peaceful'],
    'haunting'     => ['emoji' => '👁️', 'label' => 'Haunting'],
    'story'        => ['emoji' => '📖', 'label' => 'Tells a story'],
    'colours'      => ['emoji' => '🎨', 'label' => 'The colours'],
    'light'        => ['emoji' => '✨', 'label' => 'The light'],
    'texture'      => ['emoji' => '🪨', 'label' => 'Texture'],
    'timing'       => ['emoji' => '⚡', 'label' => 'Perfect timing'],
    'composition'  => ['emoji' => '🔲', 'label' => 'Composition'],
];
?>

<div class="ss-community"
     data-post-id="<?php echo $post_id; ?>"
     data-auth-url="<?php echo htmlspecialchars($auth_url); ?>"
     data-logged-in="<?php echo $community_user ? '1' : '0'; ?>"
     data-comment-mode="<?php echo htmlspecialchars($comment_identity); ?>">

    <?php // ================================================================
          // LIKES + REACTIONS BAR
          // ============================================================ ?>
    <?php if ($show_likes || $show_reactions): ?>
    <div class="ss-community-bar">

        <?php if ($show_likes): ?>
        <button class="ss-like-btn <?php echo $user_liked ? 'is-liked' : ''; ?>"
                data-liked="<?php echo $user_liked ? '1' : '0'; ?>"
                aria-label="<?php echo $user_liked ? 'Unlike' : 'Like'; ?> this post"
                aria-pressed="<?php echo $user_liked ? 'true' : 'false'; ?>">
            <span class="ss-like-icon" aria-hidden="true">
                <?php echo $user_liked ? '♥' : '♡'; ?>
            </span>
            <span class="ss-like-count"><?php echo $like_count; ?></span>
        </button>
        <?php endif; ?>

        <?php if ($show_reactions): ?>
        <div class="ss-reactions-wrap">
            <button class="ss-reaction-trigger <?php echo $user_reaction ? 'has-reaction' : ''; ?>"
                    aria-label="Add reaction"
                    aria-expanded="false">
                <?php if ($user_reaction && isset($reaction_set[$user_reaction])): ?>
                    <?php echo $reaction_set[$user_reaction]['emoji']; ?>
                <?php else: ?>
                    <span class="ss-reaction-add">＋</span>
                <?php endif; ?>
            </button>

            <div class="ss-reaction-picker" hidden>
                <?php foreach ($reaction_set as $code => $rx): ?>
                <button class="ss-reaction-opt <?php echo $user_reaction === $code ? 'is-active' : ''; ?>"
                        data-code="<?php echo htmlspecialchars($code); ?>"
                        title="<?php echo htmlspecialchars($rx['label']); ?>"
                        aria-label="<?php echo htmlspecialchars($rx['label']); ?>">
                    <?php echo $rx['emoji']; ?>
                    <?php if (!empty($reaction_map[$code])): ?>
                        <span class="ss-rx-count"><?php echo $reaction_map[$code]; ?></span>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!empty($reaction_map)): ?>
        <div class="ss-reaction-summary">
            <?php foreach ($reaction_map as $code => $count):
                if (isset($reaction_set[$code])): ?>
                <span class="ss-rx-pill" title="<?php echo htmlspecialchars($reaction_set[$code]['label']); ?>">
                    <?php echo $reaction_set[$code]['emoji']; ?>
                    <span><?php echo $count; ?></span>
                </span>
            <?php endif; endforeach; ?>
        </div>
        <?php endif; ?>

        <?php endif; // show_reactions ?>

        <?php if (!$community_user): ?>
        <div class="ss-auth-nudge">
            <a href="<?php echo htmlspecialchars($auth_url); ?>">Sign in</a> to like<?php echo $show_reactions ? ' and react' : ''; ?>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; // show_likes || show_reactions ?>


    <?php // ================================================================
          // COMMENT THREAD
          // ============================================================ ?>
    <?php if ($show_comments): ?>
    <div class="ss-comments">

        <?php if (!empty($community_comments)): ?>
        <div class="ss-comment-thread" aria-label="Comments">
            <?php foreach ($community_comments as $c):
                $is_guest = (bool)$c['is_guest'];
                $display  = $is_guest
                    ? htmlspecialchars($c['guest_name'] ?: 'Anonymous')
                    : htmlspecialchars($c['display_name'] ?: $c['username']);
                $initial  = strtoupper(substr($is_guest ? ($c['guest_name'] ?: 'A') : ($c['username'] ?: '?'), 0, 1));
                $date     = date('Y-m-d', strtotime($c['created_at']));
                $is_own   = !$is_guest && $community_user && $community_user['username'] === $c['username'];
            ?>
            <div class="ss-comment" data-comment-id="<?php echo (int)$c['id']; ?>">
                <div class="ss-comment-meta">
                    <?php if (!$is_guest && $c['avatar_url']): ?>
                    <img src="<?php echo htmlspecialchars($c['avatar_url']); ?>"
                         alt="" class="ss-avatar" width="28" height="28" loading="lazy">
                    <?php else: ?>
                    <span class="ss-avatar-placeholder" aria-hidden="true"><?php echo $initial; ?></span>
                    <?php endif; ?>
                    <span class="ss-commenter"><?php echo $display; ?></span>
                    <span class="ss-comment-date"><?php echo $date; ?></span>
                    <?php if ($is_own): ?>
                    <button class="ss-comment-delete" data-comment-id="<?php echo (int)$c['id']; ?>"
                            aria-label="Delete comment">✕</button>
                    <?php endif; ?>
                </div>
                <div class="ss-comment-body"><?php echo nl2br(htmlspecialchars($c['comment_text'])); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($community_user): ?>
        <!-- Logged-in account form — shown in all modes when user is authenticated -->
        <form class="ss-comment-form" data-post-id="<?php echo $post_id; ?>">
            <div class="ss-comment-input-row">
                <?php if ($community_user['avatar_url']): ?>
                <img src="<?php echo htmlspecialchars($community_user['avatar_url']); ?>"
                     alt="" class="ss-avatar" width="28" height="28">
                <?php else: ?>
                <span class="ss-avatar-placeholder" aria-hidden="true">
                    <?php echo strtoupper(substr($community_user['username'], 0, 1)); ?>
                </span>
                <?php endif; ?>
                <textarea name="comment_text" class="ss-comment-textarea"
                          placeholder="Add a comment..."
                          rows="1" maxlength="2000"
                          aria-label="Comment"></textarea>
            </div>
            <div class="ss-comment-actions" hidden>
                <span class="ss-comment-author">
                    <?php echo htmlspecialchars($community_user['display_name'] ?: $community_user['username']); ?>
                </span>
                <div class="ss-comment-btns">
                    <button type="button" class="ss-comment-cancel">Cancel</button>
                    <button type="submit" class="ss-comment-submit">Post</button>
                </div>
            </div>
            <div class="ss-comment-status" role="status" aria-live="polite"></div>
        </form>

        <?php elseif ($comment_identity !== 'registered'): ?>
        <!-- Guest comment form — open and hybrid modes, unauthenticated visitor -->
        <form class="ss-comment-form ss-comment-form--guest" data-post-id="<?php echo $post_id; ?>">
            <div class="ss-comment-guest-fields">
                <input type="text"  name="guest_name"  class="ss-guest-name"
                       placeholder="Your name (required)" maxlength="100">
                <input type="email" name="guest_email" class="ss-guest-email"
                       placeholder="Email (optional, never shown)" maxlength="200">
            </div>
            <div class="ss-comment-input-row">
                <span class="ss-avatar-placeholder" aria-hidden="true">?</span>
                <textarea name="comment_text" class="ss-comment-textarea"
                          placeholder="Add a comment..."
                          rows="1" maxlength="2000"
                          aria-label="Comment"></textarea>
            </div>
            <div class="ss-comment-actions" hidden>
                <span class="ss-comment-author">Commenting as guest</span>
                <div class="ss-comment-btns">
                    <button type="button" class="ss-comment-cancel">Cancel</button>
                    <button type="submit" class="ss-comment-submit">Post</button>
                </div>
            </div>
            <div class="ss-comment-status" role="status" aria-live="polite"></div>
        </form>

        <?php else: ?>
        <!-- Registered mode — sign-in prompt -->
        <div class="ss-comment-login-prompt">
            <a href="<?php echo htmlspecialchars($auth_url); ?>">Sign in</a> to leave a comment.
        </div>
        <?php endif; ?>

    </div>
    <?php endif; // show_comments ?>

</div><!-- /.ss-community -->
