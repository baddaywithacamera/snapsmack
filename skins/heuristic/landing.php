<?php
/**
 * SNAPSMACK - HEURISTIC Landing Page
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
$show_profile    = ($settings['he_profile_header']     ?? '1') === '1';
$show_tagline    = ($settings['he_show_tagline']       ?? '1') === '1';
$carousel_ind    = $settings['he_carousel_indicator']  ?? 'icon';
$hover_overlay   = $settings['he_hover_overlay']       ?? 'title';
$customize_level = $settings['he_customize_level']     ?? 'per_grid';

// ── Frame style resolver ───────────────────────────────────────────────────
$_he_shadow_map = [
    '0' => 'none',
    '1' => '3px 3px 8px rgba(0,0,0,.20)',
    '2' => '6px 6px 18px rgba(0,0,0,.40)',
    '3' => '12px 12px 32px rgba(0,0,0,.60)',
];

$he_resolve_tile_frame = function ($cover_pi_row, $post_row) use ($settings, $customize_level, $_he_shadow_map) {
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
            $sz  = (int)($settings['he_frame_size_pct']     ?? 100);
            $bpx = (int)($settings['he_frame_border_px']    ?? 0);
            $bc  = $settings['he_frame_border_color'] ?? '#000000';
            $bg  = $settings['he_frame_bg_color']     ?? '#ffffff';
            $sh  = (string)($settings['he_frame_shadow']    ?? '0');
    }
    return [
        'size_pct'    => $sz,
        'border_px'   => $bpx,
        'border_color'=> $bc,
        'bg_color'    => $bg,
        'shadow_css'  => $_he_shadow_map[$sh] ?? 'none',
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

// Infomatic map format (one compact Skin Settings field):
// slug=CODE|LABEL|colour|counter. Counter: total, ordinal, images, post, none,
// or a literal value. Post-specific overrides use post:123 as the key.
$_he_map = [];
foreach (preg_split('/\s*;\s*/', trim($settings['he_infomatic_map'] ?? '')) as $_he_rule) {
    if ($_he_rule === '' || strpos($_he_rule, '=') === false) continue;
    [$_he_key, $_he_value] = array_map('trim', explode('=', $_he_rule, 2));
    $_he_parts = array_map('trim', explode('|', $_he_value));
    if ($_he_key !== '' && !empty($_he_parts[0])) {
        $_he_map[strtolower($_he_key)] = [
            'code'    => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $_he_parts[0]), 0, 3)),
            'label'   => substr($_he_parts[1] ?? 'HEURISTIC ANALYSIS', 0, 48),
            'colour'  => $_he_parts[2] ?? 'calm',
            'counter' => strtolower($_he_parts[3] ?? 'total'),
        ];
    }
}

$_he_post_categories = [];
$_he_category_totals = [];
if ($grid_posts) {
    try {
        $_he_cat_rows = $pdo->query(
            "SELECT i.post_id, c.cat_slug
             FROM snap_post_images pi
             JOIN snap_images i ON i.id = pi.image_id
             JOIN snap_image_cat_map cm ON cm.image_id = i.id
             JOIN snap_categories c ON c.id = cm.cat_id
             WHERE pi.is_cover = 1"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($_he_cat_rows as $_he_cat_row) {
            $_he_pid = (int) $_he_cat_row['post_id'];
            $_he_slug = strtolower((string) $_he_cat_row['cat_slug']);
            $_he_post_categories[$_he_pid][] = $_he_slug;
            $_he_category_totals[$_he_slug] = ($_he_category_totals[$_he_slug] ?? 0) + 1;
        }
    } catch (PDOException $e) {
        // Categories are an enhancement. A stale/missing map must not break the grid.
    }
}
$_he_category_ordinals = [];

// Backfill horizontal-trigram rows so the feed never shows blank gaps (singles
// slide up to finish the row before a trigram). Phantom padding stays as the
// tail-case safety net. Shared helper — see core/trigram.php.
require_once dirname(__DIR__, 2) . '/core/trigram.php';
if (function_exists('trigram_align_backfill')) $grid_posts = trigram_align_backfill($grid_posts);
?>
<div class="he-content-wrap landing-feed">

<?php include __DIR__ . '/skin-profile.php'; ?>

<!-- ── 3-Column Grid ───────────────────────────────────────────────────── -->
<main>
    <div class="he-grid">
        <?php
        // Slot labels for horizontal and vertical orientations.
        $slot_class_h = [1 => 'he-tile--trigram-L', 2 => 'he-tile--trigram-M', 3 => 'he-tile--trigram-R'];
        $slot_class_v = [1 => 'he-tile--trigram-T', 2 => 'he-tile--trigram-M', 3 => 'he-tile--trigram-B'];

        $col = 0; // track current column position (0, 1, 2)
        $he_idx = 0; // running cell index (incl. phantoms) → data-row/data-col for the wave

        foreach ($grid_posts as $post):
            $he_slot   = (int)($post['trigram_slot'] ?? 0);
            $he_orient = $post['trigram_orientation'] ?? 'h';
            $he_id     = (int)($post['trigram_id'] ?? 0);

            // ── Phantom padding ──────────────────────────────────────────
            // When the L post (slot 1) of a horizontal trigram falls off the
            // start of a row, emit invisible phantom tiles to complete the
            // current row first.
            if ($he_slot === 1 && $he_orient !== 'v' && $col !== 0):
                $phantoms = 3 - $col;
                for ($ph = 0; $ph < $phantoms; $ph++):
        ?>
        <div class="he-tile he-tile--phantom" aria-hidden="true"
             data-row="<?php echo intdiv($he_idx, 3); ?>" data-col="<?php echo $he_idx % 3; ?>"></div>
        <?php
                    $col = ($col + 1) % 3;
                    $he_idx++;
                endfor;
            endif;

            $thumb_src   = $post['img_thumb_square'] ?: $post['img_file'];
            $is_slice_tile = false; // true only when a physical slice file fronts this tile

            // Trigram cover: grid tile shows the panorama slice when set.
            if ($he_id > 0 && $he_slot > 0) {
                $he_label = ($he_orient === 'v')
                    ? (['T','M','B'][$he_slot - 1] ?? '')
                    : (['L','M','R'][$he_slot - 1] ?? '');
                if ($he_label !== '') {
                    $he_rel = 'trigrams/trigram-' . $he_id . '-' . $he_label . '.jpg';
                    if (is_file(dirname(__DIR__, 2) . '/' . $he_rel)) {
                        $thumb_src = $he_rel;
                        $is_slice_tile = true;
                    }
                }
            }

            $post_url    = BASE_URL . '?s=' . urlencode($post['img_slug']);
            $image_count = (int)$post['image_count'];
            $is_carousel = $image_count > 1;
            $title_safe  = htmlspecialchars($post['title']);

            $_he_rule = $_he_map['post:' . (int) $post['post_id']] ?? null;
            $_he_rule_slug = '';
            foreach ($_he_post_categories[(int) $post['post_id']] ?? [] as $_he_slug) {
                $_he_category_ordinals[$_he_slug] = ($_he_category_ordinals[$_he_slug] ?? 0) + 1;
                if ($_he_rule === null && isset($_he_map[$_he_slug])) {
                    $_he_rule = $_he_map[$_he_slug];
                    $_he_rule_slug = $_he_slug;
                }
            }
            $_he_code = $_he_rule['code'] ?? '';
            $_he_label = $_he_rule['label'] ?? '';
            $_he_colour = $_he_rule['colour'] ?? 'calm';
            $_he_counter = $_he_rule['counter'] ?? 'none';
            $_he_value = '';
            if ($_he_rule) {
                if ($_he_counter === 'total') $_he_value = (string) ($_he_category_totals[$_he_rule_slug] ?? 1);
                elseif ($_he_counter === 'ordinal') $_he_value = (string) ($_he_category_ordinals[$_he_rule_slug] ?? 1);
                elseif ($_he_counter === 'images') $_he_value = (string) $image_count;
                elseif ($_he_counter === 'post') $_he_value = (string) $post['post_id'];
                elseif ($_he_counter !== 'none') $_he_value = substr($_he_counter, 0, 12);
            }

            // ── Tile class ───────────────────────────────────────────────
            $tile_frame = $he_resolve_tile_frame($post, $post);
            $tile_class = 'he-tile';

            if ($he_id > 0 && $he_slot > 0) {
                $sc = ($he_orient === 'v') ? ($slot_class_v[$he_slot] ?? '') : ($slot_class_h[$he_slot] ?? '');
                if ($sc) $tile_class .= ' ' . $sc;
                $tile_class .= ' he-tile--trigram';
            }

            // Frame gate rides on SLICE-FILE EXISTENCE, not trigram membership:
            // slice-fronted tiles are always full-bleed; triptychs (no slice
            // files) keep their per-image frames — matching the fediverse bake.
            // A framed tile must use the ASPECT thumbnail (natural ratio),
            // otherwise we'd be matting an already-square-cropped image.
            $do_frame = ($tile_frame['is_framed'] && !$is_slice_tile);
            if ($do_frame) {
                $thumb_src = $post['img_thumb_aspect'] ?: ($post['img_thumb_square'] ?: $post['img_file']);
                $tile_class .= ' he-tile--framed';
                if ((int)$post['img_height'] > (int)$post['img_width']) {
                    $tile_class .= ' he-tile--portrait';
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
             data-trigram-id="<?php echo $he_id; ?>"
             data-trigram-slot="<?php echo $he_slot; ?>"
             data-he-post="<?php echo (int) $post['post_id']; ?>"
             data-he-code="<?php echo htmlspecialchars($_he_code); ?>"
             data-he-label="<?php echo htmlspecialchars($_he_label); ?>"
             data-he-colour="<?php echo htmlspecialchars($_he_colour); ?>"
             data-he-value="<?php echo htmlspecialchars($_he_value); ?>"
             data-row="<?php echo intdiv($he_idx, 3); ?>" data-col="<?php echo $he_idx % 3; ?>"
             <?php if ($tile_css_vars): ?>style="<?php echo $tile_css_vars; ?>"<?php endif; ?>>
            <div class="he-ring" aria-hidden="true"></div>
            <a href="<?php echo $post_url; ?>" title="<?php echo $title_safe; ?>">
                <img src="<?php echo htmlspecialchars($thumb_src); ?>"
                     alt="<?php echo $title_safe; ?>"
                     loading="lazy">
            </a>

            <?php if ($is_carousel && $carousel_ind !== 'none'): ?>
                <div class="he-tile-indicator">
                    <?php if ($carousel_ind === 'icon'): ?>
                        <span class="he-tile-indicator--icon" aria-label="<?php echo $image_count; ?> images">⧉</span>
                    <?php else: ?>
                        <span class="he-tile-indicator--count"><?php echo $image_count; ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($hover_overlay === 'title' || $hover_overlay === 'count'): ?>
                <div class="he-tile-overlay" aria-hidden="true">
                    <span class="he-tile-overlay-text">
                        <?php if ($hover_overlay === 'title'): ?>
                            <?php echo $title_safe; ?>
                        <?php else: ?>
                            <?php echo $image_count; ?> image<?php echo $image_count !== 1 ? 's' : ''; ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php elseif ($hover_overlay === 'dark'): ?>
                <div class="he-tile-overlay he-tile-overlay--dark" aria-hidden="true"></div>
            <?php endif; ?>
        </div>
        <?php
            $col = ($col + 1) % 3;
            $he_idx++;
        endforeach; ?>

        <?php if (empty($grid_posts)): ?>
        <div style="grid-column: 1/-1; padding: 60px 20px; text-align: center; color: var(--text-secondary);">
            <p>No posts yet. Start by uploading your first photograph.</p>
        </div>
        <?php endif; ?>
    </div><!-- /.he-grid -->

</main>

</div><!-- /.he-content-wrap -->

<?php /* Post modal overlay is now rendered once by skin-footer.php (shared by all
         Grid pages) so he-modal.js finds its container on every page, not just
         the landing page. Do not re-add a per-page copy here. */ ?>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
