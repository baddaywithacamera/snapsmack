<?php
/**
 * Hip to be Square - Slider Landing Page
 *
 * Full-viewport horizontal slider showing framed images.
 * Uses the reusable SnapSlider engine (ss-engine-slider.js).
 * Variables available from index.php: $pdo, $settings, $img, $active_skin, $site_name
 */

// Fetch recent published images for the slider
$now_local = date('Y-m-d H:i:s');
$slider_limit = 20; // Max images in slider
$slider_stmt = $pdo->prepare("
    SELECT id, img_title, img_slug, img_file, img_thumb_square, img_date, img_display_options
    FROM snap_images
    WHERE img_status = 'published' AND img_date <= ?
    ORDER BY img_date DESC
    LIMIT ?
");
$slider_stmt->execute([$now_local, $slider_limit]);
$slider_images = $slider_stmt->fetchAll(PDO::FETCH_ASSOC);

// Slider config from settings (Pimpotron values)
$per_view = (int)($settings['htbs_slider_per_view'] ?? 2);
$speed = (int)($settings['htbs_slider_speed'] ?? 800);
$auto_advance = ($settings['htbs_slider_auto'] ?? '0') === '1';
$loop = ($settings['htbs_slider_loop'] ?? '1') === '1';
?>

<div id="scroll-stage" class="htbs-slider-landing">

    <?php include('skin-header.php'); ?>

    <div class="htbs-slider-container">
        <div class="htbs-slider-wrapper">
            <div id="htbs-gallery-slider" class="ss-slider">
                <div class="slider-track">
                    <?php foreach ($slider_images as $slide):
                        $slide_link = BASE_URL . htmlspecialchars($slide['img_slug']);

                        // Per-image frame overrides
                        $d_opts = [];
                        if (!empty($slide['img_display_options'])) {
                            $d_opts = json_decode($slide['img_display_options'], true) ?? [];
                        }
                        $style_parts = [];
                        if (!empty($d_opts['frame_color'])) $style_parts[] = "--frame-color:{$d_opts['frame_color']}";
                        if (!empty($d_opts['frame_width'])) $style_parts[] = "--frame-width:{$d_opts['frame_width']}px";
                        if (!empty($d_opts['mat_color'])) $style_parts[] = "--mat-color:{$d_opts['mat_color']}";
                        if (!empty($d_opts['mat_width'])) $style_parts[] = "--mat-width:{$d_opts['mat_width']}px";
                        $inline = !empty($style_parts) ? ' style="' . implode(';', $style_parts) . '"' : '';

                        // Use square thumbnail for slider
                        $thumb = $slide['img_thumb_square'] ?? '';
                        $img_url = !empty($thumb) ? BASE_URL . ltrim($thumb, '/') : BASE_URL . ltrim($slide['img_file'], '/');
                    ?>
                    <div class="slider-slide">
                        <a href="<?php echo $slide_link; ?>" class="htbs-slide-link">
                            <div class="frame-mount"<?php echo $inline; ?>>
                                <div class="frame-border">
                                    <div class="frame-mat">
                                        <div class="frame-bevel">
                                            <div class="frame-image">
                                                <img src="<?php echo $img_url; ?>"
                                                     alt="<?php echo htmlspecialchars($slide['img_title']); ?>"
                                                     loading="lazy">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <!-- Arrows injected automatically by SnapSlider engine -->
            </div>
        </div>
    </div>

    <?php include('skin-footer.php'); ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof SnapSlider !== 'undefined') {
        new SnapSlider({
            container: document.getElementById('htbs-gallery-slider'),
            perView: <?php echo $per_view; ?>,
            speed: <?php echo $speed; ?>,
            easing: 'ease-in-out',
            autoAdvance: <?php echo $auto_advance ? 'true' : 'false'; ?>,
            autoInterval: 5000,
            loop: <?php echo $loop ? 'true' : 'false'; ?>
        });
    }
});
</script>
