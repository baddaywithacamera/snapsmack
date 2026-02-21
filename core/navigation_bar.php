<?php
/**
 * SnapSmack - Standard Navigation Bar
 * Version: 1.4 - Double-Lock Integration
 */

// LOGIC: Ensure we respect both the Global Setting AND the Post Setting
$global_on = (($settings['global_comments_enabled'] ?? '1') == '1');
$post_on   = (($img['allow_comments'] ?? '1') == '1');
$show_comments = ($global_on && $post_on);
?>
<div class="nav-links">
    <span class="left">
        <?php if (!empty($first_slug) && (BASE_URL . ($img['img_slug'] ?? '') !== $first_slug)): ?>
            <a href="<?php echo $first_slug; ?>" title="Jump to First Entry">« FIRST</a>
            <span class="sep">|</span>
        <?php else: ?>
            <span class="dim">« FIRST</span>
            <span class="sep">|</span>
        <?php endif; ?>

        <?php if (!empty($prev_slug)): ?>
            <a href="<?php echo $prev_slug; ?>" title="Previous Entry">PREV</a>
        <?php else: ?>
            <span class="dim">PREV</span>
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
        <?php if (!empty($next_slug)): ?>
            <a href="<?php echo $next_slug; ?>" title="Next Entry">NEXT</a>
        <?php else: ?>
            <span class="dim">NEXT</span>
        <?php endif; ?>

        <span class="sep">|</span>

        <?php if (!empty($last_slug) && (BASE_URL . ($img['img_slug'] ?? '') !== $last_slug)): ?>
            <a href="<?php echo $last_slug; ?>" title="Jump to Latest Entry">LAST »</a>
        <?php else: ?>
            <span class="dim">LAST »</span>
        <?php endif; ?>
    </span>
</div>