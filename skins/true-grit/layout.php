<?php
/**
 * SNAPSMACK - Main layout template for the true-grit skin
 * Alpha v0.7.3a
 *
 * Renders the photo display, navigation, metadata, and comments sections.
 * Based on 50 Shades chassis with tg- namespace.
 */
require_once dirname(__DIR__, 2) . '/core/layout_logic.php';

?>

<div id="scroll-stage">

    <?php include('skin-header.php'); ?>

    <div id="tg-photobox">
        <div class="tg-photo-wrap">
            <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
            <img src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>"
                 alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                 class="tg-image post-image"
                 id="main-image">
            <?php echo $download_button; ?>
        </div>
    </div>

    <div id="infobox">
        <?php include dirname(__DIR__, 2) . '/core/navigation_bar.php'; ?>
    </div>

    <div id="footer">
        <div id="pane-info" class="footer-pane">
            <h2 class="photo-title-footer"><?php echo htmlspecialchars($img['img_title']); ?></h2>
            <div class="description">
                <?php echo $snapsmack->parseContent($img['img_description'] ?? ''); ?>
            </div>

            <?php if ($exif_display_enabled ?? true): ?>
            <div class="meta">
                <div class="meta-header">TECHNICAL SPECIFICATIONS</div>
                <table class="exif-table">
                    <?php
                    $labels = [
                        'Model' => 'Model', 'lens' => 'Lens', 'FNumber' => 'Aperture',
                        'ExposureTime' => 'Shutter', 'ISOSpeedRatings' => 'ISO',
                        'FocalLength' => 'Focal', 'film' => 'Film', 'flash' => 'Flash'
                    ];
                    foreach($labels as $key => $label): ?>
                        <?php if(!empty($exif_data[$key]) && $exif_data[$key] !== 'N/A'): ?>
                            <tr>
                                <td class="exif-label"><?php echo $label; ?></td>
                                <td class="exif-value"><?php echo htmlspecialchars($exif_data[$key]); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <div id="pane-comments" class="footer-pane">
            <?php include dirname(__DIR__, 2) . '/core/community-component.php'; ?>
        </div>
    </div>

    <?php include dirname(__DIR__, 2) . '/core/community-dock.php'; ?>
    <?php include('skin-footer.php'); ?>

</div>
