<?php
/**
 * SNAPSMACK - The Grid Landing Page
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
$show_profile    = ($settings['tg_profile_header']     ?? '1') === '1';
$show_tagline    = ($settings['tg_show_tagline']       ?? '1') === '1';
$carousel_ind    = $settings['tg_carousel_indicator']  ?? 'icon';
$hover_overlay   = $settings['tg_hover_overlay']       ?? 'title';
$customize_level = $settings['tg_customize_level']     ?? 'per_grid';

// ── Frame style resolver ───────────────────────────────────────────────────
$_tg_shadow_map = [
    '0' => 'none',
    '1' => '3px 3px 8px rgba(0,0,0,.20)',
    '2' => '6px 6px 18px rgba(0,0,0,.40)',
    '3' => '12px 12px 32px rgba(0,0,0,.60)',
];

$tg_resolve_tile_frame = function ($cover_pi_row, $post_row) use ($settings, $customize_level, $_tg_shadow_map) {
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
        $sz  = (int)($settings['tg_frame_size_pct']     ?? 100);
        $bpx = (int)($settings['tg_frame_border_px']    ?? 0);
        $bc  = $settings['tg_frame_border_color'] ?? '#000000';
        $bg  = $settings['tg_frame_bg_color']     ?? '#ffffff';
        $sh  = (string)($settings['tg_frame_shadow']    ?? '0');
    }
    return [
        'size_pct'    => $sz,
        'border_px'   => $bpx,
        'border_color'=> $bc,
        'bg_color'    => $bg,
        'shadow_css'  => $_tg_shadow_map[$sh] ?? 'none',
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
             p.created_at DESC
");
$grid_stmt->execute([$now_local]);
$grid_posts = $grid_stmt->fetchAll();

include dirname(__DIR__, 2) . '/core/meta.php';
?>
<div class="tg-content-wrap">

<?php include __DIR__ . '/skin-profile.php'; ?>

<!-- ── 3-Column Grid ───────────────────────────────────────────────────── -->
<main>
    <div class="tg-grid">
        <?php
        // Slot labels for horizontal and vertical orientations.
        $slot_class_h = [1 => 'tg-tile--trigram-L', 2 => 'tg-tile--trigram-M', 3 => 'tg-tile--trigram-R'];
        $slot_class_v = [1 => 'tg-tile--trigram-T', 2 => 'tg-tile--trigram-M', 3 => 'tg-tile--trigram-B'];

        $col = 0; // track current column position (0, 1, 2)

        foreach ($grid_posts as $post):
            $tg_slot   = (int)($post['trigram_slot'] ?? 0);
            $tg_orient = $post['trigram_orientation'] ?? 'h';
            $tg_id     = (int)($post['trigram_id'] ?? 0);

            // ── Phantom padding ──────────────────────────────────────────
            // When the L post (slot 1) of a horizontal trigram falls off the
            // start of a row, emit invisible phantom tiles to complete the
            // current row first.
            if ($tg_slot === 1 && $tg_orient !== 'v' && $col !== 0):
                $phantoms = 3 - $col;
                for ($ph = 0; $ph < $phantoms; $ph++):
        ?>
        <div class="tg-tile tg-tile--phantom" aria-hidden="true"></div>
        <?php
                    $col = ($col + 1) % 3;
                endfor;
            endif;

            $thumb_src   = $post['img_thumb_square'] ?: $post['img_file'];

            // Trigram cover: when the post belongs to a trigram, the grid tile
            // shows the panorama slice (trigrams/trigram-{id}-{L|M|R}.jpg), not the
            // post's own cover. The carousel/post view is untouched.
            if ($tg_id > 0 && $tg_slot > 0) {
                $tg_label = ($tg_orient === 'v')
                    ? (['T','M','B'][$tg_slot - 1] ?? '')
                    : (['L','M','R'][$tg_slot - 1] ?? '');
                if ($tg_label !== '') {
                    $tg_rel = 'trigrams/trigram-' . $tg_id . '-' . $tg_label . '.jpg';
                    if (is_file(dirname(__DIR__, 2) . '/' . $tg_rel)) {
                        $thumb_src = $tg_rel;
                    }
                }
            }

            $post_url    = BASE_URL . '?s=' . urlencode($post['img_slug']);
            $image_count = (int)$post['image_count'];
            $is_carousel = $image_count > 1;
            $title_safe  = htmlspecialchars($post['title']);

            // ── Tile class ───────────────────────────────────────────────
            $tile_frame = $tg_resolve_tile_frame($post, $post);
            $is_trigram_tile = ($tg_id > 0 && $tg_slot > 0);
            // Per spec, a fit-mode cover (size<100 / border / matte) is matted on
            // the tile, NOT cropped. Trigram slices are square covers and never
            // framed. A framed tile must use the ASPECT thumbnail, otherwise we'd
            // be matting an already-square-cropped image.
            $do_frame = ($tile_frame['is_framed'] && !$is_trigram_tile);
            if ($do_frame) {
                $thumb_src = $post['img_thumb_aspect'] ?: ($post['img_thumb_square'] ?: $post['img_file']);
            }
            $tile_class = 'tg-tile';

            if ($tg_id > 0 && $tg_slot > 0) {
                $sc = ($tg_orient === 'v') ? ($slot_class_v[$tg_slot] ?? '') : ($slot_class_h[$tg_slot] ?? '');
                if ($sc) $tile_class .= ' ' . $sc;
                $tile_class .= ' tg-tile--trigram';
            }

            if ($do_frame) {
                $tile_class .= ' tg-tile--framed';
                // Orientation decides which axis the size% applies to: landscape
                // fills width, portrait fills height (so it never overflows the
                // square tile). The border hugs the actual image either way.
                if ((int)$post['img_height'] > (int)$post['img_width']) {
                    $tile_class .= ' tg-tile--portrait';
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
             data-trigram-id="<?php echo $tg_id; ?>"
             data-trigram-slot="<?php echo $tg_slot; ?>"
             <?php if ($tile_css_vars): ?>style="<?php echo $tile_css_vars; ?>"<?php endif; ?>>
            <a href="<?php echo $post_url; ?>" title="<?php echo $title_safe; ?>">
                <img src="<?php echo htmlspecialchars($thumb_src); ?>"
                     alt="<?php echo $title_safe; ?>"
                     loading="lazy">
            </a>

            <?php if ($is_carousel && $carousel_ind !== 'none'): ?>
                <div class="tg-tile-indicator">
                    <?php if ($carousel_ind === 'icon'): ?>
                        <span class="tg-tile-indicator--icon" aria-label="<?php echo $image_count; ?> images">⧉</span>
                    <?php else: ?>
                        <span class="tg-tile-indicator--count"><?php echo $image_count; ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($hover_overlay === 'title' || $hover_overlay === 'count'): ?>
                <div class="tg-tile-overlay" aria-hidden="true">
                    <span class="tg-tile-overlay-text">
                        <?php if ($hover_overlay === 'title'): ?>
                            <?php echo $title_safe; ?>
                        <?php else: ?>
                            <?php echo $image_count; ?> image<?php echo $image_count !== 1 ? 's' : ''; ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php elseif ($hover_overlay === 'dark'): ?>
                <div class="tg-tile-overlay tg-tile-overlay--dark" aria-hidden="true"></div>
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

</div><!-- /.tg-content-wrap -->

<?php /* Post modal overlay is now rendered once by skin-footer.php (shared by all
         Grid pages) so tg-modal.js finds its container on every page, not just
         the landing page. Do not re-add a per-page copy here. */ ?>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
