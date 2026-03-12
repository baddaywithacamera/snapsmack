<?php
/**
 * SNAPSMACK - Photo Navigation Bar
 * Alpha v0.7.3
 *
 * Renders navigation controls for browsing between photos: Previous, First,
 * Info, Comments, Last, Next. Respects both global and per-post comment
 * settings. Chevrons appear on the outer edges for mobile responsiveness.
 */

// --- COMMENTS VISIBILITY LOGIC ---
// Comments are shown only if both the global setting AND the individual post
// setting are enabled
$global_on = (($settings['global_comments_enabled'] ?? '1') == '1');
$post_on   = (($img['allow_comments'] ?? '1') == '1');
$show_comments = ($global_on && $post_on);
?>
<div class="nav-links">
    <span class="left">
        <?php if (!empty($prev_slug)): ?>
            <a href="<?php echo $prev_slug; ?>" title="Previous Entry">« PREV</a>
        <?php else: ?>
            <span class="dim">« PREV</span>
        <?php endif; ?>

            <span class="sep">|</span>

        <?php if (!empty($first_slug) && (BASE_URL . ($img['img_slug'] ?? '') !== $first_slug)): ?>
            <a href="<?php echo $first_slug; ?>" title="Jump to First Entry">FIRST</a>
        <?php else: ?>
            <span class="dim">FIRST</span>
        <?php endif; ?>
    </span>

    <span class="sep">|</span>

    <span class="center">
        <a href="#" id="show-details">INFO</a>

        <?php if ($show_comments): ?>
            <span class="sep">|</span>
            <a href="#" id="show-comments">COMMENTS (<?php echo count($comments); ?>)</a>
        <?php endif; ?>
    </span>

    <span class="sep">|</span>

    <span class="right">
        <?php if (!empty($last_slug) && (BASE_URL . ($img['img_slug'] ?? '') !== $last_slug)): ?>
            <a href="<?php echo $last_slug; ?>" title="Jump to Latest Entry">LAST</a>
        <?php else: ?>
            <span class="dim">LAST</span>
        <?php endif; ?>

        <span class="sep">|</span>

        <?php if (!empty($next_slug)): ?>
            <a href="<?php echo $next_slug; ?>" title="Next Entry">NEXT »</a>
        <?php else: ?>
            <span class="dim">NEXT »</span>
        <?php endif; ?>
    </span>
</div>
