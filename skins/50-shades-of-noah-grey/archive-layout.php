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

// Justified rows are laid out CLIENT-SIDE by our own ss-engine-rows.js from each
// tile's data-w/data-h — no server-side row pre-build, and NO third-party library
// (this replaces the MIT-licensed fjGallery so the whole gallery stays SnapSmack).
$gap = 4;
?>

<?php
// 0.7.79: Toggle UI is rendered by core archive.php (not the skin). This file
// renders only the two photo grids; archive.php's [T][M][C] header drives
// which one is visible via <html data-archive-layout="thumbs|masonry">.
// Calendar is independent (handled by ss-engine-calendar.js).
$_fsog_cur = isset($archive_layout) ? $archive_layout : ($settings['archive_layout'] ?? 'thumbs');
$_fsog_initial_thumbs = ($_fsog_cur === 'thumbs');
?>

<!-- Cropped grid — natural aspect ratio thumbnails -->
<div id="browse-grid" class="fsog-archive-grid archive-grid" <?php echo $_fsog_initial_thumbs ? '' : 'style="display:none;"'; ?>>
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
                         alt="<?php echo htmlspecialchars(($img['img_alt'] ?? '') !== '' ? $img['img_alt'] : ($img['img_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
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

<!-- Justified rows — laid out by OUR ss-engine-rows.js (no third-party lib). Tiles
     load the aspect THUMBNAIL (a_), not the full image, which is what kills the
     downscale "sparkle" the old full-image grid produced. Shape comes from
     data-w/data-h so lazy-load never has to read naturalWidth. -->
<div id="justified-grid" class="ss-scroll-wall" style="--ss-gap: <?php echo $gap; ?>px; <?php echo $_fsog_initial_thumbs ? 'display:none;' : ''; ?>">
    <?php if (!empty($images)): ?>
        <?php foreach ($images as $img):
            $link = BASE_URL . htmlspecialchars($img['img_slug']);
            if (!empty($img['img_thumb_aspect'])) {
                $thumb_url = BASE_URL . ltrim($img['img_thumb_aspect'], '/');
            } else {
                $full_img_path = ltrim($img['img_file'] ?? '', '/');
                $filename = basename($full_img_path);
                $folder   = str_replace($filename, '', $full_img_path);
                $thumb_url = BASE_URL . $folder . 'thumbs/a_' . $filename;
            }
            $iw = (int)($img['img_width'] ?? 0); $ih = (int)($img['img_height'] ?? 0);
            if ($iw <= 0 || $ih <= 0) { $iw = 3; $ih = 2; }
            $title = $img['img_title'] ?? '';
            $alt   = ($img['img_alt'] ?? '') !== '' ? $img['img_alt'] : $title;
        ?>
            <a href="<?php echo $link; ?>" class="ss-masonry-item" title="<?php echo htmlspecialchars($title); ?>">
                <img src="<?php echo $thumb_url; ?>" data-w="<?php echo $iw; ?>" data-h="<?php echo $ih; ?>"
                     alt="<?php echo htmlspecialchars($alt, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy">
            </a>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-sector-msg">
            <p>No photographs found.</p>
        </div>
    <?php endif; ?>
</div>

<?php /* Archive grid thumbs/masonry switch moved to
   assets/js/ss-engine-archive-grid-switch.js (loaded via the skin manifest).
   #browse-grid / #justified-grid above are its hooks — no inline JS in skins. */ ?>
<?php // ===== SNAPSMACK EOF =====