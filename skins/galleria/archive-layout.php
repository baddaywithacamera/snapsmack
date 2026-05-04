<?php
/**
 * Galleria - Archive Grid Layout
 *
 * Skin-specific archive grid with miniature picture frames (square/cropped modes)
 * or Flickr-style justified row-fill (masonry mode, labelled "Justified" in Global Vibe).
 * Variables available from archive.php: $images, $settings, $all_cats, $all_albums,
 * $cat_filter, $album_filter, $archive_layout
 */

// ── JUSTIFIED (masonry) MODE ──────────────────────────────────────────────────
// Frames can't coexist with variable-width justified rows so this branch
// bypasses the frame markup entirely and uses the standard justified engine.
if ($archive_layout === 'masonry'):
    $target_row_h = (int)($settings['justified_row_height'] ?? 180);
    $gap          = (int)($settings['justified_gap'] ?? 4);
    $ref_w        = (int)($settings['main_canvas_width'] ?? 1280);

    // Group images into rows
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

    // Calculate the aspect-ratio sum of the last full row so the partial
    // last row can match its height via CSS calc(100vw / ratio-sum).
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
<div id="justified-grid" style="--justified-gap: <?php echo $gap; ?>px; --justified-row-height: <?php echo $target_row_h; ?>px; --last-row-ar-sum: <?php echo round($last_full_ar_sum, 4); ?>;">
    <?php if ($images): ?>
        <?php foreach ($rows as $row_data):
            $row = $row_data['images'];
            $row_class = 'justified-row' . (!$row_data['full'] ? ' justified-row-last' : '');
        ?>
            <div class="<?php echo $row_class; ?>">
                <?php foreach ($row as $img):
                    $link = BASE_URL . htmlspecialchars($img['img_slug']);
                    $img_url = BASE_URL . ltrim($img['img_file'], '/');
                    $flex_grow = round($img['_aspect'] * 100);
                ?>
                    <a href="<?php echo $link; ?>" class="justified-item" title="<?php echo htmlspecialchars($img['img_title']); ?>" style="flex-grow: <?php echo $flex_grow; ?>; flex-basis: 0; aspect-ratio: <?php echo round($img['_aspect'], 4); ?>;">
                        <img src="<?php echo $img_url; ?>" alt="<?php echo htmlspecialchars($img['img_title']); ?>" loading="lazy">
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-sector-msg">NO TRANSMISSIONS RECORDED IN THIS SECTOR.</div>
    <?php endif; ?>
</div>

<?php
// ── FRAMED GRID (square / cropped) MODES ─────────────────────────────────────
else:
    $show_frames = ($settings['htbs_archive_miniframes'] ?? '1') === '1';
    $grid_cols = (int)($settings['htbs_archive_cols'] ?? 4);

    // Aspect ratio bounds — clamp between 2:3 (portrait) and 3:2 (landscape)
    $ratio_min = 2 / 3;  // 0.667
    $ratio_max = 3 / 2;  // 1.500
?>

<div class="htbs-archive-grid" style="--grid-cols: <?php echo $grid_cols; ?>;">
    <?php if ($images): ?>
        <?php foreach ($images as $img):
            $link = BASE_URL . htmlspecialchars($img['img_slug']);

            // Prefer aspect-ratio thumbnail, fall back to square thumb
            if (!empty($img['img_thumb_aspect'])) {
                $thumb_url = BASE_URL . ltrim($img['img_thumb_aspect'], '/');
            } else {
                $full_img_path = ltrim($img['img_file'], '/');
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

            // Per-image frame overrides
            $d_opts = [];
            if (!empty($img['img_display_options'])) {
                $d_opts = json_decode($img['img_display_options'], true) ?? [];
            }
            $style_parts = [];
            if (!empty($d_opts['frame_color'])) $style_parts[] = "--frame-color:{$d_opts['frame_color']}";
            if (!empty($d_opts['frame_width'])) $style_parts[] = "--frame-width:{$d_opts['frame_width']}px";
            if (!empty($d_opts['mat_color'])) $style_parts[] = "--mat-color:{$d_opts['mat_color']}";
            if (!empty($d_opts['mat_width'])) $style_parts[] = "--mat-width:{$d_opts['mat_width']}px";
            $inline = !empty($style_parts) ? ' style="' . implode(';', $style_parts) . '"' : '';
        ?>
            <a href="<?php echo $link; ?>" class="htbs-archive-item" title="<?php echo htmlspecialchars($img['img_title']); ?>">
                <?php if ($show_frames): ?>
                    <div class="frame-mount"<?php echo $inline; ?>>
                        <div class="frame-border">
                            <div class="frame-mat">
                                <div class="frame-bevel">
                                    <div class="frame-image" style="aspect-ratio: <?php echo round($ratio, 4); ?>;">
                                        <img src="<?php echo $thumb_url; ?>"
                                             alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                                             loading="lazy">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="htbs-plain-thumb" style="aspect-ratio: <?php echo round($ratio, 4); ?>;">
                        <img src="<?php echo $thumb_url; ?>"
                             alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                             loading="lazy">
                    </div>
                <?php endif; ?>
                <div class="htbs-archive-title"><?php echo htmlspecialchars($img['img_title']); ?></div>
            </a>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-sector-msg" style="grid-column: 1 / -1; text-align: center; padding: 80px 20px; color: var(--text-secondary);">
            No transmissions found in this sector.
        </div>
    <?php endif; ?>
</div>

<?php endif; ?>
// EOF
