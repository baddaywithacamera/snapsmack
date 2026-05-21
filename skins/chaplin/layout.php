<?php
/**
 * SNAPSMACK - Chaplin skin: single image view
 *
 * Full-screen framed image with Art Deco ornament overlay, intertitle
 * card caption, filmstrip, and info/comments overlay.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once dirname(__DIR__, 2) . '/core/layout-logic.php';

// Per-image frame overrides
$display_opts = [];
if (!empty($img['img_display_options'])) {
    $display_opts = json_decode($img['img_display_options'], true) ?? [];
}
$frame_style = '';
if (!empty($display_opts['frame_color'])) $frame_style .= "--frame-color:{$display_opts['frame_color']};";
if (!empty($display_opts['frame_width'])) $frame_style .= "--frame-width:{$display_opts['frame_width']}px;";
if (!empty($display_opts['mat_color']))   $frame_style .= "--mat-color:{$display_opts['mat_color']};";
if (!empty($display_opts['mat_width']))   $frame_style .= "--mat-width:{$display_opts['mat_width']}px;";
?>
<canvas id="chap-film-bg" aria-hidden="true"></canvas>
<div id="scroll-stage" class="chap-single">

    <?php include __DIR__ . '/skin-header.php'; ?>

    <div class="chap-presentation">

        <?php include __DIR__ . '/frame-deco.php'; ?>

        <div class="chap-frame-area">
            <div class="frame-mount"<?php echo $frame_style ? " style=\"{$frame_style}\"" : ''; ?>>
                <div class="frame-border">
                    <div class="frame-mat">
                        <div class="frame-bevel">
                            <div class="frame-image">
                                <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
                                <img src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>"
                                     alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                                     class="post-image">
                                <?php echo $download_button; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="chap-intertitle">
            <div class="chap-intertitle-title">
                <?php echo htmlspecialchars($img['img_title']); ?>
            </div>
            <div class="chap-intertitle-rule"></div>
            <?php if (!empty($img['img_description'])): ?>
            <div class="chap-intertitle-body">
                <?php
                $brief = strip_tags($snapsmack->parseContent($img['img_description'] ?? ''));
                echo htmlspecialchars(mb_strimwidth($brief, 0, 120, '…'));
                ?>
            </div>
            <?php endif; ?>
            <div class="chap-intertitle-date">
                <?php echo date('F j, Y', strtotime($img['img_date'])); ?>
            </div>
        </div>

    </div><!-- /.chap-presentation -->

    <div id="infobox">
        <?php include dirname(__DIR__, 2) . '/core/navigation-bar.php'; ?>
    </div>

    <!-- FILMSTRIP -->
    <?php
    $now_local = date('Y-m-d H:i:s');
    $film_stmt = $pdo->prepare("
        SELECT id, img_slug, img_file, img_thumb_square, img_title
        FROM snap_images
        WHERE img_status = 'published' AND img_date <= ?
        ORDER BY sort_order ASC, img_date DESC
        LIMIT 60
    ");
    $film_stmt->execute([$now_local]);
    $filmstrip_images = $film_stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="chap-filmstrip">
        <?php foreach ($filmstrip_images as $fi):
            $fi_link = BASE_URL . htmlspecialchars($fi['img_slug']);
            if (!empty($fi['img_thumb_square'])) {
                $fi_thumb = BASE_URL . ltrim($fi['img_thumb_square'], '/');
            } elseif (!empty($fi['img_file'])) {
                $pi = pathinfo($fi['img_file']);
                $fi_thumb = BASE_URL . ltrim($pi['dirname'] . '/thumbs/t_' . $pi['basename'], '/');
            } else {
                $fi_thumb = '';
            }
            $is_active = ($fi['id'] == $img['id']) ? ' active' : '';
        ?>
            <a href="<?php echo $fi_link; ?>"
               class="chap-filmstrip-item<?php echo $is_active; ?>"
               title="<?php echo htmlspecialchars($fi['img_title']); ?>">
                <img src="<?php echo $fi_thumb; ?>"
                     alt="<?php echo htmlspecialchars($fi['img_title']); ?>"
                     loading="lazy">
            </a>
        <?php endforeach; ?>
    </div>

    <!-- INFO / COMMENTS OVERLAY -->
    <div id="chap-info-overlay" class="chap-overlay">
        <div class="chap-overlay-backdrop"></div>
        <div class="chap-overlay-box">
            <div class="chap-overlay-tabs">
                <button class="chap-tab active" data-pane="info">INFO</button>
                <button class="chap-tab" data-pane="comments">SIGNALS</button>
                <button class="chap-overlay-close" title="Close">&times;</button>
            </div>
            <div class="chap-overlay-content">
                <div id="chap-pane-info" class="chap-pane active">
                    <div class="info-title-block">
                        <div class="info-title"><?php echo htmlspecialchars($img['img_title']); ?></div>
                        <div class="info-date"><?php echo date('F j, Y', strtotime($img['img_date'])); ?></div>
                    </div>
                    <div class="description">
                        <?php echo $snapsmack->parseContent($img['img_description'] ?? ''); ?>
                    </div>
                    <?php if ($exif_display_enabled ?? true): ?>
                    <div class="meta">
                        <div class="meta-header">TECHNICAL SPECIFICATIONS</div>
                        <table class="exif-table">
                            <?php
                            $labels = [
                                'Model' => 'Model', 'lens' => 'Lens',
                                'FNumber' => 'Aperture', 'ExposureTime' => 'Shutter',
                                'ISOSpeedRatings' => 'ISO', 'FocalLength' => 'Focal',
                                'film' => 'Film', 'flash' => 'Flash',
                            ];
                            foreach ($labels as $key => $label):
                                if (!empty($exif_data[$key]) && $exif_data[$key] !== 'N/A'): ?>
                                    <tr>
                                        <td class="exif-label"><?php echo $label; ?></td>
                                        <td class="exif-value"><?php echo htmlspecialchars($exif_data[$key]); ?></td>
                                    </tr>
                                <?php endif;
                            endforeach; ?>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <div id="chap-pane-comments" class="chap-pane">
                    <?php include dirname(__DIR__, 2) . '/core/community-component.php'; ?>
                </div>
            </div>
        </div>
    </div>

    <?php include dirname(__DIR__, 2) . '/core/community-dock.php'; ?>
    <?php include __DIR__ . '/skin-footer.php'; ?>
</div>
<?php include __DIR__ . '/../../core/footer-scripts.php'; ?>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
