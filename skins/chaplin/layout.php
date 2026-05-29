<?php
/**
 * SNAPSMACK - Chaplin skin: single image view
 * v2.5
 *
 * Rational Geo base + Art Deco border overlay, intertitle overlay.
 * No Galleria structure. No filmstrip. Border on .chap-photo via skin-header.php CSS vars.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once dirname(__DIR__, 2) . '/core/layout-logic.php';

$show_desc   = ($settings['single_show_description'] ?? '1') === '1';
$title_pos   = $settings['chap_title_position'] ?? 'below_photo';

// EXIF labels
$exif_labels = [
    'Model'           => 'Camera',
    'lens'            => 'Lens',
    'FNumber'         => 'Aperture',
    'ExposureTime'    => 'Shutter',
    'ISOSpeedRatings' => 'ISO',
    'FocalLength'     => 'Focal Length',
    'film'            => 'Film',
    'flash'           => 'Flash',
];

?>
<canvas id="chap-film-bg" aria-hidden="true"></canvas>
<div id="scroll-stage" class="rg-single chap-single">

    <?php include __DIR__ . '/skin-header.php'; ?>

    <?php include dirname(__DIR__, 2) . '/core/community-dock.php'; ?>

    <!-- INTERTITLE OVERLAY — full-screen black fade modal -->
    <div id="chap-comments-drawer" class="chap-overlay-drawer" aria-hidden="true">
        <div class="chap-overlay-backdrop"></div>
        <div class="chap-overlay-card">
            <div class="chap-overlay-tabs">
                <button class="chap-tab active" data-pane="info">INFO</button>
                <button class="chap-tab" data-pane="signals">SIGNALS</button>
                <button class="chap-overlay-close" aria-label="Close">&times;</button>
            </div>
            <div class="chap-overlay-content">
                <div id="chap-pane-info" class="chap-pane active">
                    <div class="chap-info-title"><?php echo htmlspecialchars($img['img_title']); ?></div>
                    <div class="chap-info-date"><?php echo date('F j, Y', strtotime($img['img_date'])); ?></div>
                    <?php if ($show_desc && !empty($img['img_description'])): ?>
                    <div class="chap-description">
                        <?php echo $snapsmack->parseContent($img['img_description']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($exif_display_enabled ?? true): ?>
                    <div class="chap-exif-section">
                        <h4>Technical Details</h4>
                        <table class="chap-exif-table"><tbody>
                            <?php foreach ($exif_labels as $key => $label): ?>
                                <?php if (!empty($exif_data[$key]) && $exif_data[$key] !== 'N/A'): ?>
                                <tr>
                                    <td><?php echo $label; ?></td>
                                    <td><?php echo htmlspecialchars($exif_data[$key]); ?></td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody></table>
                    </div>
                    <?php endif; ?>
                </div>
                <div id="chap-pane-signals" class="chap-pane">
                    <?php include dirname(__DIR__, 2) . '/core/community-component.php'; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- PHOTOBOX -->
    <div id="rg-photobox">
        <div class="rg-photo-wrap">
            <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
            <div class="chap-img-frame">
                <?php include __DIR__ . '/frame-lines.php'; ?>
                <?php include __DIR__ . '/frame-deco.php'; ?>
                <img class="rg-image post-image chap-photo"
                     src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>"
                     alt="<?php echo htmlspecialchars($img['img_title']); ?>">
            </div>
            <?php echo $download_button; ?>
        </div>
    </div>

    <!-- TITLE (below photo, unless moved to info tray or hidden) -->
    <?php if ($title_pos === 'below_photo'): ?>
    <div class="chap-intertitle">
        <div class="chap-intertitle-rule"></div>
        <div class="chap-intertitle-title"><?php echo htmlspecialchars($img['img_title']); ?></div>
        <div class="chap-intertitle-date"><?php echo date('F j, Y', strtotime($img['img_date'])); ?></div>
        <div class="chap-intertitle-rule"></div>
    </div>
    <?php endif; ?>

    <!-- INFOBOX (core navigation bar) -->
    <div id="infobox">
        <?php include dirname(__DIR__, 2) . '/core/navigation-bar.php'; ?>
    </div>

    <!-- HIDDEN FOOTER — kept so smack-footer.js and smack-keyboard.js find
         their expected DOM elements. Never shown; overlay handles INFO/SIGNALS. -->
    <div id="footer" style="display:none!important" aria-hidden="true">
        <div id="pane-info"     class="footer-pane"></div>
        <div id="pane-comments" class="footer-pane"></div>
    </div>

    <?php include __DIR__ . '/skin-footer.php'; ?>

</div>
<?php // ===== SNAPSMACK EOF =====
