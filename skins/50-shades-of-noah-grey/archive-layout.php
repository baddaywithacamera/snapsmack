<?php
/**
 * SNAPSMACK - 50 Shades of Noah Grey Archive Layout
 * Alpha v0.7.7
 *
 * Two-mode toggle: natural-aspect cropped grid / justified (Flickr-style) rows.
 * Visitor preference persists via localStorage (consent-gated).
 *
 * Structure is identical to Rational Geo's archive-layout.php — same PHP row-
 * builder, same toggle pattern, same inline JS. Class names use fsog- prefix.
 *
 * NOTE: Included INSIDE archive.php's #scroll-stage — we only output grid content.
 * Variables provided by archive.php: $images, $settings, $pdo, BASE_URL,
 * $album_filter, $cat_filter, $thumb_px
 */

$archive_default = $settings['archive_layout'] ?? 'cropped';
// Normalise legacy 'square'/'masonry' values from DB
if ($archive_default === 'square')  $archive_default = 'cropped';
if ($archive_default === 'masonry') $archive_default = 'justified';

// Aspect ratio bounds — clamp between 2:3 and 3:2, same as Rational Geo
$ratio_min = 2 / 3;
$ratio_max = 3 / 2;

// Pre-build justified rows
$target_row_h = (int)($settings['justified_row_height'] ?? 280);
$gap          = 4;
$ref_w        = (int)($settings['main_canvas_width'] ?? 1280);

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
if (!empty($current_row)) {
    $rows[] = ['images' => $current_row, 'full' => false];
}

$last_full_ar_sum = 0;
for ($i = count($rows) - 1; $i >= 0; $i--) {
    if ($rows[$i]['full']) {
        foreach ($rows[$i]['images'] as $_img) {
            $last_full_ar_sum += $_img['_aspect'];
        }
        break;
    }
}
if ($last_full_ar_sum <= 0) $last_full_ar_sum = $ref_w / $target_row_h;
?>

<!-- Floating layout toggle — fades in on hover, top-right -->
<div class="fsog-layout-toggle">
    <div class="fsog-layout-toggle-switch">
        <button class="fsog-toggle-btn<?php echo $archive_default === 'cropped'   ? ' active' : ''; ?>" data-layout="cropped"   title="Grid">&#9638;</button>
        <button class="fsog-toggle-btn<?php echo $archive_default === 'justified' ? ' active' : ''; ?>" data-layout="justified" title="Justified">&#9636;</button>
    </div>
</div>

<!-- Cropped grid — natural aspect ratio thumbnails -->
<div id="browse-grid" class="fsog-archive-grid" <?php echo $archive_default !== 'cropped' ? 'style="display:none;"' : ''; ?>>
    <?php if (!empty($images)): ?>
        <?php foreach ($images as $img):
            $link = BASE_URL . htmlspecialchars($img['img_slug']);

            if (!empty($img['img_thumb_aspect'])) {
                $thumb_url = BASE_URL . ltrim($img['img_thumb_aspect'], '/');
            } else {
                $full_img_path = ltrim($img['img_file'] ?? '', '/');
                $filename = basename($full_img_path);
                $folder = str_replace($filename, '', $full_img_path);
                $thumb_url = BASE_URL . $folder . 'thumbs/a_' . $filename;
            }

            $iw = (int)($img['img_width'] ?? 0);
            $ih = (int)($img['img_height'] ?? 0);
            if ($iw > 0 && $ih > 0) {
                $ratio = $iw / $ih;
                $ratio = max($ratio_min, min($ratio_max, $ratio));
            } else {
                $ratio = 1;
            }
        ?>
            <a href="<?php echo $link; ?>" class="fsog-archive-item" title="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>">
                <div class="fsog-thumb<?php echo $ratio < 1 ? ' fsog-thumb-portrait' : ''; ?>" style="aspect-ratio: <?php echo round($ratio, 4); ?>;">
                    <img src="<?php echo $thumb_url; ?>"
                         alt="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>"
                         loading="lazy">
                </div>
                <div class="fsog-archive-title"><?php echo htmlspecialchars($img['img_title'] ?? ''); ?></div>
            </a>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-sector-msg" style="grid-column: 1 / -1; text-align: center; padding: 80px 20px;">
            <p>No photographs found.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Justified grid — Flickr-style row fill with full aspect ratios -->
<div id="justified-grid" style="--justified-gap: <?php echo $gap; ?>px; --justified-row-height: <?php echo $target_row_h; ?>px; --last-row-ar-sum: <?php echo round($last_full_ar_sum, 4); ?>; <?php echo $archive_default === 'cropped' ? 'display:none;' : ''; ?>">
    <?php if (!empty($images)): ?>
        <?php foreach ($rows as $row_data):
            $row = $row_data['images'];
            $row_class = 'justified-row' . (!$row_data['full'] ? ' justified-row-last' : '');
        ?>
            <div class="<?php echo $row_class; ?>">
                <?php foreach ($row as $img):
                    $link      = BASE_URL . htmlspecialchars($img['img_slug']);
                    $img_url   = BASE_URL . ltrim($img['img_file'] ?? '', '/');
                    $flex_grow = round($img['_aspect'] * 100);
                ?>
                    <a href="<?php echo $link; ?>" class="justified-item"
                       title="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>"
                       style="flex-grow: <?php echo $flex_grow; ?>; flex-basis: 0; aspect-ratio: <?php echo round($img['_aspect'], 4); ?>;">
                        <img src="<?php echo $img_url; ?>" alt="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>" loading="lazy">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-sector-msg">
            <p>No photographs found.</p>
        </div>
    <?php endif; ?>
</div>

<script>
(function() {
    'use strict';
    var KEY           = 'fsog_gallery_layout';
    var toggleBtns    = document.querySelectorAll('.fsog-toggle-btn');
    var browseGrid    = document.getElementById('browse-grid');
    var justifiedGrid = document.getElementById('justified-grid');

    function setLayout(layout) {
        for (var i = 0; i < toggleBtns.length; i++) {
            toggleBtns[i].classList.toggle('active', toggleBtns[i].getAttribute('data-layout') === layout);
        }
        if (layout === 'justified') {
            browseGrid.style.display    = 'none';
            justifiedGrid.style.display = 'block';
        } else {
            browseGrid.style.display    = 'grid';
            justifiedGrid.style.display = 'none';
        }
        try { if (window.snapConsent && window.snapConsent.ok()) localStorage.setItem(KEY, layout); } catch(e) {}
    }

    function init() {
        var saved = null;
        try { saved = (window.snapConsent && window.snapConsent.ok()) ? localStorage.getItem(KEY) : null; } catch(e) {}
        setLayout(saved || '<?php echo htmlspecialchars($archive_default); ?>');
    }

    for (var i = 0; i < toggleBtns.length; i++) {
        toggleBtns[i].addEventListener('click', function() {
            setLayout(this.getAttribute('data-layout'));
        });
    }

    init();
}());
</script>
