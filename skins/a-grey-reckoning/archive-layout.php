<?php
/**
 * SNAPSMACK - Archive grid layout for the a-grey-reckoning skin
 * Alpha v0.7.4
 *
 * White-bordered square thumbnails on black, reminiscent of the
 * noahgrey.com/photography/portfolios grid circa 2006. Simple,
 * deliberate, no ornamentation.
 *
 * Variables available from archive.php: $images, $settings, $all_cats,
 * $all_albums, $cat_filter, $album_filter, $archive_layout
 */

$grid_cols    = (int)($settings['htbs_browse_cols'] ?? 4);
$border_width = (int)($settings['htbs_archive_border_width'] ?? 4);

// Count for pagination display
$total = count($images ?? []);
?>

<!-- BREADCRUMB / CONTEXT BAR -->
<div class="ge-canvas">
    <div class="ge-title-bar">
        <span class="ge-title-text">
            <?php echo htmlspecialchars($site_name ?? 'SNAPSMACK'); ?> &rsaquo;
            <?php if ($album_filter):
                foreach ($all_albums as $a) {
                    if ($a['id'] == $album_filter) {
                        echo htmlspecialchars(strtoupper($a['album_name']));
                        break;
                    }
                }
            elseif ($cat_filter):
                foreach ($all_cats as $c) {
                    if ($c['id'] == $cat_filter) {
                        echo htmlspecialchars(strtoupper($c['cat_name']));
                        break;
                    }
                }
            else:
                echo 'ARCHIVE';
            endif; ?>
        </span>
        <span class="ge-title-date"><?php echo $total; ?> ENTR<?php echo $total !== 1 ? 'IES' : 'Y'; ?></span>
    </div>
</div>

<!-- THUMBNAIL GRID -->
<div class="ge-archive-grid" style="--grid-cols: <?php echo $grid_cols; ?>;">
    <?php if ($images): ?>
        <?php foreach ($images as $img):
            $link = BASE_URL . htmlspecialchars($img['img_slug']);

            // Prefer square thumb, fall back to constructing path
            if (!empty($img['img_thumb_square'])) {
                $thumb_url = BASE_URL . ltrim($img['img_thumb_square'], '/');
            } else {
                $full_img_path = ltrim($img['img_file'], '/');
                $filename = basename($full_img_path);
                $folder = str_replace($filename, '', $full_img_path);
                $thumb_url = BASE_URL . $folder . 'thumbs/t_' . $filename;
            }
        ?>
            <a href="<?php echo $link; ?>" class="ge-archive-thumb" title="<?php echo htmlspecialchars($img['img_title']); ?>">
                <img src="<?php echo $thumb_url; ?>"
                     alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                     loading="lazy"
                     style="border-width: <?php echo $border_width; ?>px;">
                <span class="ge-archive-title"><?php echo htmlspecialchars($img['img_title']); ?></span>
            </a>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="grid-column: 1 / -1; text-align: center; padding: 80px 20px; color: var(--ge-text-dim);">
            No photographs found.
        </div>
    <?php endif; ?>
</div>
