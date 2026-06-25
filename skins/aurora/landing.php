<?php
/**
 * SNAPSMACK - AURORA Landing Page
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
$show_profile    = ($settings['au_profile_header']     ?? '1') === '1';
$show_tagline    = ($settings['au_show_tagline']       ?? '1') === '1';
$carousel_ind    = $settings['au_carousel_indicator']  ?? 'icon';
$hover_overlay   = $settings['au_hover_overlay']       ?? 'title';
$customize_level = $settings['au_customize_level']     ?? 'per_grid';

// ── Frame style resolver ───────────────────────────────────────────────────
$_au_shadow_map = [
    '0' => 'none',
    '1' => '3px 3px 8px rgba(0,0,0,.20)',
    '2' => '6px 6px 18px rgba(0,0,0,.40)',
    '3' => '12px 12px 32px rgba(0,0,0,.60)',
];

$au_resolve_tile_frame = function ($cover_pi_row, $post_row) use ($settings, $customize_level, $_au_shadow_map) {
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
            $sz  = (int)($settings['au_frame_size_pct']     ?? 100);
            $bpx = (int)($settings['au_frame_border_px']    ?? 0);
            $bc  = $settings['au_frame_border_color'] ?? '#000000';
            $bg  = $settings['au_frame_bg_color']     ?? '#ffffff';
            $sh  = (string)($settings['au_frame_shadow']    ?? '0');
    }
    return [
        'size_pct'    => $sz,
        'border_px'   => $bpx,
        'border_color'=> $bc,
        'bg_color'    => $bg,
        'shadow_css'  => $_au_shadow_map[$sh] ?? 'none',
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
    ORDER BY CASE WHEN p.sort_order > 0 THEN 0 ELSE 1 END ASC,
             p.sort_order ASC,
             p.created_at DESC
");
$grid_stmt->execute([$now_local]);
$grid_posts = $grid_stmt->fetchAll();
?>
<div class="au-content-wrap">

<?php include __DIR__ . '/skin-profile.php'; ?>

<!-- ── 3-Column Grid ───────────────────────────────────────────────────── -->
<main>
    <div class="au-grid">
        <?php
        // Slot labels for horizontal and vertical orientations.
        $slot_class_h = [1 => 'au-tile--trigram-L', 2 => 'au-tile--trigram-M', 3 => 'au-tile--trigram-R'];
        $slot_class_v = [1 => 'au-tile--trigram-T', 2 => 'au-tile--trigram-M', 3 => 'au-tile--trigram-B'];

        $col = 0; // track current column position (0, 1, 2)
        $au_idx = 0; // running cell index (incl. phantoms) → data-row/data-col for the wave

        foreach ($grid_posts as $post):
            $au_slot   = (int)($post['trigram_slot'] ?? 0);
            $au_orient = $post['trigram_orientation'] ?? 'h';
            $au_id     = (int)($post['trigram_id'] ?? 0);

            // ── Phantom padding ──────────────────────────────────────────
            // When the L post (slot 1) of a horizontal trigram falls off the
            // start of a row, emit invisible phantom tiles to complete the
            // current row first.
            if ($au_slot === 1 && $au_orient !== 'v' && $col !== 0):
                $phantoms = 3 - $col;
                for ($ph = 0; $ph < $phantoms; $ph++):
        ?>
        <div class="au-tile au-tile--phantom" aria-hidden="true"
             data-row="<?php echo intdiv($au_idx, 3); ?>" data-col="<?php echo $au_idx % 3; ?>"></div>
        <?php
                    $col = ($col + 1) % 3;
                    $au_idx++;
                endfor;
            endif;

            $thumb_src   = $post['img_thumb_square'] ?: $post['img_file'];

            // Trigram cover: grid tile shows the panorama slice when set.
            if ($au_id > 0 && $au_slot > 0) {
                $au_label = ($au_orient === 'v')
                    ? (['T','M','B'][$au_slot - 1] ?? '')
                    : (['L','M','R'][$au_slot - 1] ?? '');
                if ($au_label !== '') {
                    $au_rel = 'trigrams/trigram-' . $au_id . '-' . $au_label . '.jpg';
                    if (is_file(dirname(__DIR__, 2) . '/' . $au_rel)) $thumb_src = $au_rel;
                }
            }

            $post_url    = BASE_URL . '?s=' . urlencode($post['img_slug']);
            $image_count = (int)$post['image_count'];
            $is_carousel = $image_count > 1;
            $title_safe  = htmlspecialchars($post['title']);

            // ── Tile class ───────────────────────────────────────────────
            $tile_frame = $au_resolve_tile_frame($post, $post);
            $tile_class = 'au-tile';

            if ($au_id > 0 && $au_slot > 0) {
                $sc = ($au_orient === 'v') ? ($slot_class_v[$au_slot] ?? '') : ($slot_class_h[$au_slot] ?? '');
                if ($sc) $tile_class .= ' ' . $sc;
                $tile_class .= ' au-tile--trigram';
            }

            if ($tile_frame['is_framed']) $tile_class .= ' au-tile--framed';

            $tile_css_vars = '';
            if ($tile_frame['is_framed']) {
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
             data-trigram-id="<?php echo $au_id; ?>"
             data-trigram-slot="<?php echo $au_slot; ?>"
             data-row="<?php echo intdiv($au_idx, 3); ?>" data-col="<?php echo $au_idx % 3; ?>"
             <?php if ($tile_css_vars): ?>style="<?php echo $tile_css_vars; ?>"<?php endif; ?>>
            <div class="au-ring" aria-hidden="true"></div>
            <a href="<?php echo $post_url; ?>" title="<?php echo $title_safe; ?>">
                <img src="<?php echo htmlspecialchars($thumb_src); ?>"
                     alt="<?php echo $title_safe; ?>"
                     loading="lazy">
            </a>

            <?php if ($is_carousel && $carousel_ind !== 'none'): ?>
                <div class="au-tile-indicator">
                    <?php if ($carousel_ind === 'icon'): ?>
                        <span class="au-tile-indicator--icon" aria-label="<?php echo $image_count; ?> images">⧉</span>
                    <?php else: ?>
                        <span class="au-tile-indicator--count"><?php echo $image_count; ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($hover_overlay === 'title' || $hover_overlay === 'count'): ?>
                <div class="au-tile-overlay" aria-hidden="true">
                    <span class="au-tile-overlay-text">
                        <?php if ($hover_overlay === 'title'): ?>
                            <?php echo $title_safe; ?>
                        <?php else: ?>
                            <?php echo $image_count; ?> image<?php echo $image_count !== 1 ? 's' : ''; ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php elseif ($hover_overlay === 'dark'): ?>
                <div class="au-tile-overlay au-tile-overlay--dark" aria-hidden="true"></div>
            <?php endif; ?>
        </div>
        <?php
            $col = ($col + 1) % 3;
            $au_idx++;
        endforeach; ?>

        <?php if (empty($grid_posts)): ?>
        <div style="grid-column: 1/-1; padding: 60px 20px; text-align: center; color: var(--text-secondary);">
            <p>No posts yet. Start by uploading your first photograph.</p>
        </div>
        <?php endif; ?>
    </div><!-- /.au-grid -->

</main>

</div><!-- /.au-content-wrap -->

<?php /* Post modal overlay is now rendered once by skin-footer.php (shared by all
         Grid pages) so au-modal.js finds its container on every page, not just
         the landing page. Do not re-add a per-page copy here. */ ?>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
