<?php
/**
 * SNAPSMACK - The Grid Post View
 * Alpha v0.7.1
 *
 * Single post view. For single-image posts, renders the full image with
 * EXIF and caption below. For carousel posts (image_count > 1), wraps all
 * images in a SnapSlider in carousel mode with dot indicators and dispatches
 * snapslider:slidechange to update the EXIF panel on each swipe.
 *
 * Variables from index.php / layout_logic.php:
 *   $pdo, $settings, $img, $active_skin, $exif_data, $comments
 */

require_once dirname(__DIR__, 2) . '/core/layout_logic.php';

// ── Load the post container for this image ────────────────────────────────
$post = null;
$post_images = [];

if (!empty($img['post_id'])) {
    $post_stmt = $pdo->prepare("SELECT * FROM snap_posts WHERE id = ?");
    $post_stmt->execute([$img['post_id']]);
    $post = $post_stmt->fetch();
}

if ($post) {
    // Load all images in the post in sort order
    $pi_stmt = $pdo->prepare("
        SELECT i.*, pi.sort_position, pi.is_cover
        FROM snap_post_images pi
        JOIN snap_images i ON i.id = pi.image_id
        WHERE pi.post_id = ? AND pi.sort_position >= 0
        ORDER BY pi.sort_position ASC
    ");
    $pi_stmt->execute([$post['id']]);
    $post_images = $pi_stmt->fetchAll();
}

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

include dirname(__DIR__, 2) . '/core/meta.php';
include __DIR__ . '/skin-header.php';
?>

<div class="tg-post-wrap">

    <!-- ── Post Header ─────────────────────────────────────────────────── -->
    <div class="tg-post-header">
        <button class="tg-back-btn" onclick="history.back()" aria-label="Back to grid">&#8592;</button>
        <h1 class="tg-post-title-text"><?php echo htmlspecialchars($post['title']); ?></h1>
    </div>

    <?php if ($is_carousel): ?>
    <!-- ── Carousel (multi-image post) ────────────────────────────────── -->
    <div class="tg-carousel-wrap">
        <div id="tg-carousel"
             class="ss-slider"
             data-slider-mode="carousel"
             data-exif-map="<?php echo htmlspecialchars(json_encode($exif_map)); ?>">
            <div class="slider-track">
                <?php foreach ($post_images as $pimg): ?>
                <div class="slider-slide" data-image-id="<?php echo $pimg['id']; ?>">
                    <img src="<?php echo htmlspecialchars($pimg['img_file']); ?>"
                         alt="<?php echo htmlspecialchars($pimg['img_title']); ?>"
                         style="width:100%; max-height:80vh; object-fit:contain; background:#000;"
                         loading="lazy">
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Dots injected here by SnapSlider -->
    </div>

    <?php else: ?>
    <!-- ── Single image ───────────────────────────────────────────────── -->
    <div style="background:#000; text-align:center;">
        <img src="<?php echo htmlspecialchars($cover_img['img_file']); ?>"
             alt="<?php echo htmlspecialchars($cover_img['img_title']); ?>"
             class="tg-single-img">
    </div>
    <?php endif; ?>

    <!-- ── Post Body ───────────────────────────────────────────────────── -->
    <div class="tg-post-body">

        <?php if (!empty($post['description'])): ?>
        <div class="tg-post-caption">
            <?php
            // Run through the shortcode parser if available
            if (function_exists('snapsmack_parse_shortcodes')) {
                echo snapsmack_parse_shortcodes(nl2br(htmlspecialchars($post['description'])));
            } else {
                echo nl2br(htmlspecialchars($post['description']));
            }
            ?>
        </div>
        <?php endif; ?>

        <!-- EXIF panel — updated by snapslider:slidechange in carousel mode -->
        <div id="tg-exif-panel" class="tg-exif-panel">
            <?php
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
            foreach ($exif_fields as $key => $label):
                $val = trim($cover_exif[$key] ?? ($key === 'film' ? ($cover_img['img_film'] ?? '') : ''));
                if (!$val) continue;
            ?>
            <div class="tg-exif-item" data-exif-key="<?php echo $key; ?>">
                <span class="tg-exif-label"><?php echo $label; ?></span>
                <span class="tg-exif-value"><?php echo htmlspecialchars($val); ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="tg-post-date">
            <?php echo date('F j, Y', strtotime($post['created_at'])); ?>
        </p>
    </div>

    <!-- ── Community Component ─────────────────────────────────────────── -->
    <?php if (!empty($post['allow_comments'])): ?>
    <div class="tg-community-wrap">
        <?php
        // community-component.php expects $img to be in scope
        $img = $cover_img;
        include dirname(__DIR__, 2) . '/core/community-component.php';
        ?>
    </div>
    <?php endif; ?>

</div><!-- .tg-post-wrap -->

<?php if ($is_carousel): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var container = document.getElementById('tg-carousel');
    if (!container || typeof SnapSlider === 'undefined') return;

    var slider = new SnapSlider({
        container: container,
        speed:     400,
        loop:      false
    });

    // Update EXIF panel on slide change
    container.addEventListener('snapslider:slidechange', function (e) {
        var exif  = e.detail.exif || {};
        var panel = document.getElementById('tg-exif-panel');
        if (!panel) return;

        // Clear existing items
        panel.innerHTML = '';

        var fields = {
            camera: 'Camera', lens: 'Lens', focal: 'Focal', film: 'Film',
            iso: 'ISO', aperture: 'Aperture', shutter: 'Shutter', flash: 'Flash'
        };

        Object.keys(fields).forEach(function (key) {
            var val = (exif[key] || '').trim();
            if (!val) return;
            var item  = document.createElement('div');
            item.className = 'tg-exif-item';
            item.setAttribute('data-exif-key', key);
            var lbl   = document.createElement('span');
            lbl.className = 'tg-exif-label';
            lbl.textContent = fields[key];
            var valEl = document.createElement('span');
            valEl.className = 'tg-exif-value';
            valEl.textContent = val;
            item.appendChild(lbl);
            item.appendChild(valEl);
            panel.appendChild(item);
        });
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/skin-footer.php'; ?>
