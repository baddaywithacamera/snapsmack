<?php
/**
 * SNAPSMACK - Main layout template for the true-grit skin
 * Alpha v0.7.8
 *
 * Renders the photo display, navigation, metadata, and comments sections.
 * Based on 50 Shades chassis with tg- namespace.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */



require_once dirname(__DIR__, 2) . '/core/layout-logic.php';
require_once dirname(__DIR__, 2) . '/core/snap-tags.php';

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
        <?php include dirname(__DIR__, 2) . '/core/navigation-bar.php'; ?>
    </div>

    <div id="footer">
        <div id="pane-info" class="footer-pane">
            <h2 class="photo-title-footer"><?php echo htmlspecialchars($img['img_title']); ?></h2>
            <div class="description">
                <?php echo $snapsmack->parseContent($img['img_description'] ?? ''); ?>
            </div>

            <?php
            // ── Hashtags ──────────────────────────────────────────────
            $image_tags = snap_get_tags($pdo, (int)$img['id']);
            if (!empty($image_tags)):
            ?>
            <div class="tg-tags">
                <?php foreach ($image_tags as $t): ?>
                    <a href="<?php echo BASE_URL . '?tag=' . rawurlencode($t['slug']); ?>" class="tg-tag">#<?php echo htmlspecialchars($t['slug']); ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

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
<?php // ===== SNAPSMACK EOF =====
