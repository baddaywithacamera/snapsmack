<?php
/**
 * SNAPSMACK - Rational Geo Single Image View
 * v1.1
 *
 * Magazine-page feel. Standard #footer / #pane-info / #pane-comments pattern,
 * same as 50-shades-of-noah-grey. Driven by smack-footer (ss-engine-footer.js).
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */



require_once dirname(__DIR__, 2) . '/core/layout-logic.php';

$global_on = (($settings['global_comments_enabled'] ?? '1') == '1');
$post_on   = (($img['allow_comments'] ?? '1') == '1');
$comments_active = ($global_on && $post_on);

$show_desc = ($settings['single_show_description'] ?? '1') === '1';
$show_map_bg = ($settings['show_map_background'] ?? '1') === '1';

// Border config
$border_colors = [
    'yellow' => '#FFCC00',
    'white'  => '#ffffff',
    'black'  => '#000000',
    'grey'   => '#808080',
    'none'   => 'transparent'
];
$bc = $settings['image_border_color'] ?? 'yellow';
$border_val = $border_colors[$bc] ?? '#FFCC00';
$hero_bw = (int)($settings['hero_border_width'] ?? '20');

// EXIF labels
$exif_labels = [
    'Model' => 'Camera', 'lens' => 'Lens', 'FNumber' => 'Aperture',
    'ExposureTime' => 'Shutter', 'ISOSpeedRatings' => 'ISO',
    'FocalLength' => 'Focal Length', 'film' => 'Film', 'flash' => 'Flash'
];
?>

<div id="scroll-stage" class="rg-single">

    <?php include('skin-header.php'); ?>

    <?php include dirname(__DIR__, 2) . '/core/community-dock.php'; ?>

    <!-- PHOTOBOX -->
    <div id="rg-photobox">
        <div class="rg-photo-wrap">
            <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
            <img class="rg-image post-image"
                 src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>"
                 alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                 style="border: var(--rg-hero-inner, 4px) solid #ffffff; outline: <?php echo $hero_bw; ?>px solid <?php echo htmlspecialchars($border_val); ?>;">
            <?php echo $download_button; ?>
        </div>
    </div>

    <!-- INFOBOX (core navigation bar) — in-flow, flex-shrink: 0 -->
    <div id="infobox">
        <?php include dirname(__DIR__, 2) . '/core/navigation-bar.php'; ?>
    </div>

    <!-- FOOTER — standard ss-engine-footer.js pattern (same as 50-shades) -->
    <div id="footer">
        <div id="pane-info" class="footer-pane">
            <div class="rg-drawer-inner">
                <h2 class="rg-photo-title"><?php echo htmlspecialchars($img['img_title']); ?></h2>
                <div class="rg-photo-date"><?php echo date('F j, Y', strtotime($img['img_date'])); ?></div>

                <?php if ($show_desc && !empty($img['img_description'])): ?>
                    <div class="rg-description">
                        <?php echo $snapsmack->parseContent($img['img_description']); ?>
                    </div>
                <?php endif; ?>

                <?php if ($exif_display_enabled ?? true): ?>
                    <div class="rg-exif-section">
                        <h4>Technical Details</h4>
                        <table class="rg-exif-table">
                            <tbody>
                                <?php foreach ($exif_labels as $key => $label): ?>
                                    <?php if (!empty($exif_data[$key]) && $exif_data[$key] !== 'N/A'): ?>
                                        <tr>
                                            <td><?php echo $label; ?></td>
                                            <td><?php echo htmlspecialchars($exif_data[$key]); ?></td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div id="pane-comments" class="footer-pane">
            <div class="rg-drawer-inner">
                <?php include dirname(__DIR__, 2) . '/core/community-component.php'; ?>
            </div>
        </div>
    </div>

    <?php include('skin-footer.php'); ?>
</div>
<?php // ===== SNAPSMACK EOF =====
