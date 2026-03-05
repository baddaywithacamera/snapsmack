<?php
/**
 * Hip to be Square - Archive Grid Layout
 *
 * Skin-specific archive grid with miniature picture frames.
 * Variables available from archive.php: $images, $settings, $all_cats, $all_albums,
 * $cat_filter, $album_filter, $archive_layout
 */

$show_frames = ($settings['htbs_archive_miniframes'] ?? '1') === '1';
$grid_cols = (int)($settings['htbs_archive_cols'] ?? 4);
?>

<div class="htbs-archive-grid" style="--grid-cols: <?php echo $grid_cols; ?>;">
    <?php if ($images): ?>
        <?php foreach ($images as $img):
            $link = BASE_URL . htmlspecialchars($img['img_slug']);
            $full_img_path = ltrim($img['img_file'], '/');
            $filename = basename($full_img_path);
            $folder = str_replace($filename, '', $full_img_path);
            // Use square thumbnail for the grid
            $thumb_url = BASE_URL . $folder . 'thumbs/t_' . $filename;

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
                                    <div class="frame-image">
                                        <img src="<?php echo $thumb_url; ?>"
                                             alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                                             loading="lazy">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="htbs-plain-thumb">
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
