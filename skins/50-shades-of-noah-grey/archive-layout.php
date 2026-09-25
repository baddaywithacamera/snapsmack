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
// Tile size and gap come from the manifest (--ss-cols / --ss-gap on #justified-grid
// in style.css) — nothing is set inline here, or it would override the controls.
// 1.6.0: the M layout is a stored-value select (rows / columns / square / mosaic).
// Same tiles, same controls; the container class picks which engine binds to it:
//   rows    → .ss-scroll-wall  (ss-engine-rows.js)
//   columns → .ss-masonry      (ss-engine-columns.js)
//   square  → .ss-square-wall  (pure CSS grid, style.css)
//   mosaic  → .ss-mosaic-wall  (ss-engine-mosaic.js — Codex's MOSAIC quilt)
// Copied from skins/scroll/wall.php, which has run all four in production.
$_fsog_m_layout = (string)($settings['masonry_layout'] ?? 'rows');
if (!in_array($_fsog_m_layout, ['rows', 'columns', 'square', 'mosaic'], true)) $_fsog_m_layout = 'rows';
$_fsog_is_mosaic = ($_fsog_m_layout === 'mosaic');
$_fsog_m_class  = $_fsog_is_mosaic ? 'ss-mosaic-wall'
                : ($_fsog_m_layout === 'columns' ? 'ss-masonry'
                : ($_fsog_m_layout === 'square' ? 'ss-square-wall' : 'ss-scroll-wall'));

// ── ASYMMETRIC (MOSAIC) ───────────────────────────────────────────────────────
// Same block markup, payload and engine as skins/scroll/wall.php, which took them
// unchanged from eatmeclaude.php. The one difference: an ARCHIVE is a finite,
// already-filtered set, so every block is rendered here and there is no feed
// sentinel — nothing to stream. Block sizes use the same 6-per-block rule so the
// quilt reads identically.
$_fsog_mosaic_blocks = [];
if ($_fsog_is_mosaic && !empty($images)) {
    if (!function_exists('fsog_mosaic_block_sizes')) {
    function fsog_mosaic_block_sizes(int $total): array {
        if ($total <= 0) return [];
        if ($total <= 6) return [$total];
        $full = intdiv($total, 6); $remainder = $total % 6;
        $sizes = array_fill(0, $full, 6);
        if ($remainder === 1) { $sizes[count($sizes) - 1] = 5; $sizes[] = 2; }
        elseif ($remainder > 0) { $sizes[] = $remainder; }
        return $sizes;
    }
    }
    $_fsog_payload = [];
    foreach ($images as $img) {
        $full = ltrim((string)($img['img_file'] ?? ''), '/');
        if ($full === '') continue;
        $thumb = ltrim((string)($img['img_thumb_aspect'] ?? ''), '/');
        if ($thumb === '') $thumb = $full;
        $title = trim((string)($img['img_title'] ?? ''));
        $alt   = trim((string)($img['img_alt'] ?? ''));
        $_fsog_payload[] = [
            'src'    => BASE_URL . $thumb,
            'full'   => BASE_URL . $full,
            'href'   => BASE_URL . ltrim((string)($img['img_slug'] ?? ''), '/'),
            'alt'    => $alt !== '' ? $alt : $title,
            'id'     => (int)($img['id'] ?? 0),
            'width'  => max(1, (int)($img['img_width'] ?? 1)),
            'height' => max(1, (int)($img['img_height'] ?? 1)),
            'focusX' => 50,
            'focusY' => 50,
            'lazy'   => true,
        ];
    }
    $_fsog_offset = 0;
    foreach (fsog_mosaic_block_sizes(count($_fsog_payload)) as $_sz) {
        $_fsog_mosaic_blocks[] = array_slice($_fsog_payload, $_fsog_offset, $_sz);
        $_fsog_offset += $_sz;
    }
}
$_fsog_mosaic_gap  = max(0, min(25, (int)($settings['masonry_gap'] ?? 4)));
$_fsog_mosaic_emph = (string)($settings['masonry_mosaic_emphasis'] ?? 'landscape');
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
        <div class="empty-sector-msg">
            <p>No photographs found.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Justified rows — laid out by OUR ss-engine-rows.js (no third-party lib). Tiles
     load the aspect THUMBNAIL (a_), not the full image, which is what kills the
     downscale "sparkle" the old full-image grid produced. Shape comes from
     data-w/data-h so lazy-load never has to read naturalWidth. -->
<div id="justified-grid" class="<?php echo $_fsog_m_class; ?>" data-masonry-layout="<?php echo $_fsog_m_layout; ?>"<?php echo $_fsog_initial_thumbs ? ' style="display:none;"' : ''; ?>>
    <?php if ($_fsog_is_mosaic): ?>
        <?php if ($_fsog_mosaic_blocks): ?>
            <?php foreach ($_fsog_mosaic_blocks as $_blk): ?>
            <div class="snap-mosaic fsog-mosaic-block"
                 data-gap="<?php echo $_fsog_mosaic_gap; ?>"
                 data-emphasis="<?php echo htmlspecialchars($_fsog_mosaic_emph, ENT_QUOTES); ?>"
                 data-mosaic="<?php echo htmlspecialchars(json_encode($_blk, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>"></div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-sector-msg">
                <p>No photographs found.</p>
            </div>
        <?php endif; ?>
    <?php elseif (!empty($images)): ?>
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

<?php if ($_fsog_is_mosaic): ?>
<!-- ASYMMETRIC: Codex's MOSAIC engine, loaded exactly as skins/scroll/wall.php and
     eatmeclaude.php load it. Rows/columns/square load nothing here — their engines
     come via the skin's require_scripts. No mosaic-feed engine: an archive is a
     finite set and every block is already on the page. -->
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-mosaic.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-mosaic.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>
<?php endif; ?>

<?php /* Archive grid thumbs/masonry switch moved to
   assets/js/ss-engine-archive-grid-switch.js (loaded via the skin manifest).
   #browse-grid / #justified-grid above are its hooks — no inline JS in skins. */ ?>
<?php // ===== SNAPSMACK EOF =====