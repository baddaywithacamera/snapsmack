<?php
/**
 * SNAPSMACK - 50 Shades of Noah Grey Archive Layout
 * Alpha v0.7.7
 *
 * Three-way toggle: square grid / natural-cropped grid / justified (Flickr-style) masonry.
 * Visitor preference persists via localStorage (consent-gated).
 *
 * NOTE: Included INSIDE archive.php's #scroll-stage — we only output grid content.
 * Variables provided by archive.php: $images, $settings, $pdo, BASE_URL,
 * $album_filter, $cat_filter, $thumb_px
 *
 * JS is handled by ss-engine-fsog-layout-toggle.js (loaded via manifest require_scripts).
 * The toggle widget exposes data-key and data-default so the engine needs no PHP injection.
 */

$archive_default = $settings['archive_layout'] ?? 'square';
$grid_cols       = (int)($settings['browse_cols'] ?? 4);
$thumb_width     = (int)($thumb_px ?? 400);

// Aspect ratio bounds for cropped mode (same as Galleria / RG)
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

// Initial visibility per default setting.
// PHP sets display:none on hidden panes so the page renders correctly before
// ss-engine-fsog-layout-toggle.js runs (no FOUC).
// Note: 'masonry' is the DB value for the justified mode.
$show_square    = $archive_default === 'square';
$show_cropped   = $archive_default === 'cropped';
$show_justified = ($archive_default === 'masonry' || $archive_default === 'justified');
?>

<!-- Floating layout toggle — fades in on hover, top-right.
     data-key   = localStorage key (read by ss-engine-fsog-layout-toggle.js)
     data-default = server-side default (normalised to 'justified' in the engine) -->
<div class="fsog-layout-toggle"
     data-key="fsog_gallery_layout"
     data-default="<?php echo htmlspecialchars($archive_default); ?>">
    <div class="fsog-layout-toggle-switch">
        <button class="fsog-toggle-btn<?php echo $show_square    ? ' active' : ''; ?>" data-layout="square"    title="Square Grid">&#9632;</button>
        <button class="fsog-toggle-btn<?php echo $show_cropped   ? ' active' : ''; ?>" data-layout="cropped"   title="Natural Aspect">&#9638;</button>
        <button class="fsog-toggle-btn<?php echo $show_justified ? ' active' : ''; ?>" data-layout="justified" title="Justified">&#9636;</button>
    </div>
</div>

<!-- Square grid -->
<div id="browse-grid" class="square-grid"
     style="--grid-cols: <?php echo $grid_cols; ?>; --thumb-width: <?php echo $thumb_width; ?>px;
            <?php echo !$show_square ? 'display:none;' : ''; ?>">
    <?php if (!empty($images)): ?>
        <?php foreach ($images as $img):
            $link          = BASE_URL . htmlspecialchars($img['img_slug']);
            $full_img_path = ltrim($img['img_file'], '/');
            $filename      = basename($full_img_path);
            $folder        = str_replace($filename, '', $full_img_path);
            $thumb_url     = BASE_URL . $folder . 'thumbs/t_' . $filename;
        ?>
            <div class="thumb-container">
                <a href="<?php echo $link; ?>" class="thumb-link" title="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>">
                    <img src="<?php echo $thumb_url; ?>" alt="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>" loading="lazy">
                </a>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-sector-msg">NO TRANSMISSIONS RECORDED IN THIS SECTOR.</div>
    <?php endif; ?>
</div>

<!-- Cropped grid (natural aspect, a_ thumbs) -->
<div id="browse-grid-cropped" class="cropped-grid"
     style="--grid-cols: <?php echo $grid_cols; ?>; --thumb-width: <?php echo $thumb_width; ?>px;
            <?php echo !$show_cropped ? 'display:none;' : ''; ?>">
    <?php if (!empty($images)): ?>
        <?php foreach ($images as $img):
            $link          = BASE_URL . htmlspecialchars($img['img_slug']);
            $full_img_path = ltrim($img['img_file'], '/');
            $filename      = basename($full_img_path);
            $folder        = str_replace($filename, '', $full_img_path);
            $thumb_url     = BASE_URL . $folder . 'thumbs/a_' . $filename;
            $orientation   = (int)($img['img_orientation'] ?? 0);
            $orient_class  = $orientation === 1 ? 'orient-portrait' : ($orientation === 2 ? 'orient-square' : 'orient-landscape');
        ?>
            <div class="thumb-container cropped-item">
                <a href="<?php echo $link; ?>" class="thumb-link <?php echo $orient_class; ?>" title="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>">
                    <img src="<?php echo $thumb_url; ?>" alt="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>" loading="lazy">
                </a>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-sector-msg">NO TRANSMISSIONS RECORDED IN THIS SECTOR.</div>
    <?php endif; ?>
</div>

<!-- Justified grid (full images, Flickr-style row fill).
     Class 'justified-grid' included for fjGallery compatibility. -->
<div id="justified-grid" class="justified-grid"
     style="--justified-gap: <?php echo $gap; ?>px;
            --justified-row-height: <?php echo $target_row_h; ?>px;
            --last-row-ar-sum: <?php echo round($last_full_ar_sum, 4); ?>;
            <?php echo !$show_justified ? 'display:none;' : ''; ?>">
    <?php if (!empty($rows)): ?>
        <?php foreach ($rows as $row_data):
            $row_class = 'justified-row' . (!$row_data['full'] ? ' justified-row-last' : '');
        ?>
            <div class="<?php echo $row_class; ?>">
                <?php foreach ($row_data['images'] as $img):
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
        <div class="empty-sector-msg">NO TRANSMISSIONS RECORDED IN THIS SECTOR.</div>
    <?php endif; ?>
</div>
