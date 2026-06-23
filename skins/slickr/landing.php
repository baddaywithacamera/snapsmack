<?php
/**
 * SNAPSMACK - Slickr Landing Page (Flickr profile idiom)
 *
 * Replicates the Flickr profile front page:
 *   - full-width cover/background image (admin-selectable; falls back to the
 *     most recent landscape photo)
 *   - circular avatar overlapping the cover (reuses the skin_avatar setting,
 *     same source as The Grid)
 *   - profile info row: name, tagline, photo count, location, "joined"
 *   - Flickr-style tab nav (Photostream / Albums / Archive / static pages)
 *   - justified masonry photostream (same row-packing math as archive-layout.php)
 * No black global nav bar — the cover sits flush at the top.
 *
 * Variables from index.php: $pdo, $settings, $active_skin, $site_name, BASE_URL
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$now_local = date('Y-m-d H:i:s');

// ── Static pages for the tab nav ──────────────────────────────────────────
try {
    $nav_pages = $pdo->query(
        "SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $nav_pages = [];
}

// ── Profile fields (site-wide settings) ───────────────────────────────────
$site_display = $settings['site_name']        ?? 'SnapSmack';
// Decode any stored HTML entities first (Flickr-imported / pasted values can
// arrive pre-encoded, e.g. "it&#039;s"); the output layer re-escapes once, so
// this is idempotent and kills the double-encoded "&#039;" rendering.
$tagline      = trim(html_entity_decode($settings['site_tagline'] ?? '', ENT_QUOTES, 'UTF-8'));
$bio          = trim(html_entity_decode($settings['site_description'] ?? '', ENT_QUOTES, 'UTF-8'));
$location     = trim($settings['slickr_location']    ?? '');  // optional, set in skin settings
$established  = trim($settings['slickr_established']  ?? '');  // optional, e.g. "2011"

// ── Photo count ───────────────────────────────────────────────────────────
$count_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM snap_images WHERE img_status = 'published' AND img_date <= ?"
);
$count_stmt->execute([$now_local]);
$photo_count = (int)$count_stmt->fetchColumn();

// ── Avatar (same source as The Grid: snap_settings.skin_avatar) ───────────
$avatar_path     = $settings['skin_avatar'] ?? '';
$avatar_exists   = $avatar_path && file_exists(dirname(__DIR__, 2) . '/' . $avatar_path);
$avatar_url      = $avatar_exists ? BASE_URL . htmlspecialchars($avatar_path) : '';
$avatar_initials = strtoupper(substr($site_display, 0, 1));

// ── Cover image: explicit setting → fallback to newest landscape photo ────
$cover_url = '';
$cover_id  = (int)($settings['slickr_cover_image_id'] ?? 0);
if ($cover_id > 0) {
    $cs = $pdo->prepare("SELECT img_file FROM snap_images WHERE id = ? AND img_status = 'published'");
    $cs->execute([$cover_id]);
    $cf = $cs->fetchColumn();
    if ($cf) $cover_url = BASE_URL . ltrim($cf, '/');
}
if ($cover_url === '') {
    $cs = $pdo->prepare(
        "SELECT img_file FROM snap_images
         WHERE img_status = 'published' AND img_width > img_height AND img_date <= ?
         ORDER BY img_date DESC LIMIT 1"
    );
    $cs->execute([$now_local]);
    $cf = $cs->fetchColumn();
    if ($cf) $cover_url = BASE_URL . ltrim($cf, '/');
}

// ── Photostream images for the justified grid ─────────────────────────────
$grid_stmt = $pdo->prepare(
    "SELECT id, img_title, img_slug, img_file, img_thumb_aspect, img_width, img_height
     FROM snap_images
     WHERE img_status = 'published' AND img_date <= ?
     ORDER BY img_date DESC, id DESC"
);
$grid_stmt->execute([$now_local]);
$images = $grid_stmt->fetchAll();

include dirname(__DIR__, 2) . '/core/meta.php';
?>
<div class="sl-landing">

    <!-- ── Cover / background image ──────────────────────────────────────── -->
    <div class="sl-cover"<?php if ($cover_url): ?> style="background-image:url('<?php echo $cover_url; ?>');"<?php endif; ?>>
        <div class="sl-cover-scrim" aria-hidden="true"></div>
    </div>

    <!-- ── Profile header ────────────────────────────────────────────────── -->
    <section class="sl-profile">
        <div class="sl-profile-inner">
            <div class="sl-profile-avatar">
                <?php if ($avatar_exists): ?>
                    <img src="<?php echo $avatar_url; ?>" alt="<?php echo htmlspecialchars($site_display); ?>">
                <?php else: ?>
                    <span class="sl-profile-avatar-initials"><?php echo htmlspecialchars($avatar_initials); ?></span>
                <?php endif; ?>
            </div>

            <div class="sl-profile-info">
                <h1 class="sl-profile-name"><?php echo htmlspecialchars($site_display); ?></h1>
                <?php if ($tagline): ?>
                    <p class="sl-profile-tagline"><?php echo htmlspecialchars($tagline); ?></p>
                <?php endif; ?>
                <?php if ($bio): ?>
                    <p class="sl-profile-bio"><?php echo nl2br(htmlspecialchars($bio)); ?></p>
                <?php endif; ?>
            </div>

            <div class="sl-profile-stats">
                <div class="sl-stat">
                    <strong><?php echo number_format($photo_count); ?></strong>
                    <span>Photo<?php echo $photo_count !== 1 ? 's' : ''; ?></span>
                </div>
                <?php if ($location): ?>
                    <div class="sl-stat-line"><?php echo htmlspecialchars($location); ?></div>
                <?php endif; ?>
                <?php if ($established): ?>
                    <div class="sl-stat-line">Joined <?php echo htmlspecialchars($established); ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Tab / utility bar ─────────────────────────────────────────── -->
        <nav class="sl-profile-tabs">
            <div class="sl-tabs-left">
                <a href="<?php echo BASE_URL; ?>" class="sl-tab sl-tab--active">Photostream</a>
                <a href="<?php echo BASE_URL; ?>albums.php" class="sl-tab">Albums</a>
                <a href="<?php echo BASE_URL; ?>collections.php" class="sl-tab">Collections</a>
                <?php foreach ($nav_pages as $np): ?>
                    <a href="<?php echo BASE_URL . htmlspecialchars($np['slug']); ?>" class="sl-tab"><?php echo htmlspecialchars($np['title']); ?></a>
                <?php endforeach; ?>
            </div>
            <div class="sl-tabs-right">
                <form class="sl-search" action="<?php echo BASE_URL; ?>archive.php" method="get" role="search">
                    <input type="search" name="search" class="sl-search-input" placeholder="Search photos" aria-label="Search photos">
                </form>
                <button type="button" class="sl-cal-toggle" id="sl-cal-toggle" aria-label="Calendar filter" title="Calendar filter">
                    <span class="sl-cal-c">C</span>
                </button>
            </div>
        </nav>
    </section>

    <!-- ── Justified photostream ─────────────────────────────────────────── -->
    <main class="sl-landing-stream">
        <?php
        $target_row_h = (int)($settings['justified_row_height'] ?? 240);
        $gap          = 4;
        $ref_w        = (int)($settings['main_canvas_width'] ?? 1400);

        // Pack images into rows (same algorithm as archive-layout.php).
        $rows = [];
        $current_row = [];
        $current_row_width = 0;
        foreach ($images as $img) {
            $iw = (int)($img['img_width']  ?? 400);
            $ih = (int)($img['img_height'] ?? 400);
            if ($iw <= 0) $iw = 400;
            if ($ih <= 0) $ih = 400;
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

        // Aspect-ratio sum of the last full row, so the partial last row matches.
        $last_full_ar_sum = 0;
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            if ($rows[$i]['full']) {
                foreach ($rows[$i]['images'] as $_img) $last_full_ar_sum += $_img['_aspect'];
                break;
            }
        }
        if ($last_full_ar_sum <= 0) $last_full_ar_sum = $ref_w / $target_row_h;
        ?>
        <div id="justified-grid" style="--justified-gap: <?php echo $gap; ?>px; --justified-row-height: <?php echo $target_row_h; ?>px; --last-row-ar-sum: <?php echo round($last_full_ar_sum, 4); ?>;">
            <?php if (!empty($images)): ?>
                <?php foreach ($rows as $row_data):
                    $row = $row_data['images'];
                    $row_class = 'justified-row' . (!$row_data['full'] ? ' justified-row-last' : '');
                ?>
                    <div class="<?php echo $row_class; ?>">
                        <?php foreach ($row as $img):
                            $link      = BASE_URL . htmlspecialchars($img['img_slug']);
                            // Use the aspect thumbnail (same asset archive-layout.php serves),
                            // NOT the full-resolution original — loading 9k+ full images froze
                            // the photostream. Fall back to the original only if no thumb exists.
                            $thumb_rel = trim((string)($img['img_thumb_aspect'] ?? ''));
                            $img_url   = BASE_URL . ltrim($thumb_rel !== '' ? $thumb_rel : ($img['img_file'] ?? ''), '/');
                            $flex_grow = round($img['_aspect'] * 100);
                        ?>
                            <a href="<?php echo $link; ?>" class="justified-item" title="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>" style="flex-grow: <?php echo $flex_grow; ?>; flex-basis: 0; aspect-ratio: <?php echo round($img['_aspect'], 4); ?>;">
                                <img src="<?php echo $img_url; ?>" alt="<?php echo htmlspecialchars($img['img_title'] ?? ''); ?>" loading="lazy">
                                <span class="justified-item-overlay">
                                    <span class="justified-item-title"><?php echo htmlspecialchars($img['img_title'] ?? ''); ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="sl-no-photos">
                    <p>No photographs yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

</div><!-- /.sl-landing -->

<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
