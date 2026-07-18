<?php
/**
 * SNAPSMACK - AURORA Post View
 * Alpha v0.7.9
 *
 * Single post view. For single-image posts, renders the full image with
 * EXIF and caption below. For carousel posts (image_count > 1), wraps all
 * images in a SnapSlider in carousel mode with dot indicators and dispatches
 * snapslider:slidechange to update the EXIF panel on each swipe.
 *
 * Variables from index.php / layout-logic.php:
 *   $pdo, $settings, $img, $active_skin, $exif_data, $comments
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once dirname(__DIR__, 2) . '/core/layout-logic.php';
require_once dirname(__DIR__, 2) . '/core/snap-tags.php';

// ── Direct visit to a post URL (NOT a modal-fragment fetch) ──────────────────
// We no longer render a standalone "flat" post page. Render the grid and flag
// au-modal.js (via skin-footer's data-autoopen) to open this post's modal over
// the grid — Instagram-style deep linking. The .au-post-ig markup below is
// therefore ONLY ever produced as a modal fragment (?modal=1).
if (empty($_GET['modal'])) {
    $au_autoopen = true;
    include __DIR__ . '/landing.php';
    return;
}

// ── Load the post container for this image ────────────────────────────────
$post = null;
$post_images = [];

if (!empty($img['post_id'])) {
    $post_stmt = $pdo->prepare("SELECT * FROM snap_posts WHERE id = ?");
    $post_stmt->execute([$img['post_id']]);
    $post = $post_stmt->fetch();
}

if ($post) {
    // Load all images in the post in sort order (including per-image frame style)
    $pi_stmt = $pdo->prepare("
        SELECT i.*, pi.sort_position, pi.is_cover,
               pi.img_size_pct, pi.img_border_px, pi.img_border_color,
               pi.img_bg_color, pi.img_shadow
        FROM snap_post_images pi
        JOIN snap_images i ON i.id = pi.image_id
        WHERE pi.post_id = ? AND pi.sort_position >= 0
        ORDER BY pi.sort_position ASC
    ");
    $pi_stmt->execute([$post['id']]);
    $post_images = $pi_stmt->fetchAll();
}

// ── Frame style resolution ─────────────────────────────────────────────────
// Resolves the correct size/border/bg/shadow for a given image based on
// the active customisation level (per_grid | per_carousel | per_image).
$_au_shadow_map = [
    '0' => 'none',
    '1' => '3px 3px 8px rgba(0,0,0,.20)',
    '2' => '6px 6px 18px rgba(0,0,0,.40)',
    '3' => '12px 12px 32px rgba(0,0,0,.60)',
];
$_au_customize_level = $settings['au_customize_level'] ?? 'per_grid';

$au_resolve_frame = function ($pi_row) use ($settings, $_au_customize_level, $_au_shadow_map, &$post) {
    switch ($_au_customize_level) {
        case 'per_image':
            $sz  = (int)($pi_row['img_size_pct']     ?? 100);
            $bpx = (int)($pi_row['img_border_px']    ?? 0);
            $bc  = $pi_row['img_border_color'] ?? '#000000';
            $bg  = $pi_row['img_bg_color']     ?? '#ffffff';
            $sh  = (string)($pi_row['img_shadow']    ?? '0');
            break;
        case 'per_carousel':
            $sz  = (int)($post['post_img_size_pct']  ?? 100);
            $bpx = (int)($post['post_border_px']     ?? 0);
            $bc  = $post['post_border_color'] ?? '#000000';
            $bg  = $post['post_bg_color']     ?? '#ffffff';
            $sh  = (string)($post['post_shadow']     ?? '0');
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

// Fallback: single image, no post container (legacy)
if (empty($post_images)) {
    $post_images = [$img];
    $post = [
        'title'          => $img['img_title'],
        'description'    => $img['img_description'],
        'allow_comments' => $img['allow_comments'],
        'created_at'     => $img['img_date'],
        'post_type'      => 'single',
    ];
}

$is_carousel   = count($post_images) > 1;
$page_title    = $post['title'];

// ── Build EXIF map for the carousel JS (image_id → exif fields) ──────────
$exif_map = [];
foreach ($post_images as $pimg) {
    $raw = json_decode($pimg['img_exif'] ?? '{}', true) ?: [];
    $exif_map[$pimg['id']] = array_filter([
        'camera'   => $raw['camera']   ?? '',
        'lens'     => $raw['lens']     ?? '',
        'focal'    => $raw['focal']    ?? '',
        'film'     => $raw['film']     ?? ($pimg['img_film'] ?? ''),
        'iso'      => $raw['iso']      ?? '',
        'aperture' => $raw['aperture'] ?? '',
        'shutter'  => $raw['shutter']  ?? '',
        'flash'    => $raw['flash']    ?? '',
    ]);
}

// ── Current (first/cover) image EXIF for initial render ──────────────────
$cover_img  = $post_images[0];
$cover_exif = json_decode($cover_img['img_exif'] ?? '{}', true) ?: [];

$_au_modal_mode = !empty($_GET['modal']);

if (!$_au_modal_mode) {
    include __DIR__ . '/skin-header.php';
}
?>

<?php
// ── Right panel: avatar + site name ───────────────────────────────────────
$_avatar_path    = $settings['skin_avatar'] ?? '';
$_avatar_exists  = $_avatar_path && file_exists(dirname(__DIR__, 2) . '/' . $_avatar_path);
$_site_name      = $settings['site_name'] ?? 'SnapSmack';
$_avatar_initial = strtoupper(substr($_site_name, 0, 1));
?>
<div class="au-post-ig">

    <!-- ── LEFT: image panel (never scrolls) ───────────────────────────── -->
    <div class="au-post-ig-image">

        <?php if ($is_carousel): ?>
        <!-- Carousel -->
        <div class="au-carousel-wrap">
            <div id="au-carousel"
                 class="ss-slider"
                 data-slider-mode="carousel"
                 data-exif-map="<?php echo htmlspecialchars(json_encode($exif_map)); ?>">
                <div class="slider-track">
                    <?php foreach ($post_images as $pimg):
                        $frame = $au_resolve_frame($pimg);
                        if ($frame['is_framed']):
                            $slide_vars = sprintf(
                                '--slide-bg:%s; --slide-img-size:%d%%; --slide-border-w:%dpx; --slide-border-c:%s; --slide-shadow:%s;',
                                htmlspecialchars($frame['bg_color']),
                                $frame['size_pct'],
                                $frame['border_px'],
                                htmlspecialchars($frame['border_color']),
                                htmlspecialchars($frame['shadow_css'])
                            );
                            $slide_class = 'slider-slide au-slide--framed';
                            $img_style   = '';
                        else:
                            $slide_vars  = '';
                            $slide_class = 'slider-slide';
                            $img_style   = '';
                        endif;
                    ?>
                    <div class="<?php echo $slide_class; ?>"
                         data-image-id="<?php echo $pimg['id']; ?>"
                         <?php if ($slide_vars): ?>style="<?php echo $slide_vars; ?>"<?php endif; ?>>
                        <img src="<?php echo htmlspecialchars($pimg['img_file']); ?>"
                             alt="<?php echo htmlspecialchars($pimg['img_title']); ?>"
                             data-lightbox-src="<?php echo htmlspecialchars($pimg['img_file']); ?>"
                             <?php if ($img_style): ?>style="<?php echo $img_style; ?>"<?php endif; ?>
                             loading="lazy">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Dots injected here by SnapSlider -->
        </div>

        <?php else:
            $frame = $au_resolve_frame($cover_img);
            $single_img_style = $frame['is_framed'] ? sprintf(
                'max-width:%d%%; border:%dpx solid %s; box-shadow:%s; box-sizing:border-box;',
                $frame['size_pct'], $frame['border_px'],
                htmlspecialchars($frame['border_color']), htmlspecialchars($frame['shadow_css'])
            ) : '';
        ?>
        <!-- Single image -->
        <img src="<?php echo htmlspecialchars($cover_img['img_file']); ?>"
             alt="<?php echo htmlspecialchars($cover_img['img_title']); ?>"
             data-lightbox-src="<?php echo htmlspecialchars($cover_img['img_file']); ?>"
             class="au-single-img"
             <?php if ($single_img_style): ?>style="<?php echo $single_img_style; ?>"<?php endif; ?>>
        <?php endif; ?>

    </div><!-- .au-post-ig-image -->

    <!-- ── RIGHT: info panel (scrollable) ──────────────────────────────── -->
    <div class="au-post-ig-info">

        <!-- Fixed header -->
        <div class="au-post-ig-header">
            <button class="au-back-btn" type="button" aria-label="Back to grid">&#8592;</button>
            <?php if ($_avatar_exists): ?>
                <img class="au-post-ig-avatar"
                     src="<?php echo BASE_URL . htmlspecialchars($_avatar_path); ?>"
                     alt="">
            <?php else: ?>
                <span class="au-post-ig-avatar au-post-ig-avatar--initials"><?php echo htmlspecialchars($_avatar_initial); ?></span>
            <?php endif; ?>
            <span class="au-post-ig-sitename"><?php echo htmlspecialchars($_site_name); ?></span>
        </div>

        <!-- Scrollable body -->
        <div class="au-post-ig-body">

            <!-- Caption IG-style: bold sitename inline + caption text -->
            <div class="au-post-caption-block">
                <p class="au-post-ig-caption">
                    <span class="au-post-ig-caption-user"><?php echo htmlspecialchars($_site_name); ?></span>
                    <?php
                    $caption_parts = [];
                    if (!empty($post['title'])) {
                        $caption_parts[] = htmlspecialchars($post['title']);
                    }
                    if (!empty($post['description'])) {
                        $desc_html = snap_render_caption_html($post['description'], BASE_URL, 'au-hashtag');
                        if (function_exists('snapsmack_parse_shortcodes')) {
                            $caption_parts[] = snapsmack_parse_shortcodes(nl2br($desc_html));
                        } else {
                            $caption_parts[] = nl2br($desc_html);
                        }
                    }
                    echo implode('<br>', $caption_parts);
                    ?>
                </p>
            </div>

            <?php
            // ── EXIF panel ───────────────────────────────────────────────────
            // Build the cover image's EXIF rows into a buffer first, then only
            // emit the panel when there is EXIF to show — on the cover now, or on
            // another slide (carousel mode injects it via snapslider:slidechange).
            // Posts with no EXIF (phone/IG imports, GramOfSmack mode) emit NO panel,
            // so the bordered strip never renders as empty stray lines.
            $exif_fields = [
                'camera'   => 'Camera',
                'lens'     => 'Lens',
                'focal'    => 'Focal',
                'film'     => 'Film',
                'iso'      => 'ISO',
                'aperture' => 'Aperture',
                'shutter'  => 'Shutter',
                'flash'    => 'Flash',
            ];
            $exif_rows = '';
            foreach ($exif_fields as $key => $label):
                $val = trim($cover_exif[$key] ?? ($key === 'film' ? ($cover_img['img_film'] ?? '') : ''));
                if ($val === '') continue;
                $exif_rows .= '<div class="au-exif-item" data-exif-key="' . $key . '">'
                            . '<span class="au-exif-label">' . $label . '</span>'
                            . '<span class="au-exif-value">' . htmlspecialchars($val) . '</span></div>';
            endforeach;
            $exif_any = ($exif_rows !== '') || !empty(array_filter($exif_map));
            if ($exif_any):
            ?>
            <!-- EXIF panel — updated by snapslider:slidechange in carousel mode -->
            <div id="au-exif-panel" class="au-exif-panel"><?php echo $exif_rows; ?></div>
            <?php endif; ?>

            <?php if (!empty($post['allow_comments'])): ?>
            <div class="au-community-wrap">
                <?php
                $img = $cover_img;
                include dirname(__DIR__, 2) . '/core/community-component.php';
                ?>
            </div>
            <?php endif; ?>

        </div><!-- .au-post-ig-body -->

        <!-- Engagement bar — pinned above footer, IG-style -->
        <div class="au-post-ig-actions">
            <div class="au-post-ig-action-icons">
                <button class="au-action-btn" aria-label="Comment" onclick="document.querySelector('.au-community-wrap')?.scrollIntoView({behavior:'smooth'})">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </button>
                <button class="au-action-btn au-action-bookmark" aria-label="Save">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                </button>
            </div>
            <p class="au-post-ig-date"><?php echo date('F j, Y', strtotime($post['created_at'])); ?></p>
        </div>

        <?php if (!$_au_modal_mode) include __DIR__ . '/skin-footer.php'; ?>

    </div><!-- .au-post-ig-info -->

</div><!-- .au-post-ig -->
<?php // ===== SNAPSMACK EOF ====