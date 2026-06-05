<?php
/**
 * SNAPSMACK - Chaplin skin: archive layout
 *
 * Ported directly from 50-shades-of-noah-grey. Uses global Archive Appearance
 * settings (browse_cols, archive_gutter, justified_row_height, main_canvas_width)
 * — NOT skin-specific archive settings.
 *
 * NOTE: Included INSIDE archive.php's #scroll-stage — we only output grid content.
 * Variables provided by archive.php: $images, $settings, $pdo, BASE_URL,
 * $album_filter, $cat_filter, $archive_layout
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$archive_default = $settings['archive_layout'] ?? 'cropped';
if ($archive_default === 'square')  $archive_default = 'cropped';
if ($archive_default === 'masonry') $archive_default = 'justified';

$ratio_min = 2 / 3;
$ratio_max = 3 / 2;

$target_row_h = (int)($settings['justified_row_height'] ?? 280);
$gap          = (int)($settings['archive_gutter'] ?? 4);
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

$_chap_cur = isset($archive_layout) ? $archive_layout : ($settings['archive_layout'] ?? 'thumbs');
$_chap_initial_thumbs = ($_chap_cur !== 'masonry');
?>

<!-- Chaplin cropped grid — fsog classes, verbatim from 50-shades -->
<div id="browse-grid" class="fsog-archive-grid"
     style="--grid-cols: <?php echo (int)($settings['browse_cols'] ?? 4); ?>; --thumb-width: <?php echo (int)($thumb_px ?? 250); ?>px; <?php echo $_chap_initial_thumbs ? '' : 'display:none;'; ?>">
    <?php if (!empty($images)): ?>
        <?php foreach ($images as $img):
            $link = BASE_URL . htmlspecialchars($img['img_slug']);
            if (!empty($img['img_thumb_aspect'])) {
                $thumb_url = BASE_URL . ltrim($img['img_thumb_aspect'], '/');
            } else {
                $full_img_path = ltrim($img['img_file'] ?? '', '/');
                $filename  = basename($full_img_path);
                $folder    = str_replace($filename, '', $full_img_path);
                $thumb_url = BASE_URL . $folder . 'thumbs/a_' . $filename;
            }
            $iw = (int)($img['img_width'] ?? 0);
            $ih = (int)($img['img_height'] ?? 0);
            if ($iw > 0 && $ih > 0) {
                $ratio = max($ratio_min, min($ratio_max, $iw / $ih));
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
        <div style="grid-column: 1 / -1; text-align: center; padding: 80px 20px; color: var(--chap-ink-muted);">
            <p>The projector is dark. No frames to show.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Justified grid — Flickr-style row fill -->
<div id="justified-grid"
     style="--justified-gap: <?php echo $gap; ?>px; --justified-row-height: <?php echo $target_row_h; ?>px; --last-row-ar-sum: <?php echo round($last_full_ar_sum, 4); ?>; <?php echo $_chap_initial_thumbs ? 'display:none;' : ''; ?>">
    <?php if (!empty($images)): ?>
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
        <div style="text-align: center; padding: 80px 20px; color: var(--chap-ink-muted);">
            <p>The projector is dark. No frames to show.</p>
        </div>
    <?php endif; ?>
</div>


<?php // ===== SNAPSMACK EOF =====