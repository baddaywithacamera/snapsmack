<?php
/**
 * SNAPSMACK - 50 Shades of Noah Grey Archive Layout
 * Alpha v0.7.9c
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

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
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

<?php
// $available_modes and $archive_layout are in scope from archive.php.
// Render toggle only when more than one layout is available.
$_fsog_icons  = ['square'=>'&#9638;','cropped'=>'&#9638;','croppedwithcalendar'=>'&#9637;','masonry'=>'&#9636;'];
$_fsog_titles = ['square'=>'Square Grid','cropped'=>'Grid','croppedwithcalendar'=>'Calendar','masonry'=>'Justified'];
$_fsog_avail  = isset($available_modes) ? $available_modes : [];
$_fsog_cur    = isset($archive_layout)  ? $archive_layout  : ($settings['archive_layout'] ?? 'cropped');
?>
<?php if (count($_fsog_avail) > 1): ?>
<!-- Floating layout toggle — fades in on hover, top-right -->
<div class="fsog-layout-toggle">
    <div class="fsog-layout-toggle-switch">
        <?php foreach ($_fsog_avail as $_fsog_mode):
            $_icon  = $_fsog_icons[$_fsog_mode]  ?? '&#9638;';
            $_title = $_fsog_titles[$_fsog_mode] ?? strtoupper($_fsog_mode);
        ?>
            <button class="fsog-toggle-btn<?php echo ($_fsog_mode === $_fsog_cur) ? ' active' : ''; ?>"
                    data-layout="<?php echo htmlspecialchars($_fsog_mode); ?>"
                    title="<?php echo $_title; ?>"><?php echo $_icon; ?></button>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

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

    var calLayout = 'croppedwithcalendar';

    function setLayout(layout) {
        // Calendar layout requires a page load (body class drives the calendar engine).
        // Also navigate away from calendar layout cleanly.
        if (layout === calLayout || document.body.classList.contains('archive-layout-' + calLayout)) {
            location.href = 'archive.php?layout=' + encodeURIComponent(layout);
            return;
        }
        for (var i = 0; i < toggleBtns.length; i++) {
            toggleBtns[i].classList.toggle('active', toggleBtns[i].getAttribute('data-layout') === layout);
        }
        if (layout === 'masonry' || layout === 'justified') {
            browseGrid.style.display    = 'none';
            justifiedGrid.style.display = 'block';
        } else {
            browseGrid.style.display    = 'grid';
            justifiedGrid.style.display = 'none';
        }
        try { localStorage.setItem(KEY, layout); } catch(e) {}
    }

    function init() {
        // If the URL already specifies the calendar layout, stay on it.
        // Reading localStorage here would trigger a setLayout() call that
        // navigates away (the body-class check in setLayout fires a redirect).
        if (document.body.classList.contains('archive-layout-' + calLayout)) return;
        var saved = null;
        try { saved = localStorage.getItem(KEY); } catch(e) {}
        setLayout(saved || '<?php echo htmlspecialchars($_fsog_cur); ?>');
    }

    for (var i = 0; i < toggleBtns.length; i++) {
        toggleBtns[i].addEventListener('click', function() {
            setLayout(this.getAttribute('data-layout'));
        });
    }

    init();

    // Dock the layout toggle into the filter bar (right side).
    // #infobox is already position:relative so the toggle uses position:absolute.
    var infobox = document.getElementById('infobox');
    var toggle  = document.querySelector('.fsog-layout-toggle');
    if (infobox && toggle) {
        infobox.appendChild(toggle);
    }
}());
</script>
<?php // ===== SNAPSMACK EOF =====
