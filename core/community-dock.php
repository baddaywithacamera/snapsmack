<?php
/**
 * SNAPSMACK - Community Dock
 * Alpha v0.7
 *
 * Floating FAB (floating action button) for likes and reactions.
 * Renders as a fixed-position button cluster in a configurable corner,
 * visually adjacent to the download button. Clicking the main button
 * toggles a like; the reaction trigger expands a picker above/beside it.
 *
 * Include in any skin's layout.php after layout_logic.php has run:
 *   <?php include dirname(__DIR__, 2) . '/core/community-dock.php'; ?>
 *
 * Position is set via snap_settings 'community_dock_position'.
 * Conflict detection: if community dock and social dock share a corner,
 * the community dock is offset so they don't overlap.
 *
 * Requires: ss-engine-community.js, ss-community.css (both via smack-community
 * in the skin manifest's require_scripts).
 */

if (($settings['community_enabled']      ?? '1') !== '1') return;
if (($settings['community_likes_enabled'] ?? '1') !== '1') return;
if (empty($img['id']))                                      return;

require_once __DIR__ . '/community-session.php';

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

$user_liked = false;
if ($community_user) {
    $ul_stmt = $pdo->prepare("SELECT id FROM snap_likes WHERE post_id = ? AND user_id = ? LIMIT 1");
    $ul_stmt->execute([$post_id, $community_user['id']]);
    $user_liked = (bool)$ul_stmt->fetchColumn();
}

// --- REACTIONS ---
$reactions_on  = ($settings['community_reactions_enabled'] ?? '0') === '1';
$allow_dislike = ($settings['community_allow_dislike']     ?? '0') === '1';

// Active reaction set (stored as JSON in settings, up to 6 codes)
$active_reactions = [];
if ($reactions_on) {
    $raw = $settings['community_active_reactions'] ?? '[]';
    $active_reactions = json_decode($raw, true) ?: [];
    if ($allow_dislike && !in_array('thumbs-down', $active_reactions, true)) {
        $active_reactions[] = 'thumbs-down';
    }
}

// Full reaction registry (master list)
$reaction_registry = [
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
    'thumbs-down'  => ['emoji' => '👎', 'label' => 'Honest feedback'],
];

// Current user's reaction
$user_reaction = null;
$reaction_counts = [];
if ($reactions_on && !empty($active_reactions)) {
    $rx_stmt = $pdo->prepare("
        SELECT reaction_code, COUNT(*) as cnt FROM snap_reactions
        WHERE post_id = ? GROUP BY reaction_code
    ");
    $rx_stmt->execute([$post_id]);
    foreach ($rx_stmt->fetchAll() as $row) {
        $reaction_counts[$row['reaction_code']] = (int)$row['cnt'];
    }
    if ($community_user) {
        $ur_stmt = $pdo->prepare("SELECT reaction_code FROM snap_reactions WHERE post_id = ? AND user_id = ? LIMIT 1");
        $ur_stmt->execute([$post_id, $community_user['id']]);
        $user_reaction = $ur_stmt->fetchColumn() ?: null;
    }
}

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
?>

<div class="<?php echo $dock_classes; ?>"
     id="ss-community-dock"
     data-post-id="<?php echo $post_id; ?>"
     data-logged-in="<?php echo $community_user ? '1' : '0'; ?>"
     data-auth-url="<?php echo htmlspecialchars($auth_url); ?>"
     data-reactions="<?php echo $reactions_on && !empty($active_reactions) ? '1' : '0'; ?>">

    <?php // ================================================================
          // REACTION PICKER (expands from trigger, above the main buttons)
          // ============================================================ ?>
    <?php if ($reactions_on && !empty($active_reactions)): ?>
    <div class="ss-cdock-picker" hidden role="dialog" aria-label="Reactions">
        <?php foreach ($active_reactions as $code):
            if (!isset($reaction_registry[$code])) continue;
            $rx      = $reaction_registry[$code];
            $count   = $reaction_counts[$code] ?? 0;
            $is_mine = ($user_reaction === $code);
        ?>
        <button class="ss-cdock-reaction <?php echo $is_mine ? 'is-active' : ''; ?>"
                data-code="<?php echo htmlspecialchars($code); ?>"
                title="<?php echo htmlspecialchars($rx['label']); ?>"
                aria-label="<?php echo htmlspecialchars($rx['label']); ?>"
                aria-pressed="<?php echo $is_mine ? 'true' : 'false'; ?>">
            <span class="ss-cdock-emoji"><?php echo $rx['emoji']; ?></span>
            <?php if ($count > 0): ?>
                <span class="ss-cdock-rx-count"><?php echo $count; ?></span>
            <?php endif; ?>
        </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php // ================================================================
          // BUTTON CLUSTER (like + optional reaction trigger)
          // ============================================================ ?>
    <div class="ss-cdock-buttons">

        <?php if ($reactions_on && !empty($active_reactions)): ?>
        <button class="ss-cdock-btn ss-cdock-react-btn <?php echo $user_reaction ? 'has-reaction' : ''; ?>"
                id="ss-cdock-react-trigger"
                aria-label="React to this photo"
                aria-expanded="false"
                title="<?php echo $user_reaction && isset($reaction_registry[$user_reaction])
                    ? htmlspecialchars($reaction_registry[$user_reaction]['label'])
                    : 'Add reaction'; ?>">
            <?php if ($user_reaction && isset($reaction_registry[$user_reaction])): ?>
                <span class="ss-cdock-current-rx"><?php echo $reaction_registry[$user_reaction]['emoji']; ?></span>
            <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="20" height="20" aria-hidden="true">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M8 14s1.5 2 4 2 4-2 4-2"/>
                    <line x1="9" y1="9" x2="9.01" y2="9"/>
                    <line x1="15" y1="9" x2="15.01" y2="9"/>
                </svg>
            <?php endif; ?>
        </button>
        <?php endif; ?>

        <button class="ss-cdock-btn ss-cdock-like-btn <?php echo $user_liked ? 'is-liked' : ''; ?>"
                id="ss-cdock-like-btn"
                data-liked="<?php echo $user_liked ? '1' : '0'; ?>"
                aria-label="<?php echo $user_liked ? 'Unlike' : 'Like'; ?> this photo"
                aria-pressed="<?php echo $user_liked ? 'true' : 'false'; ?>"
                title="<?php echo $user_liked ? 'Unlike' : 'Like'; ?>">
            <span class="ss-cdock-like-icon" aria-hidden="true"><?php echo $user_liked ? '♥' : '♡'; ?></span>
            <?php if ($like_count > 0): ?>
                <span class="ss-cdock-like-count"><?php echo $like_count; ?></span>
            <?php endif; ?>
        </button>

    </div><!-- /.ss-cdock-buttons -->

</div><!-- /.ss-community-dock -->
