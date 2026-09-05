<?php
/**
 * SNAPSMACK - Community Dock
 *
 * Floating FAB (floating action button) for likes and reactions.
 * Renders as a fixed-position button cluster in a configurable corner,
 * visually adjacent to the download button. Clicking the main button
 * toggles a like; the reaction trigger expands a picker above/beside it.
 *
 * Include in any skin's layout.php after layout-logic.php has run:
 *   <?php include dirname(__DIR__, 2) . '/core/community-dock.php'; ?>
 *
 * Position is set via snap_settings 'community_dock_position'.
 * Conflict detection: if community dock and social dock share a corner,
 * the community dock is offset so they don't overlap.
 *
 * Requires: ss-engine-community.js, ss-community.css (both via smack-community
 * in the skin manifest's require_scripts).
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


if (($settings['community_enabled']      ?? '1') !== '1') return;
if (($settings['community_likes_enabled'] ?? '1') !== '1') return;
if (empty($img['id']))                                      return;

require_once __DIR__ . '/community-session.php';
require_once __DIR__ . '/secret-store.php'; // SECAUDIT 047

// --- GUARD: Bail silently if community migration hasn't been run yet ---
if (!snap_community_ready()) return;

// --- POST PERMISSION ---
if ((($img['allow_comments'] ?? '1') != '1')) return;

$community_user = community_current_user();
$post_id        = (int)$img['id'];
$auth_url       = '/community-auth.php?action=login&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/');

// --- LIKE STATE ---
$lc_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_likes WHERE post_id = ?");
$lc_stmt->execute([$post_id]);
$like_count = (int)$lc_stmt->fetchColumn();

// FEDIVERSE: fold federated likes into the tally. The dock's $img is the
// image row, so image-target likes key on its id; if the image rides in a
// grouped post, post-target likes (Likes on the post Note) count too.
if (($settings['fediverse_enabled'] ?? '0') === '1' && is_file(__DIR__ . '/fediverse.php')) {
    require_once __DIR__ . '/fediverse.php';
    $like_count = sv_combined_like_count($pdo, 'image', $post_id, $like_count);
    if (!empty($img['post_id'])) {
        $like_count = sv_combined_like_count($pdo, 'post', (int)$img['post_id'], $like_count);
    }
}

$user_liked = false;
if ($community_user) {
    $ul_stmt = $pdo->prepare("SELECT id FROM snap_likes WHERE post_id = ? AND user_id = ? LIMIT 1");
    $ul_stmt->execute([$post_id, $community_user['id']]);
    $user_liked = (bool)$ul_stmt->fetchColumn();
} else {
    // Anonymous like check via hashed IP (no PII stored)
    try {
        $_like_salt  = snap_ensure_download_salt($pdo ?? null); // SECAUDIT 047
        $_guest_hash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0') . $_like_salt);
        $ul_stmt = $pdo->prepare("SELECT id FROM snap_likes WHERE post_id = ? AND guest_hash = ? LIMIT 1");
        $ul_stmt->execute([$post_id, $_guest_hash]);
        $user_liked = (bool)$ul_stmt->fetchColumn();
    } catch (PDOException $e) {
        // guest_hash column doesn't exist yet — pre-migration, skip
    }
}

// REACTIONS REMOVED (0.7.x): SnapSmack standardized on a single HEART like,
// matching the Fediverse. The emoji reaction system is gone — snap_reactions is no
// longer read here. Existing reaction rows are migrated into snap_likes by
// migrations/migrate-reactions-to-likes.sql. snap_likes and the federated-like tally
// above are untouched.

// --- POSITION ---
$valid_positions  = ['top-left','top-right','bottom-left','bottom-right','left-top','left-bottom','right-top','right-bottom'];
$dock_pos         = $settings['community_dock_position'] ?? 'bottom-right';
if (!in_array($dock_pos, $valid_positions, true)) $dock_pos = 'bottom-right';

// Conflict detection: if social dock is in the same corner, add offset class
$social_pos       = $settings['social_dock_position']    ?? 'bottom-right';
$social_enabled   = ($settings['social_dock_enabled']    ?? '0') === '1';
$has_conflict     = $social_enabled && ($social_pos === $dock_pos);

// Download button is always bottom-right at bottom:80px right:30px.
// If community dock is also bottom-right, it stacks above it (handled by CSS
// class ss-cdock-above-download).
$above_download   = ($dock_pos === 'bottom-right');

// Build CSS classes
$dock_classes = 'ss-community-dock ss-cdock-' . $dock_pos;
if ($has_conflict)   $dock_classes .= ' ss-cdock-conflict';
if ($above_download) $dock_classes .= ' ss-cdock-above-download';

// The dock must never render unstyled: on a manifest skin the head already
// emitted ss-community.css (core/meta.php marks it), but pre-manifest skin
// builds still deployed in the field have no manifest, so emit the <link>
// here exactly like the other core components in footer-scripts.php do.
if (empty($GLOBALS['snapsmack_engine_css_emitted']['assets/css/ss-community.css'])) {
    echo '<link rel="stylesheet" href="' . BASE_URL . 'assets/css/ss-community.css?v=' . SNAPSMACK_VERSION_SHORT . '">' . "\n";
    $GLOBALS['snapsmack_engine_css_emitted']['assets/css/ss-community.css'] = true;
}
?>

<div class="<?php echo $dock_classes; ?>"
     id="ss-community-dock"
     data-post-id="<?php echo $post_id; ?>"
     data-logged-in="<?php echo $community_user ? '1' : '0'; ?>"
     data-auth-url="<?php echo htmlspecialchars($auth_url); ?>"
     data-reactions="0">

    <?php // Heart-only like. Reactions removed — standardized on the Fediverse heart.
          // Single always-visible FAB, reusing the existing like path
          // (process-like.php / snap_likes). Outline heart by default, filled when
          // liked (CSS via .is-active). ?>
    <div class="ss-cdock-buttons">
        <button class="ss-cdock-btn ss-cdock-heart <?php echo $user_liked ? 'is-active' : ''; ?>"
                id="ss-cdock-like-btn"
                data-action="like"
                data-liked="<?php echo $user_liked ? '1' : '0'; ?>"
                title="<?php echo $user_liked ? 'Unlike' : 'Like'; ?>"
                aria-label="<?php echo $user_liked ? 'Unlike' : 'Like'; ?>"
                aria-pressed="<?php echo $user_liked ? 'true' : 'false'; ?>">
            <span class="ss-cdock-emoji ss-cdock-heart-svg"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg></span>
            <?php if ($like_count > 0): ?>
                <span class="ss-cdock-rx-count"><?php echo $like_count; ?></span>
            <?php endif; ?>
        </button>
    </div><!-- /.ss-cdock-buttons -->

</div><!-- /.ss-community-dock -->
<?php // ===== SNAPSMACK EOF =====
