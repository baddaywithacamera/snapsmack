<?php
/**
 * SNAPSMACK - Chaplin skin: archive layout
 *
 * Structure taken from 50-shades-of-noah-grey (natural aspect ratio thumbs +
 * justified masonry). Chaplin black background, New Horizon revival_double
 * border treatment on thumbnails.
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

// Use $archive_layout from archive.php scope.
$_chap_cur           = isset($archive_layout) ? $archive_layout : ($settings['archive_layout'] ?? 'thumbs');
$_chap_initial_thumbs = ($_chap_cur !== 'masonry');

// Aspect ratio bounds — clamp 2:3 → 3:2
$ratio_min = 2 / 3;
$ratio_max = 3 / 2;

// Justified row builder
$target_row_h      = (int)($settings['justified_row_height'] ?? 280);
$gap               = 4;
$ref_w             = (int)($settings['chap_archive_max_width'] ?? 1400);
$rows              = [];
$current_row       = [];
$current_row_width = 0;

foreach ($images as $img) {
    $iw = (int)($img['img_width']  ?? 400);
    $ih = (int)($img['img_height'] ?? 400);
    if ($ih <= 0) $ih = 400;
    if ($iw <= 0) $iw = 400;
    $img['_aspect']    = $iw / $ih;
    $scaled_w          = round($img['_aspect'] * $target_row_h);
    $current_row[]     = $img;
    $current_row_width += $scaled_w + $gap;
    if ($current_row_width - $gap >= $ref_w) {
        $rows[]            = ['images' => $current_row, 'full' => true];
        $current_row       = [];
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

<!-- Chaplin cropped grid — natural aspect ratio, New Horizon borders -->
<div id="browse-grid" class="chap-archive-grid" <?php echo $_chap_initial_thumbs ? '' : 'style="display:none;"'; ?>>
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
            $iw = (int)($img['img_width']  ?? 0);
            $ih = (int)($img['img_height'] ?? 0);
            if ($iw > 0 && $ih > 0) {
                $ratio = max($ratio_min, min($ratio_max, $iw / $ih));
            } else {
                $ratio = 1;
            }
        ?>
            <a href="<?php echo $link; ?>"
               class="chap-archive-item"
               title="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>">
                <div class="chap-thumb<?php echo $ratio < 1 ? ' chap-thumb-portrait' : ''; ?>"
                     style="aspect-ratio: <?php echo round($ratio, 4); ?>;">
                    <img src="<?php echo $thumb_url; ?>"
                         alt="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>"
                         loading="lazy">
                </div>
                <div class="chap-archive-title"><?php echo htmlspecialchars($img['img_title'] ?? ''); ?></div>
            </a>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="grid-column: 1 / -1; text-align: center; padding: 80px 20px; color: var(--chap-ink-muted);">
            <p>The projector is dark. No frames to show.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Justified/masonry grid -->
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
                    <a href="<?php echo $link; ?>"
                       class="justified-item"
                       title="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>"
                       style="flex-grow: <?php echo $flex_grow; ?>; flex-basis: 0; aspect-ratio: <?php echo round($img['_aspect'], 4); ?>;">
                        <img src="<?php echo $img_url; ?>"
                             alt="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>"
                             loading="lazy">
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

<script>
(function () {
    'use strict';
    var browseGrid    = document.getElementById('browse-grid');
    var justifiedGrid = document.getElementById('justified-grid');

    function applyLayout(layout) {
        if (!browseGrid || !justifiedGrid) return;
        if (layout === 'masonry') {
            browseGrid.style.display    = 'none';
            justifiedGrid.style.display = 'block';
        } else {
            browseGrid.style.display    = 'grid';
            justifiedGrid.style.display = 'none';
        }
    }

    var initial = document.documentElement.getAttribute('data-archive-layout') || 'thumbs';
    applyLayout(initial);

    document.addEventListener('smackarchive:layoutchange', function (e) {
        applyLayout((e.detail && e.detail.layout) || 'thumbs');
    });
}());
</script>
<?php // ===== SNAPSMACK EOF =====
