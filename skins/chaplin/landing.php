<?php
/**
 * SNAPSMACK - Chaplin skin: slider landing page
 *
 * Full-viewport horizontal slider of framed square images.
 * Uses the reusable SnapSlider engine (ss-engine-slider.js).
 *
 * Variables from index.php: $pdo, $settings, $img, $active_skin, $site_name
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$now_local   = date('Y-m-d H:i:s');
$slider_limit = 20;
$slider_stmt = $pdo->prepare("
    SELECT id, img_title, img_slug, img_file, img_thumb_square, img_date, img_display_options
    FROM snap_images
    WHERE img_status = 'published' AND img_date <= ?
    ORDER BY sort_order ASC, img_date DESC
    LIMIT ?
");
$slider_stmt->execute([$now_local, $slider_limit]);
$slider_images = $slider_stmt->fetchAll(PDO::FETCH_ASSOC);

$per_view    = (int)($settings['chap_slider_per_view'] ?? 2);
$speed       = (int)($settings['chap_slider_speed']    ?? 1000);
$auto_advance = ($settings['chap_slider_auto']  ?? '0') === '1';
$loop        = ($settings['chap_slider_loop']   ?? '1') === '1';
?>

<div id="scroll-stage" class="chap-slider-landing">

    <?php include('skin-header.php'); ?>

    <div class="chap-slider-container">
        <div class="chap-slider-wrapper">
            <div id="chap-gallery-slider" class="ss-slider" data-auto-init
                 data-per-view="<?php echo $per_view; ?>"
                 data-speed="<?php echo $speed; ?>"
                 data-easing="ease-in-out"
                 data-auto-advance="<?php echo $auto_advance ? 'true' : 'false'; ?>"
                 data-auto-interval="6000"
                 data-loop="<?php echo $loop ? 'true' : 'false'; ?>">
                <div class="slider-track">
                    <?php foreach ($slider_images as $slide):
                        $slide_link = BASE_URL . htmlspecialchars($slide['img_slug']);

                        $d_opts = [];
                        if (!empty($slide['img_display_options'])) {
                            $d_opts = json_decode($slide['img_display_options'], true) ?? [];
                        }
                        $style_parts = [];
                        if (!empty($d_opts['frame_color'])) $style_parts[] = "--frame-color:{$d_opts['frame_color']}";
                        if (!empty($d_opts['frame_width'])) $style_parts[] = "--frame-width:{$d_opts['frame_width']}px";
                        if (!empty($d_opts['mat_color']))   $style_parts[] = "--mat-color:{$d_opts['mat_color']}";
                        if (!empty($d_opts['mat_width']))   $style_parts[] = "--mat-width:{$d_opts['mat_width']}px";
                        $inline = !empty($style_parts) ? ' style="' . implode(';', $style_parts) . '"' : '';

                        if (!empty($slide['img_thumb_square'])) {
                            $img_url = BASE_URL . ltrim($slide['img_thumb_square'], '/');
                        } elseif (!empty($slide['img_file'])) {
                            $pi = pathinfo($slide['img_file']);
                            $img_url = BASE_URL . ltrim($pi['dirname'] . '/thumbs/t_' . $pi['basename'], '/');
                        } else {
                            $img_url = '';
                        }
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
                <!-- Arrows injected by SnapSlider engine -->
            </div>
        </div>
    </div>

    <?php include('skin-footer.php'); ?>
</div>
<?php // ===== SNAPSMACK EOF =====
