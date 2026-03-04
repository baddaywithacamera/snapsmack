<?php
/**
 * SNAPSMACK - Rational Geo Single Image View
 * v1.0
 *
 * Magazine-page feel: comments slide DOWN from top (like a masthead section),
 * info/caption slides UP from bottom (like a magazine caption block).
 * No #footer element — core ss-engine-footer.js no-ops gracefully.
 */
require_once dirname(__DIR__, 2) . '/core/layout_logic.php';

$global_on = (($settings['global_comments_enabled'] ?? '1') == '1');
$post_on   = (($img['allow_comments'] ?? '1') == '1');
$comments_active = ($global_on && $post_on);

$show_desc = ($settings['single_show_description'] ?? '1') === '1';
$show_map_bg = ($settings['show_map_background'] ?? '1') === '1';

// Border config
$border_colors = [
    'yellow' => '#FFCC00',
    'white'  => '#ffffff',
    'black'  => '#000000',
    'grey'   => '#808080',
    'none'   => 'transparent'
];
$bc = $settings['image_border_color'] ?? 'yellow';
$border_val = $border_colors[$bc] ?? '#FFCC00';
$hero_bw = (int)($settings['hero_border_width'] ?? '8');

// EXIF labels
$exif_labels = [
    'Model' => 'Camera', 'lens' => 'Lens', 'FNumber' => 'Aperture',
    'ExposureTime' => 'Shutter', 'ISOSpeedRatings' => 'ISO',
    'FocalLength' => 'Focal Length', 'film' => 'Film', 'flash' => 'Flash'
];
?>

<div id="scroll-stage" class="rg-single">

    <?php include('skin-header.php'); ?>

    <!-- COMMENTS DRAWER — slides DOWN from top (masthead section feel) -->
    <div id="rg-comments-drawer" class="rg-drawer rg-drawer-top">
        <div class="rg-drawer-inner">
            <?php if ($comments_active): ?>
                <div class="rg-comments-header">Editorial Notes</div>
                <?php if ($comments): ?>
                    <div class="rg-comments-list">
                        <?php foreach ($comments as $c): ?>
                            <div class="rg-comment">
                                <div class="rg-comment-author"><?php echo htmlspecialchars($c['comment_author']); ?></div>
                                <div class="rg-comment-date"><?php echo date('F j, Y', strtotime($c['comment_date'])); ?></div>
                                <div class="rg-comment-text"><?php echo nl2br(htmlspecialchars($c['comment_text'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="rg-no-comments">No editorial notes yet.</p>
                <?php endif; ?>

                <form action="<?php echo BASE_URL; ?>process-comment.php" method="POST" class="rg-comment-form">
                    <input type="hidden" name="img_id" value="<?php echo $img['id']; ?>">
                    <h3>Leave a Note</h3>
                    <div class="rg-comment-form-row">
                        <input type="text" name="author" placeholder="Your name" required>
                        <input type="email" name="email" placeholder="Email" required>
                    </div>
                    <textarea name="comment_text" placeholder="Your thoughts..." required></textarea>
                    <button type="submit" class="rg-comment-form-submit">Submit</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- PHOTOBOX -->
    <div id="rg-photobox">
        <div class="rg-photo-wrap">
            <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
            <img class="rg-image post-image"
                 src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>"
                 alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                 style="border: <?php echo $hero_bw; ?>px solid <?php echo htmlspecialchars($border_val); ?>;">
            <?php echo $download_button; ?>
        </div>
    </div>

    <!-- INFOBOX (core navigation bar) -->
    <div id="infobox">
        <?php include dirname(__DIR__, 2) . '/core/navigation_bar.php'; ?>
    </div>

    <!-- INFO DRAWER — slides UP from bottom (magazine caption block) -->
    <div id="rg-info-drawer" class="rg-drawer rg-drawer-bottom">
        <div class="rg-drawer-inner">
            <h2 class="rg-photo-title"><?php echo htmlspecialchars($img['img_title']); ?></h2>
            <div class="rg-photo-date"><?php echo date('F j, Y', strtotime($img['img_date'])); ?></div>

            <?php if ($show_desc && !empty($img['img_description'])): ?>
                <div class="rg-description">
                    <?php echo $snapsmack->parseContent($img['img_description']); ?>
                </div>
            <?php endif; ?>

            <?php if ($exif_display_enabled ?? true): ?>
                <div class="rg-exif-section">
                    <h4>Technical Details</h4>
                    <table class="rg-exif-table">
                        <tbody>
                            <?php foreach ($exif_labels as $key => $label): ?>
                                <?php if (!empty($exif_data[$key]) && $exif_data[$key] !== 'N/A'): ?>
                                    <tr>
                                        <td><?php echo $label; ?></td>
                                        <td><?php echo htmlspecialchars($exif_data[$key]); ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include('skin-footer.php'); ?>
</div>

<script>
/**
 * Rational Geo — Dual Drawer Controller
 * Comments slide DOWN from below the header (masthead section).
 * Info/caption slides UP from above the page footer (magazine caption).
 * Intercepts core nav-bar buttons so ss-engine-footer.js is bypassed
 * (no #footer element means it no-ops).
 */
document.addEventListener('DOMContentLoaded', function() {

    var infoDrawer = document.getElementById('rg-info-drawer');
    var commDrawer = document.getElementById('rg-comments-drawer');
    var btnInfo    = document.getElementById('show-details');
    var btnComm    = document.getElementById('show-comments');
    var ease       = 'max-height 0.4s cubic-bezier(.2,.9,.2,1)';

    function initDrawer(el) {
        if (!el) return;
        el.style.maxHeight = '0';
        el.style.overflow  = 'hidden';
        el.style.transition = ease;
    }

    function openDrawer(el) {
        if (!el) return;
        el.style.maxHeight = el.scrollHeight + 'px';
        var onEnd = function(ev) {
            if (ev.propertyName !== 'max-height') return;
            el.removeEventListener('transitionend', onEnd);
            el.style.maxHeight = 'none';
            el.style.overflow  = 'visible';
        };
        el.addEventListener('transitionend', onEnd);
    }

    function closeDrawer(el, cb) {
        if (!el) return;
        el.style.overflow  = 'hidden';
        el.style.maxHeight = el.scrollHeight + 'px';
        el.offsetHeight; // reflow
        el.style.maxHeight = '0';
        var onEnd = function(ev) {
            if (ev.propertyName !== 'max-height') return;
            el.removeEventListener('transitionend', onEnd);
            if (cb) cb();
        };
        el.addEventListener('transitionend', onEnd);
    }

    function isOpen(el) {
        return el && (el.style.maxHeight !== '0' && el.style.maxHeight !== '0px');
    }

    initDrawer(infoDrawer);
    initDrawer(commDrawer);

    // --- Toggle info (bottom-up caption block) ---
    if (btnInfo) {
        btnInfo.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (isOpen(infoDrawer)) {
                closeDrawer(infoDrawer);
            } else {
                if (isOpen(commDrawer)) closeDrawer(commDrawer);
                openDrawer(infoDrawer);
                infoDrawer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }

    // --- Toggle comments (top-down masthead section) ---
    if (btnComm) {
        btnComm.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (isOpen(commDrawer)) {
                closeDrawer(commDrawer);
            } else {
                if (isOpen(infoDrawer)) closeDrawer(infoDrawer);
                openDrawer(commDrawer);
                commDrawer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }

    // --- Smackdown API bridge (keyboard engine compat) ---
    window.smackdown = window.smackdown || {};
    window.smackdown.toggleFooter = function(target, e) {
        if (e) e.preventDefault();
        if (target === 'info' && btnInfo) btnInfo.click();
        else if (target === 'comments' && btnComm) btnComm.click();
    };
    window.smackdown.closeFooter = function() {
        if (isOpen(infoDrawer)) closeDrawer(infoDrawer);
        if (isOpen(commDrawer)) closeDrawer(commDrawer);
    };

    // --- Map background ---
    <?php if ($show_map_bg): ?>
    document.body.classList.add('rg-map-bg');
    <?php endif; ?>
});
</script>
