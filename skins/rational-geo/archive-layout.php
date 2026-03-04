<?php
/**
 * SNAPSMACK - Rational Geo Archive Layout
 * v1.0
 *
 * Toggle between cropped thumbnail grid and justified/masonry layout.
 * Visitor preference persists via localStorage.
 *
 * Variables provided by archive.php:
 *   $images, $settings, $pdo, BASE_URL, $album_filter, $cat_filter
 */

$archive_default = $settings['archive_default_layout'] ?? 'cropped';

// Border config (same mapping as layout.php)
$border_colors = [
    'yellow' => '#FFCC00',
    'white'  => '#ffffff',
    'black'  => '#000000',
    'grey'   => '#808080',
    'none'   => 'transparent'
];
$bc = $settings['image_border_color'] ?? 'yellow';
$border_val = $border_colors[$bc] ?? '#FFCC00';
$thumb_bw = (int)($settings['thumb_border_width'] ?? '2');

$show_map_bg = ($settings['show_map_background'] ?? '1') === '1';
?>

<div id="scroll-stage" class="rg-archive">

    <?php include('skin-header.php'); ?>

    <!-- Archive header with breadcrumb and layout toggle -->
    <div class="rg-archive-header">
        <div class="rg-archive-header-left">
            <h1 class="rg-archive-title">Gallery</h1>

            <?php if (!empty($album_filter) || !empty($cat_filter)): ?>
            <div class="rg-breadcrumb">
                <a href="<?php echo BASE_URL; ?>archive.php">All Photographs</a>
                <?php if (!empty($album_filter)): ?>
                    <span class="rg-breadcrumb-sep">›</span>
                    <span class="rg-breadcrumb-current">
                        <?php
                        $album_stmt = $pdo->prepare("SELECT album_name FROM snap_albums WHERE album_id = ?");
                        $album_stmt->execute([$album_filter]);
                        $album = $album_stmt->fetch(PDO::FETCH_ASSOC);
                        echo htmlspecialchars($album['album_name'] ?? 'Album');
                        ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($cat_filter)): ?>
                    <span class="rg-breadcrumb-sep">›</span>
                    <span class="rg-breadcrumb-current">
                        <?php
                        $cat_stmt = $pdo->prepare("SELECT cat_name FROM snap_categories WHERE cat_id = ?");
                        $cat_stmt->execute([$cat_filter]);
                        $cat = $cat_stmt->fetch(PDO::FETCH_ASSOC);
                        echo htmlspecialchars($cat['cat_name'] ?? 'Category');
                        ?>
                    </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="rg-layout-toggle">
            <span class="rg-layout-toggle-label">View</span>
            <div class="rg-layout-toggle-switch">
                <button class="rg-toggle-btn<?php echo $archive_default === 'cropped' ? ' active' : ''; ?>" data-layout="cropped" title="Grid">▦</button>
                <button class="rg-toggle-btn<?php echo $archive_default !== 'cropped' ? ' active' : ''; ?>" data-layout="justified" title="Justified">▤</button>
            </div>
        </div>
    </div>

    <!-- Cropped grid -->
    <div id="browse-grid" class="cropped-grid" style="<?php echo $archive_default !== 'cropped' ? 'display:none;' : ''; ?>">
        <?php if (!empty($images)): ?>
            <?php foreach ($images as $img): ?>
                <a href="<?php echo BASE_URL . htmlspecialchars($img['img_slug']); ?>" class="grid-item" title="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>">
                    <img src="<?php echo BASE_URL . ltrim($img['img_thumb_square'], '/'); ?>"
                         alt="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>"
                         loading="lazy"
                         style="border: <?php echo $thumb_bw; ?>px solid <?php echo htmlspecialchars($border_val); ?>;">
                    <div class="grid-item-overlay">
                        <div class="grid-item-title"><?php echo htmlspecialchars($img['img_title'] ?? ''); ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="rg-no-photos">
                <p>No photographs found.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Justified grid -->
    <div id="justified-grid" style="<?php echo $archive_default === 'cropped' ? 'display:none;' : ''; ?>">
        <?php if (!empty($images)): ?>
            <?php foreach ($images as $img): ?>
                <a href="<?php echo BASE_URL . htmlspecialchars($img['img_slug']); ?>" class="justified-item" title="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>">
                    <img src="<?php echo BASE_URL . ltrim($img['img_thumb_aspect'] ?? $img['img_thumb_square'], '/'); ?>"
                         alt="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>"
                         loading="lazy"
                         style="border: <?php echo $thumb_bw; ?>px solid <?php echo htmlspecialchars($border_val); ?>;">
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="rg-no-photos">
                <p>No photographs found.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php include('skin-footer.php'); ?>
</div>

<script>
(function() {
    'use strict';
    var KEY = 'rg_gallery_layout';
    var toggleBtns    = document.querySelectorAll('.rg-toggle-btn');
    var browseGrid    = document.getElementById('browse-grid');
    var justifiedGrid = document.getElementById('justified-grid');

    function setLayout(layout) {
        for (var i = 0; i < toggleBtns.length; i++) {
            toggleBtns[i].classList.toggle('active', toggleBtns[i].getAttribute('data-layout') === layout);
        }
        if (layout === 'justified') {
            browseGrid.style.display = 'none';
            justifiedGrid.style.display = 'block';
            if (window.smackJustified && typeof window.smackJustified.init === 'function') {
                window.smackJustified.init();
            }
        } else {
            browseGrid.style.display = 'grid';
            justifiedGrid.style.display = 'none';
        }
        try { localStorage.setItem(KEY, layout); } catch(e) {}
    }

    function init() {
        var saved = null;
        try { saved = localStorage.getItem(KEY); } catch(e) {}
        setLayout(saved || '<?php echo htmlspecialchars($archive_default); ?>');
    }

    for (var i = 0; i < toggleBtns.length; i++) {
        toggleBtns[i].addEventListener('click', function() {
            setLayout(this.getAttribute('data-layout'));
        });
    }

    <?php if ($show_map_bg): ?>
    document.body.classList.add('rg-map-bg');
    <?php endif; ?>

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
