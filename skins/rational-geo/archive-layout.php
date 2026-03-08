<?php
/**
 * SNAPSMACK - Rational Geo Archive Layout
 * Alpha v0.7
 *
 * Toggle between natural-ratio thumbnail grid and justified/masonry layout.
 * Visitor preference persists via localStorage.
 *
 * NOTE: This file is included INSIDE archive.php's #scroll-stage.
 * archive.php already handles: skin-header, #infobox, #scroll-stage wrapper,
 * skin-footer, and footer-scripts. We ONLY output grid content here,
 * exactly like Galleria's archive-layout.php does.
 *
 * Variables provided by archive.php:
 *   $images, $settings, $pdo, BASE_URL, $album_filter, $cat_filter
 */

$archive_default = $settings['archive_default_layout'] ?? 'cropped';

// Aspect ratio bounds — clamp between 2:3 (portrait) and 3:2 (landscape), same as Galleria
$ratio_min = 2 / 3;  // 0.667
$ratio_max = 3 / 2;  // 1.500

$show_map_bg = ($settings['show_map_background'] ?? '1') === '1';
?>

<!-- Floating layout toggle — appears on hover, top-right -->
<div class="rg-layout-toggle">
    <div class="rg-layout-toggle-switch">
        <button class="rg-toggle-btn<?php echo $archive_default === 'cropped' ? ' active' : ''; ?>" data-layout="cropped" title="Grid">&#9638;</button>
        <button class="rg-toggle-btn<?php echo $archive_default !== 'cropped' ? ' active' : ''; ?>" data-layout="justified" title="Justified">&#9636;</button>
    </div>
</div>

<!-- Cropped grid — Galleria structure, RG borders -->
<div id="browse-grid" class="rg-archive-grid" <?php echo $archive_default !== 'cropped' ? 'style="display:none;"' : ''; ?>>
    <?php if (!empty($images)): ?>
        <?php foreach ($images as $img):
            $link = BASE_URL . htmlspecialchars($img['img_slug']);

            // Prefer aspect-ratio thumbnail, fall back to constructed thumb path
            if (!empty($img['img_thumb_aspect'])) {
                $thumb_url = BASE_URL . ltrim($img['img_thumb_aspect'], '/');
            } else {
                $full_img_path = ltrim($img['img_file'] ?? '', '/');
                $filename = basename($full_img_path);
                $folder = str_replace($filename, '', $full_img_path);
                $thumb_url = BASE_URL . $folder . 'thumbs/t_' . $filename;
            }

            // Compute clamped aspect ratio from stored dimensions
            $iw = (int)($img['img_width'] ?? 0);
            $ih = (int)($img['img_height'] ?? 0);
            if ($iw > 0 && $ih > 0) {
                $ratio = $iw / $ih;
                $ratio = max($ratio_min, min($ratio_max, $ratio));
            } else {
                $ratio = 1; // fallback square
            }
        ?>
            <a href="<?php echo $link; ?>" class="rg-archive-item" title="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>">
                <div class="rg-thumb<?php echo $ratio < 1 ? ' rg-thumb-portrait' : ''; ?>" style="aspect-ratio: <?php echo round($ratio, 4); ?>;">
                    <img src="<?php echo $thumb_url; ?>"
                         alt="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>"
                         loading="lazy">
                </div>
                <div class="rg-archive-title"><?php echo htmlspecialchars($img['img_title'] ?? ''); ?></div>
            </a>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="rg-no-photos" style="grid-column: 1 / -1; text-align: center; padding: 80px 20px;">
            <p>No photographs found.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Justified grid — row-building PHP lifted from archive.php masonry branch.
     public-facing.css handles the flex layout; we just add RG border overrides. -->
<?php
    $target_row_h = (int)($settings['justified_row_height'] ?? 260);
    $gap          = 4;
    $ref_w        = (int)($settings['main_canvas_width'] ?? 1200);
?>
<div id="justified-grid" style="--justified-gap: <?php echo $gap; ?>px; --justified-row-height: <?php echo $target_row_h; ?>px; <?php echo $archive_default === 'cropped' ? 'display:none;' : ''; ?>">
    <?php if (!empty($images)):
        // Build rows by accumulating images until estimated width reaches reference width
        $rows = [];
        $current_row = [];
        $current_row_width = 0;

        foreach ($images as $img) {
            $iw = (int)($img['img_width'] ?? 400);
            $ih = (int)($img['img_height'] ?? 400);
            if ($ih <= 0) $ih = 400;
            if ($iw <= 0) $iw = 400;

            $img['_aspect'] = $iw / $ih;
            $scaled_w = round($img['_aspect'] * $target_row_h);

            $current_row[] = $img;
            $current_row_width += $scaled_w + $gap;

            if ($current_row_width - $gap >= $ref_w) {
                $rows[] = ['images' => $current_row, 'full' => true];
                $current_row = [];
                $current_row_width = 0;
            }
        }
        // Last partial row — marked so CSS doesn't over-stretch
        if (!empty($current_row)) {
            $rows[] = ['images' => $current_row, 'full' => false];
        }
    ?>
        <?php foreach ($rows as $row_data):
            $row = $row_data['images'];
            $is_full = $row_data['full'];
            $row_class = 'justified-row' . (!$is_full ? ' justified-row-last' : '');
        ?>
            <div class="<?php echo $row_class; ?>">
                <?php foreach ($row as $img):
                    $link = BASE_URL . htmlspecialchars($img['img_slug']);
                    $full_img_path = ltrim($img['img_file'] ?? '', '/');
                    $filename = basename($full_img_path);
                    $folder = str_replace($filename, '', $full_img_path);
                    $thumb_url = BASE_URL . $folder . 'thumbs/a_' . $filename;
                    $flex_grow = round($img['_aspect'] * 100);
                ?>
                    <a href="<?php echo $link; ?>" class="justified-item" title="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>" style="flex-grow: <?php echo $flex_grow; ?>; aspect-ratio: <?php echo round($img['_aspect'], 4); ?>;">
                        <img src="<?php echo $thumb_url; ?>" alt="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>" loading="lazy">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="rg-no-photos">
            <p>No photographs found.</p>
        </div>
    <?php endif; ?>
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
