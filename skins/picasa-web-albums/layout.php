<?php
/**
 * SNAPSMACK - Picasa Web Albums Single Image View
 * v1.0
 *
 * Clean viewer with toolbar, filmstrip, and expandable details.
 * Inspired by Picasa Web Albums' image viewer interface.
 */
require_once dirname(__DIR__, 2) . '/core/layout_logic.php';

$global_on = (($settings['global_comments_enabled'] ?? '1') == '1');
$post_on = (($img['allow_comments'] ?? '1') == '1');
$comments_active = ($global_on && $post_on);
$show_filmstrip = ($settings['lightbox_filmstrip'] ?? '1') === '1';
$show_exif = ($settings['lightbox_show_exif'] ?? '0') === '1';
$show_desc = ($settings['single_show_description'] ?? '1') === '1';
$show_signals = ($settings['single_show_signals'] ?? '1') === '1';

// Fetch filmstrip images
$now_local = date('Y-m-d H:i:s');
$film_stmt = $pdo->prepare("
    SELECT id, img_slug, img_thumb_square, img_title
    FROM snap_images
    WHERE img_status = 'published' AND img_date <= ?
    ORDER BY img_date DESC LIMIT 50
");
$film_stmt->execute([$now_local]);
$filmstrip_images = $film_stmt->fetchAll(PDO::FETCH_ASSOC);

$exif_labels = [
    'Model' => 'Model', 'lens' => 'Lens', 'FNumber' => 'Aperture',
    'ExposureTime' => 'Shutter', 'ISOSpeedRatings' => 'ISO',
    'FocalLength' => 'Focal', 'film' => 'Film', 'flash' => 'Flash'
];
?>

<div id="scroll-stage" class="pwa-single">

    <?php include('skin-header.php'); ?>

    <!-- Toolbar -->
    <div class="pwa-toolbar">
        <div class="pwa-toolbar-container">
            <button class="pwa-toolbar-btn" title="Back" onclick="history.back()">
                <span class="pwa-icon">←</span>
            </button>
            <span class="pwa-toolbar-sep"></span>
            <button class="pwa-toolbar-btn" title="Share">
                <span class="pwa-icon">↗</span> <span class="pwa-toolbar-label">Share</span>
            </button>
            <span class="pwa-toolbar-sep"></span>
            <button class="pwa-toolbar-btn" title="Rotate">
                <span class="pwa-icon">↻</span>
            </button>
            <button class="pwa-toolbar-btn pwa-slideshow-btn" title="Slideshow">
                <span class="pwa-icon">▶</span>
            </button>
        </div>
    </div>

    <!-- Main Image Container -->
    <div class="pwa-viewer-wrapper">
        <div class="pwa-image-container">
            <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
            <img class="pwa-main-image post-image"
                 src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>"
                 alt="<?php echo htmlspecialchars($img['img_title']); ?>">
            <?php echo $download_button; ?>
        </div>

        <!-- Navigation Arrows -->
        <?php if (!empty($prev_slug)): ?>
            <a class="pwa-nav-arrow pwa-nav-prev" href="<?php echo $prev_slug; ?>" title="Previous">‹</a>
        <?php endif; ?>
        <?php if (!empty($next_slug)): ?>
            <a class="pwa-nav-arrow pwa-nav-next" href="<?php echo $next_slug; ?>" title="Next">›</a>
        <?php endif; ?>
    </div>

    <!-- INFO DRAWER — slides DOWN from below the toolbar -->
    <div id="pwa-info-drawer" class="pwa-drawer pwa-drawer-top">
        <div class="pwa-drawer-inner">
            <div class="pwa-image-title-block">
                <h1 class="pwa-image-title"><?php echo htmlspecialchars($img['img_title']); ?></h1>
                <div class="pwa-image-date"><?php echo date('F j, Y', strtotime($img['img_date'])); ?></div>
            </div>

            <?php if ($show_desc && !empty($img['img_description'])): ?>
                <div class="pwa-description">
                    <?php echo $snapsmack->parseContent($img['img_description']); ?>
                </div>
            <?php endif; ?>

            <?php if ($exif_display_enabled ?? true): ?>
                <div class="pwa-details">
                    <button class="pwa-details-toggle" onclick="var c=this.nextElementSibling;c.classList.toggle('pwa-expanded');this.querySelector('.pwa-toggle-icon').textContent=c.classList.contains('pwa-expanded')?'▲':'▼';">
                        <span class="pwa-toggle-icon">▼</span> Details
                    </button>
                    <div class="pwa-details-content">
                        <table class="pwa-exif-table">
                            <?php foreach ($exif_labels as $key => $label): ?>
                                <?php if (!empty($exif_data[$key]) && $exif_data[$key] !== 'N/A'): ?>
                                    <tr>
                                        <td class="pwa-exif-label"><?php echo $label; ?></td>
                                        <td class="pwa-exif-value"><?php echo htmlspecialchars($exif_data[$key]); ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Info Bar -->
    <div id="infobox">
        <?php include dirname(__DIR__, 2) . '/core/navigation_bar.php'; ?>
    </div>

    <!-- COMMENTS DRAWER — slides UP from above the filmstrip -->
    <div id="pwa-comments-drawer" class="pwa-drawer pwa-drawer-bottom">
        <div class="pwa-drawer-inner">
            <?php if ($comments_active): ?>
                <div class="pwa-comments-header">Signals</div>
                <?php if ($comments): ?>
                    <div class="pwa-comments-list">
                        <?php foreach ($comments as $c): ?>
                            <div class="pwa-comment">
                                <span class="pwa-comment-author"><?php echo htmlspecialchars($c['comment_author']); ?></span>
                                <span class="pwa-comment-date"><?php echo date('M j, Y', strtotime($c['comment_date'])); ?></span>
                                <p class="pwa-comment-text"><?php echo nl2br(htmlspecialchars($c['comment_text'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="pwa-no-comments">No signals recorded.</p>
                <?php endif; ?>

                <form action="<?php echo BASE_URL; ?>process-comment.php" method="POST" class="pwa-comment-form">
                    <input type="hidden" name="img_id" value="<?php echo $img['id']; ?>">
                    <div class="pwa-form-row">
                        <input type="text" name="author" placeholder="Name" required>
                        <input type="email" name="email" placeholder="Email" required>
                    </div>
                    <textarea name="comment_text" placeholder="Your comment..." required></textarea>
                    <button type="submit" class="pwa-submit-btn">Post</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filmstrip -->
    <?php if ($show_filmstrip && !empty($filmstrip_images)): ?>
    <div class="pwa-filmstrip">
        <?php foreach ($filmstrip_images as $fi):
            $fi_link = BASE_URL . htmlspecialchars($fi['img_slug']);
            $fi_thumb = !empty($fi['img_thumb_square']) ? BASE_URL . ltrim($fi['img_thumb_square'], '/') : '';
            $is_current = ($fi['id'] == $img['id']) ? ' pwa-current' : '';
        ?>
            <a href="<?php echo $fi_link; ?>" class="pwa-filmstrip-item<?php echo $is_current; ?>" title="<?php echo htmlspecialchars($fi['img_title']); ?>">
                <img src="<?php echo $fi_thumb; ?>" alt="<?php echo htmlspecialchars($fi['img_title']); ?>" loading="lazy">
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php include('skin-footer.php'); ?>
</div>

<script>
/**
 * Picasa Web Albums — Dual Drawer Controller
 * Info slides DOWN from below the toolbar.
 * Comments slide UP from above the filmstrip.
 * Intercepts the core nav-bar buttons so ss-engine-footer.js is bypassed
 * (it no-ops because there's no #footer element).
 */
document.addEventListener('DOMContentLoaded', function() {

    // --- Filmstrip auto-scroll ---
    var current = document.querySelector('.pwa-filmstrip-item.pwa-current');
    if (current) current.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });

    // --- Drawer elements ---
    var infoDrawer = document.getElementById('pwa-info-drawer');
    var commDrawer = document.getElementById('pwa-comments-drawer');
    var btnInfo    = document.getElementById('show-details');
    var btnComm    = document.getElementById('show-comments');
    var ease       = 'max-height 0.35s cubic-bezier(.2,.9,.2,1)';

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

    // --- Toggle info (top-down) ---
    if (btnInfo) {
        btnInfo.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation(); // beat the core footer engine
            if (isOpen(infoDrawer)) {
                closeDrawer(infoDrawer);
            } else {
                // Close comments if open, then open info
                if (isOpen(commDrawer)) closeDrawer(commDrawer);
                openDrawer(infoDrawer);
                infoDrawer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }

    // --- Toggle comments (bottom-up) ---
    if (btnComm) {
        btnComm.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (isOpen(commDrawer)) {
                closeDrawer(commDrawer);
            } else {
                // Close info if open, then open comments
                if (isOpen(infoDrawer)) closeDrawer(infoDrawer);
                openDrawer(commDrawer);
                commDrawer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }

    // Expose to other engines
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
});
</script>
