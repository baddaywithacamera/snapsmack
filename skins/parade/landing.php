<?php
/**
 * SNAPSMACK - PARADE Landing Page
 *
 * Classic 3-column photo grid with optional profile header.
 * All published posts are fetched in one query (no pagination) with browser
 * lazy-loading for performance.  Trigram posts are rendered with slot classes
 * and phantom padding to ensure row alignment.
 *
 * Variables from index.php: $pdo, $settings, $active_skin, $site_name
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$now_local = date('Y-m-d H:i:s');

// ── Static pages for nav ───────────────────────────────────────────────────
try {
    $nav_pages_stmt = $pdo->query("SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC");
    $nav_pages = $nav_pages_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $nav_pages = [];
}

// Read skin settings
$show_profile    = ($settings['pa_profile_header']     ?? '1') === '1';
$show_tagline    = ($settings['pa_show_tagline']       ?? '1') === '1';
$carousel_ind    = $settings['pa_carousel_indicator']  ?? 'icon';
$hover_overlay   = $settings['pa_hover_overlay']       ?? 'title';
$customize_level = $settings['pa_customize_level']     ?? 'per_grid';

// ── Frame style resolver ───────────────────────────────────────────────────
$_pa_shadow_map = [
    '0' => 'none',
    '1' => '3px 3px 8px rgba(0,0,0,.20)',
    '2' => '6px 6px 18px rgba(0,0,0,.40)',
    '3' => '12px 12px 32px rgba(0,0,0,.60)',
];

$pa_resolve_tile_frame = function ($cover_pi_row, $post_row) use ($settings, $customize_level, $_pa_shadow_map) {
    switch ($customize_level) {
        case 'per_image':
            $sz  = (int)($cover_pi_row['img_size_pct']     ?? 100);
            $bpx = (int)($cover_pi_row['img_border_px']    ?? 0);
            $bc  = $cover_pi_row['img_border_color'] ?? '#000000';
            $bg  = $cover_pi_row['img_bg_color']     ?? '#ffffff';
            $sh  = (string)($cover_pi_row['img_shadow']    ?? '0');
            break;
        case 'per_carousel':
            $sz  = (int)($post_row['post_img_size_pct']  ?? 100);
            $bpx = (int)($post_row['post_border_px']     ?? 0);
            $bc  = $post_row['post_border_color'] ?? '#000000';
            $bg  = $post_row['post_bg_color']     ?? '#ffffff';
            $sh  = (string)($post_row['post_shadow']     ?? '0');
            break;
        default: // per_grid
            $sz  = (int)($settings['pa_frame_size_pct']     ?? 100);
            $bpx = (int)($settings['pa_frame_border_px']    ?? 0);
            $bc  = $settings['pa_frame_border_color'] ?? '#000000';
            $bg  = $settings['pa_frame_bg_color']     ?? '#ffffff';
            $sh  = (string)($settings['pa_frame_shadow']    ?? '0');
    }
    return [
        'size_pct'    => $sz,
        'border_px'   => $bpx,
        'border_color'=> $bc,
        'bg_color'    => $bg,
        'shadow_css'  => $_pa_shadow_map[$sh] ?? 'none',
        'is_framed'   => ($sz < 100 || $bpx > 0 || (int)$sh > 0),
    ];
};

// ── Post count (for profile header) ──────────────────────────────────────
$count_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM snap_posts WHERE status = 'published' AND created_at <= ?"
);
$count_stmt->execute([$now_local]);
$post_count = (int)$count_stmt->fetchColumn();

// ── Fetch all published posts ─────────────────────────────────────────────
// All posts go into the DOM; images use loading="lazy" so the browser only
// fetches them as they approach the viewport (same pattern as archive.php).
$grid_stmt = $pdo->prepare("
    SELECT
        p.id          AS post_id,
        p.title,
        p.slug        AS post_slug,
        p.post_type,
        p.trigram_id,
        p.created_at,
        p.sort_order,
        p.post_img_size_pct,
        p.post_border_px,
        p.post_border_color,
        p.post_bg_color,
        p.post_shadow,
        i.id          AS img_id,
        i.img_file,
        i.img_thumb_square,
        i.img_thumb_aspect,
        i.img_width,
        i.img_height,
        i.img_slug,
        pi.img_size_pct,
        pi.img_border_px,
        pi.img_border_color,
        pi.img_bg_color,
        pi.img_shadow,
        (SELECT COUNT(*)
         FROM snap_post_images spi
         WHERE spi.post_id = p.id
           AND spi.sort_position >= 0)  AS image_count,
        CASE
            WHEN tg.post_id_1 = p.id THEN 1
            WHEN tg.post_id_2 = p.id THEN 2
            WHEN tg.post_id_3 = p.id THEN 3
            ELSE NULL
        END AS trigram_slot,
        tg.orientation AS trigram_orientation
    FROM snap_posts p
    JOIN snap_post_images pi ON pi.post_id = p.id AND pi.is_cover = 1
    JOIN snap_images i       ON i.id = pi.image_id
    LEFT JOIN snap_trigrams tg ON tg.id = p.trigram_id
    WHERE p.status = 'published'
      AND p.created_at <= ?
    ORDER BY CASE WHEN p.sort_order > 0 THEN 1 ELSE 0 END ASC,
             p.sort_order ASC,
             p.id DESC
");
$grid_stmt->execute([$now_local]);
$grid_posts = $grid_stmt->fetchAll();

// Backfill horizontal-trigram rows so the feed never shows blank gaps (singles
// slide up to finish the row before a trigram). Phantom padding stays as the
// tail-case safety net. Shared helper — see core/trigram.php.
require_once dirname(__DIR__, 2) . '/core/trigram.php';
if (function_exists('trigram_align_backfill')) $grid_posts = trigram_align_backfill($grid_posts);

// ── GAME ON field behind the flag (optional) ───────────────────────────────
// Same markup GAME ON's landing emits; the shared engine + stylesheet do the
// rest. Pool rules copied from GAME ON: one-image posts are eligible, a
// carousel's cover is not (its other images are), trigram members never.
$_pa_puzzles = $settings['pa_puzzles'] ?? 'off';
$_pa_go_pool = [];
if ($_pa_puzzles !== 'off') {
    $_pa_go_stmt = $pdo->prepare("
        SELECT p.title, i.img_file, i.img_thumb_square,
               i.img_slug, pi.img_focus_x, pi.img_focus_y, pi.img_zoom,
               COALESCE(ci.img_slug, i.img_slug) AS post_img_slug
          FROM snap_posts p
          JOIN snap_post_images pi ON pi.post_id = p.id AND pi.sort_position >= 0
          JOIN snap_images i ON i.id = pi.image_id
          LEFT JOIN snap_post_images cpi ON cpi.post_id = p.id AND cpi.is_cover = 1
          LEFT JOIN snap_images ci ON ci.id = cpi.image_id
         WHERE p.status = 'published'
           AND p.created_at <= ?
           AND p.trigram_id IS NULL
           AND i.img_thumb_square IS NOT NULL
           AND i.img_thumb_square <> ''
           AND NOT (
               pi.is_cover = 1
               AND (SELECT COUNT(*) FROM snap_post_images spi
                     WHERE spi.post_id = p.id AND spi.sort_position >= 0) > 1
           )
         ORDER BY i.id DESC
    ");
    $_pa_go_stmt->execute([$now_local]);
    $_pa_go_pool = $_pa_go_stmt->fetchAll(PDO::FETCH_ASSOC);
    shuffle($_pa_go_pool);
}
$_pa_go_slots = [];
if (!empty($_pa_go_pool)) {
    for ($i = 0; $i < 144; $i++) {
        $_pa_go_slots[] = $_pa_go_pool[$i % count($_pa_go_pool)];
    }
}
$_pa_go_url = static function (string $path): string {
    if (preg_match('~^https?://~i', $path)) return $path;
    return BASE_URL . ltrim($path, '/');
};
?>
<?php if (!empty($_pa_go_slots)): ?>
<div class="go-puzzle-field pa-puzzle-field"
     data-game-on
     data-mode="<?php echo htmlspecialchars($_pa_puzzles); ?>"
     data-palette="automatic"
     data-direction="automatic"
     data-puzzle-density="<?php echo (int)($settings['pa_go_density'] ?? 100); ?>"
     data-activity="<?php echo (int)($settings['pa_go_activity'] ?? 3); ?>"
     data-speed="<?php echo (int)($settings['pa_go_speed'] ?? 3); ?>"
     data-border-activity="0"
     data-modal-theme="<?php echo htmlspecialchars($settings['pa_go_modal_theme'] ?? 'light'); ?>"
     data-score-url="<?php echo htmlspecialchars(BASE_URL . 'game-on-scores.php'); ?>"
     data-help-url="<?php echo htmlspecialchars(BASE_URL . 'page.php?slug=how-to-play-15-puzzle'); ?>"
     aria-hidden="true">
    <?php foreach ($_pa_go_slots as $_go_index => $_go_image):
        $_go_title = trim((string)($_go_image['title'] ?? '')) ?: 'Photograph';
    ?>
    <button class="go-puzzle" type="button"
            tabindex="-1"
            data-puzzle-index="<?php echo (int)$_go_index; ?>"
            data-thumb="<?php echo htmlspecialchars($_pa_go_url((string)$_go_image['img_thumb_square'])); ?>"
            data-full="<?php echo htmlspecialchars($_pa_go_url((string)$_go_image['img_file'])); ?>"
            data-post-url="<?php echo htmlspecialchars(BASE_URL . '?s=' . urlencode((string)$_go_image['post_img_slug'])); ?>"
            data-label="<?php echo htmlspecialchars($_go_title); ?>"
            data-focus-x="<?php echo (int)($_go_image['img_focus_x'] ?? 50); ?>"
            data-focus-y="<?php echo (int)($_go_image['img_focus_y'] ?? 50); ?>"
            data-zoom="<?php echo (int)($_go_image['img_zoom'] ?? 100); ?>"></button>
    <?php endforeach; ?>
</div>
<div id="go-puzzle-candidates" hidden aria-hidden="true">
<?php foreach (array_slice($_pa_go_pool, 0, 288) as $_go_candidate): ?>
    <i data-thumb="<?php echo htmlspecialchars($_pa_go_url((string)$_go_candidate['img_thumb_square'])); ?>"
       data-full="<?php echo htmlspecialchars($_pa_go_url((string)$_go_candidate['img_file'])); ?>"
       data-post-url="<?php echo htmlspecialchars(BASE_URL . '?s=' . urlencode((string)$_go_candidate['post_img_slug'])); ?>"
       data-label="<?php echo htmlspecialchars(trim((string)($_go_candidate['title'] ?? '')) ?: 'Photograph'); ?>"
       data-focus-x="<?php echo (int)($_go_candidate['img_focus_x'] ?? 50); ?>"
       data-focus-y="<?php echo (int)($_go_candidate['img_focus_y'] ?? 50); ?>"
       data-zoom="<?php echo (int)($_go_candidate['img_zoom'] ?? 100); ?>"></i>
<?php endforeach; ?>
</div>
<?php endif; ?>
<div class="pa-content-wrap landing-feed">

<?php include __DIR__ . '/skin-profile.php'; ?>

<!-- ── 3-Column Grid ───────────────────────────────────────────────────── -->
<main>
    <div class="pa-grid">
        <?php
        // Slot labels for horizontal and vertical orientations.
        $slot_class_h = [1 => 'pa-tile--trigram-L', 2 => 'pa-tile--trigram-M', 3 => 'pa-tile--trigram-R'];
        $slot_class_v = [1 => 'pa-tile--trigram-T', 2 => 'pa-tile--trigram-M', 3 => 'pa-tile--trigram-B'];

        $col = 0; // track current column position (0, 1, 2)
        $pa_idx = 0; // running cell index (incl. phantoms) → data-row/data-col for the wave

        foreach ($grid_posts as $post):
            $pa_slot   = (int)($post['trigram_slot'] ?? 0);
            $pa_orient = $post['trigram_orientation'] ?? 'h';
            $pa_id     = (int)($post['trigram_id'] ?? 0);

            // ── Phantom padding ──────────────────────────────────────────
            // When the L post (slot 1) of a horizontal trigram falls off the
            // start of a row, emit invisible phantom tiles to complete the
            // current row first.
            if ($pa_slot === 1 && $pa_orient !== 'v' && $col !== 0):
                $phantoms = 3 - $col;
                for ($ph = 0; $ph < $phantoms; $ph++):
        ?>
        <div class="pa-tile pa-tile--phantom" aria-hidden="true"
             data-row="<?php echo intdiv($pa_idx, 3); ?>" data-col="<?php echo $pa_idx % 3; ?>"></div>
        <?php
                    $col = ($col + 1) % 3;
                    $pa_idx++;
                endfor;
            endif;

            $thumb_src   = $post['img_thumb_square'] ?: $post['img_file'];
            $is_slice_tile = false; // true only when a physical slice file fronts this tile

            // Trigram cover: grid tile shows the panorama slice when set.
            if ($pa_id > 0 && $pa_slot > 0) {
                $pa_label = ($pa_orient === 'v')
                    ? (['T','M','B'][$pa_slot - 1] ?? '')
                    : (['L','M','R'][$pa_slot - 1] ?? '');
                if ($pa_label !== '') {
                    $pa_rel = 'trigrams/trigram-' . $pa_id . '-' . $pa_label . '.jpg';
                    if (is_file(dirname(__DIR__, 2) . '/' . $pa_rel)) {
                        $thumb_src = $pa_rel;
                        $is_slice_tile = true;
                    }
                }
            }

            $post_url    = BASE_URL . '?s=' . urlencode($post['img_slug']);
            $image_count = (int)$post['image_count'];
            $is_carousel = $image_count > 1;
            $title_safe  = htmlspecialchars($post['title']);

            // ── Tile class ───────────────────────────────────────────────
            $tile_frame = $pa_resolve_tile_frame($post, $post);
            $tile_class = 'pa-tile';

            if ($pa_id > 0 && $pa_slot > 0) {
                $sc = ($pa_orient === 'v') ? ($slot_class_v[$pa_slot] ?? '') : ($slot_class_h[$pa_slot] ?? '');
                if ($sc) $tile_class .= ' ' . $sc;
                $tile_class .= ' pa-tile--trigram';
            }

            // Frame gate rides on SLICE-FILE EXISTENCE, not trigram membership:
            // slice-fronted tiles are always full-bleed; triptychs (no slice
            // files) keep their per-image frames — matching the fediverse bake.
            // A framed tile must use the ASPECT thumbnail (natural ratio),
            // otherwise we'd be matting an already-square-cropped image.
            $do_frame = ($tile_frame['is_framed'] && !$is_slice_tile);
            if ($do_frame) {
                $thumb_src = $post['img_thumb_aspect'] ?: ($post['img_thumb_square'] ?: $post['img_file']);
                $tile_class .= ' pa-tile--framed';
                if ((int)$post['img_height'] > (int)$post['img_width']) {
                    $tile_class .= ' pa-tile--portrait';
                }
            }

            $tile_css_vars = '';
            if ($do_frame) {
                $tile_css_vars = sprintf(
                    '--tile-bg:%s; --tile-img-size:%d%%; --tile-border-w:%dpx; --tile-border-c:%s; --tile-shadow:%s;',
                    htmlspecialchars($tile_frame['bg_color']),
                    $tile_frame['size_pct'],
                    $tile_frame['border_px'],
                    htmlspecialchars($tile_frame['border_color']),
                    htmlspecialchars($tile_frame['shadow_css'])
                );
            }
        ?>
        <div class="<?php echo $tile_class; ?>"
             data-trigram-id="<?php echo $pa_id; ?>"
             data-trigram-slot="<?php echo $pa_slot; ?>"
             data-row="<?php echo intdiv($pa_idx, 3); ?>" data-col="<?php echo $pa_idx % 3; ?>"
             <?php if ($tile_css_vars): ?>style="<?php echo $tile_css_vars; ?>"<?php endif; ?>>
            <div class="pa-ring" aria-hidden="true"></div>
            <a href="<?php echo $post_url; ?>" title="<?php echo $title_safe; ?>">
                <img src="<?php echo htmlspecialchars($thumb_src); ?>"
                     alt="<?php echo $title_safe; ?>"
                     loading="lazy">
            </a>

            <?php if ($is_carousel && $carousel_ind !== 'none'): ?>
                <div class="pa-tile-indicator">
                    <?php if ($carousel_ind === 'icon'): ?>
                        <span class="pa-tile-indicator--icon" aria-label="<?php echo $image_count; ?> images">⧉</span>
                    <?php else: ?>
                        <span class="pa-tile-indicator--count"><?php echo $image_count; ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($hover_overlay === 'title' || $hover_overlay === 'count'): ?>
                <div class="pa-tile-overlay" aria-hidden="true">
                    <span class="pa-tile-overlay-text">
                        <?php if ($hover_overlay === 'title'): ?>
                            <?php echo $title_safe; ?>
                        <?php else: ?>
                            <?php echo $image_count; ?> image<?php echo $image_count !== 1 ? 's' : ''; ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php elseif ($hover_overlay === 'dark'): ?>
                <div class="pa-tile-overlay pa-tile-overlay--dark" aria-hidden="true"></div>
            <?php endif; ?>
        </div>
        <?php
            $col = ($col + 1) % 3;
            $pa_idx++;
        endforeach; ?>

        <?php if (empty($grid_posts)): ?>
        <div style="grid-column: 1/-1; padding: 60px 20px; text-align: center; color: var(--text-secondary);">
            <p>No posts yet. Start by uploading your first photograph.</p>
        </div>
        <?php endif; ?>
    </div><!-- /.pa-grid -->

</main>

</div><!-- /.pa-content-wrap -->

<?php /* Post modal overlay is now rendered once by skin-footer.php (shared by all
         Grid pages) so pa-modal.js finds its container on every page, not just
         the landing page. Do not re-add a per-page copy here. */ ?>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
