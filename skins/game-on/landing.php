<?php
/**
 * SNAPSMACK - Game On Landing Page
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
$show_profile    = ($settings['go_profile_header']     ?? '1') === '1';
$show_tagline    = ($settings['go_show_tagline']       ?? '1') === '1';
$carousel_ind    = $settings['go_carousel_indicator']  ?? 'icon';
$hover_overlay   = $settings['go_hover_overlay']       ?? 'title';
$customize_level = $settings['go_customize_level']     ?? 'per_grid';

// ── Frame style resolver ───────────────────────────────────────────────────
$_go_shadow_map = [
    '0' => 'none',
    '1' => '3px 3px 8px rgba(0,0,0,.20)',
    '2' => '6px 6px 18px rgba(0,0,0,.40)',
    '3' => '12px 12px 32px rgba(0,0,0,.60)',
];

$go_resolve_tile_frame = function ($cover_pi_row, $post_row) use ($settings, $customize_level, $_go_shadow_map) {
    // Honour explicit per-image styling REGARDLESS of the site customise level —
    // mirrors layout.php's post-view logic. Without this, a per-image 85% / border
    // / matte set in the gram composer was ignored on the grid tile unless the
    // whole site happened to be on the 'per_image' level, so the slider silently
    // did nothing (this is the "doesn't work as specced" bug). Square-crop 'fill'
    // stores size=100, so it never trips this and stays a normal cover crop.
    $pi_sz  = (int)($cover_pi_row['img_size_pct']  ?? 100);
    $pi_bpx = (int)($cover_pi_row['img_border_px'] ?? 0);
    $pi_sh  = (string)($cover_pi_row['img_shadow'] ?? '0');
    $has_per_image = ($pi_sz < 100 || $pi_bpx > 0 || (int)$pi_sh > 0);

    if ($customize_level === 'per_image' || $has_per_image) {
        $sz  = $pi_sz;
        $bpx = $pi_bpx;
        $bc  = $cover_pi_row['img_border_color'] ?? '#000000';
        $bg  = $cover_pi_row['img_bg_color']     ?? '#ffffff';
        $sh  = $pi_sh;
    } elseif ($customize_level === 'per_carousel') {
        $sz  = (int)($post_row['post_img_size_pct']  ?? 100);
        $bpx = (int)($post_row['post_border_px']     ?? 0);
        $bc  = $post_row['post_border_color'] ?? '#000000';
        $bg  = $post_row['post_bg_color']     ?? '#ffffff';
        $sh  = (string)($post_row['post_shadow']     ?? '0');
    } else { // per_grid
        $sz  = (int)($settings['go_frame_size_pct']     ?? 100);
        $bpx = (int)($settings['go_frame_border_px']    ?? 0);
        $bc  = $settings['go_frame_border_color'] ?? '#000000';
        $bg  = $settings['go_frame_bg_color']     ?? '#ffffff';
        $sh  = (string)($settings['go_frame_shadow']    ?? '0');
    }
    return [
        'size_pct'    => $sz,
        'border_px'   => $bpx,
        'border_color'=> $bc,
        'bg_color'    => $bg,
        'shadow_css'  => $_go_shadow_map[$sh] ?? 'none',
        'is_framed'   => ($sz < 100 || $bpx > 0 || (int)$sh > 0),
    ];
};

// ── Post count (for profile header) ──────────────────────────────────────
$count_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM snap_posts WHERE status = 'published' AND created_at <= ?"
);
$count_stmt->execute([$now_local]);
$post_count = (int)$count_stmt->fetchColumn();

// ── Fetch all published posts with cover image + trigram info ─────────────
// No LIMIT — all posts, browser lazy-loading handles performance.
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
        pi.img_focus_x,
        pi.img_focus_y,
        pi.img_zoom,
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

include dirname(__DIR__, 2) . '/core/meta.php';

// GAME ON's living background is deliberately fed from the rows already
// fetched for the public grid: no second archive query and no full-resolution
// background downloads. Render the maximum 16-by-9 desktop field; the engine
// hides surplus boards when the visitor selects a lower density.
$_go_puzzle_mode = $settings['go_puzzle_mode'] ?? 'moving';
$_go_puzzle_pool = [];
if ($_go_puzzle_mode !== 'off' && !empty($grid_posts)) {
    foreach ($grid_posts as $_go_candidate) {
        if (empty($_go_candidate['img_thumb_square'])) continue;
        $_go_puzzle_pool[] = $_go_candidate;
    }
    shuffle($_go_puzzle_pool);
}
$_go_puzzle_slots = [];
if (!empty($_go_puzzle_pool)) {
    // A per-request rotation keeps the deep archive alive without changing the
    // canonical feed order. Adjacent duplicate avoidance falls out naturally
    // until the inventory contains fewer than two usable images.
    for ($i = 0; $i < 144; $i++) {
        $_go_puzzle_slots[] = $_go_puzzle_pool[$i % count($_go_puzzle_pool)];
    }
}

$_go_asset_url = static function (string $path): string {
    if (preg_match('~^https?://~i', $path)) return $path;
    return BASE_URL . ltrim($path, '/');
};
?>
<?php if (!empty($_go_puzzle_slots)): ?>
<div class="go-puzzle-field"
     data-game-on
     data-mode="<?php echo htmlspecialchars($_go_puzzle_mode); ?>"
     data-palette="<?php echo htmlspecialchars($settings['go_border_palette'] ?? 'automatic'); ?>"
     data-direction="<?php echo htmlspecialchars($settings['go_border_direction'] ?? 'automatic'); ?>"
     data-puzzle-density="<?php echo (int)($settings['go_puzzle_density'] ?? 100); ?>"
     data-activity="<?php echo (int)($settings['go_motion_activity'] ?? 3); ?>"
     data-speed="<?php echo (int)($settings['go_motion_speed'] ?? 3); ?>"
     aria-hidden="true">
    <?php foreach ($_go_puzzle_slots as $_go_index => $_go_image):
        $_go_title = trim((string)($_go_image['title'] ?? '')) ?: 'Photograph';
        $_go_thumb = $_go_asset_url((string)$_go_image['img_thumb_square']);
        $_go_full  = $_go_asset_url((string)$_go_image['img_file']);
        $_go_url   = BASE_URL . '?s=' . urlencode((string)$_go_image['img_slug']);
    ?>
    <button class="go-puzzle" type="button"
            tabindex="-1"
            data-puzzle-index="<?php echo (int)$_go_index; ?>"
            data-thumb="<?php echo htmlspecialchars($_go_thumb); ?>"
            data-full="<?php echo htmlspecialchars($_go_full); ?>"
            data-post-url="<?php echo htmlspecialchars($_go_url); ?>"
            data-label="<?php echo htmlspecialchars($_go_title); ?>"
            data-focus-x="<?php echo (int)($_go_image['img_focus_x'] ?? 50); ?>"
            data-focus-y="<?php echo (int)($_go_image['img_focus_y'] ?? 50); ?>"
            data-zoom="<?php echo (int)($_go_image['img_zoom'] ?? 100); ?>"></button>
    <?php endforeach; ?>
</div>
<div class="go-puzzle-edge-mask" aria-hidden="true"></div>
<?php endif; ?>
<div class="go-content-wrap">

<?php include __DIR__ . '/skin-profile.php'; ?>

<!-- ── 3-Column Grid ───────────────────────────────────────────────────── -->
<main>
    <div class="go-grid">
        <?php
        // Slot labels for horizontal and vertical orientations.
        $slot_class_h = [1 => 'go-tile--trigram-L', 2 => 'go-tile--trigram-M', 3 => 'go-tile--trigram-R'];
        $slot_class_v = [1 => 'go-tile--trigram-T', 2 => 'go-tile--trigram-M', 3 => 'go-tile--trigram-B'];

        $col = 0; // track current column position (0, 1, 2)

        foreach ($grid_posts as $post):
            $go_slot   = (int)($post['trigram_slot'] ?? 0);
            $go_orient = $post['trigram_orientation'] ?? 'h';
            $go_id     = (int)($post['trigram_id'] ?? 0);

            // ── Phantom padding ──────────────────────────────────────────
            // When the L post (slot 1) of a horizontal trigram falls off the
            // start of a row, emit invisible phantom tiles to complete the
            // current row first.
            if ($go_slot === 1 && $go_orient !== 'v' && $col !== 0):
                $phantoms = 3 - $col;
                for ($ph = 0; $ph < $phantoms; $ph++):
        ?>
        <div class="go-tile go-tile--phantom" aria-hidden="true"></div>
        <?php
                    $col = ($col + 1) % 3;
                endfor;
            endif;

            $thumb_src   = $post['img_thumb_square'] ?: $post['img_file'];
            $is_slice_tile = false; // true only when a physical slice file fronts this tile

            // Trigram cover: when the post belongs to a trigram, the grid tile
            // shows the panorama slice (trigrams/trigram-{id}-{L|M|R}.jpg), not the
            // post's own cover. The carousel/post view is untouched.
            if ($go_id > 0 && $go_slot > 0) {
                $go_label = ($go_orient === 'v')
                    ? (['T','M','B'][$go_slot - 1] ?? '')
                    : (['L','M','R'][$go_slot - 1] ?? '');
                if ($go_label !== '') {
                    $go_rel = 'trigrams/trigram-' . $go_id . '-' . $go_label . '.jpg';
                    if (is_file(dirname(__DIR__, 2) . '/' . $go_rel)) {
                        $thumb_src = $go_rel;
                        $is_slice_tile = true;
                    }
                }
            }

            $post_url    = BASE_URL . '?s=' . urlencode($post['img_slug']);
            $image_count = (int)$post['image_count'];
            $is_carousel = $image_count > 1;
            $title_safe  = htmlspecialchars($post['title']);

            // ── Tile class ───────────────────────────────────────────────
            $tile_frame = $go_resolve_tile_frame($post, $post);
            // Per spec, a fit-mode cover (size<100 / border / matte) is matted on
            // the tile, NOT cropped. The frame gate rides on SLICE-FILE EXISTENCE,
            // not trigram membership (Sean's taxonomy, 2026-07-02): slice trigrams
            // and carousel trigrams have physical slice files fronting the tiles —
            // always full-bleed, never framed. A TRIPTYCH (three distinct posts
            // locked as a row, no slice files) keeps per-image frames and
            // natural-aspect presentation, matching its fediverse bake. A framed
            // tile must use the ASPECT thumbnail, otherwise we'd be matting an
            // already-square-cropped image.
            $do_frame = ($tile_frame['is_framed'] && !$is_slice_tile);
            if ($do_frame) {
                $thumb_src = $post['img_thumb_aspect'] ?: ($post['img_thumb_square'] ?: $post['img_file']);
            }
            $tile_class = 'go-tile';

            if ($go_id > 0 && $go_slot > 0) {
                $sc = ($go_orient === 'v') ? ($slot_class_v[$go_slot] ?? '') : ($slot_class_h[$go_slot] ?? '');
                if ($sc) $tile_class .= ' ' . $sc;
                $tile_class .= ' go-tile--trigram';
            }

            if ($do_frame) {
                $tile_class .= ' go-tile--framed';
                // Orientation decides which axis the size% applies to: landscape
                // fills width, portrait fills height (so it never overflows the
                // square tile). The border hugs the actual image either way.
                if ((int)$post['img_height'] > (int)$post['img_width']) {
                    $tile_class .= ' go-tile--portrait';
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
             data-trigram-id="<?php echo $go_id; ?>"
             data-trigram-slot="<?php echo $go_slot; ?>"
             <?php if ($tile_css_vars): ?>style="<?php echo $tile_css_vars; ?>"<?php endif; ?>>
            <a href="<?php echo $post_url; ?>" title="<?php echo $title_safe; ?>">
                <img src="<?php echo htmlspecialchars($thumb_src); ?>"
                     alt="<?php echo $title_safe; ?>"
                     loading="lazy">
            </a>

            <?php if ($is_carousel && $carousel_ind !== 'none'): ?>
                <div class="go-tile-indicator">
                    <?php if ($carousel_ind === 'icon'): ?>
                        <span class="go-tile-indicator--icon" aria-label="<?php echo $image_count; ?> images">⧉</span>
                    <?php else: ?>
                        <span class="go-tile-indicator--count"><?php echo $image_count; ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($hover_overlay === 'title' || $hover_overlay === 'count'): ?>
                <div class="go-tile-overlay" aria-hidden="true">
                    <span class="go-tile-overlay-text">
                        <?php if ($hover_overlay === 'title'): ?>
                            <?php echo $title_safe; ?>
                        <?php else: ?>
                            <?php echo $image_count; ?> image<?php echo $image_count !== 1 ? 's' : ''; ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php elseif ($hover_overlay === 'dark'): ?>
                <div class="go-tile-overlay go-tile-overlay--dark" aria-hidden="true"></div>
            <?php endif; ?>
        </div>
        <?php
            $col = ($col + 1) % 3;
        endforeach; ?>

        <?php if (empty($grid_posts)): ?>
        <div style="grid-column: 1/-1; padding: 60px 20px; text-align: center; color: var(--text-secondary);">
            <p>No posts yet. Start by uploading your first photograph.</p>
        </div>
        <?php endif; ?>
    </div>
</main>

</div><!-- /.go-content-wrap -->

<?php /* Post modal overlay is now rendered once by skin-footer.php (shared by all
         Grid pages) so go-modal.js finds its container on every page, not just
         the landing page. Do not re-add a per-page copy here. */ ?>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
