<?php
/**
 * SNAPSMACK - Main layout template for the pocket-rocket skin
 * Alpha v0.7.2
 *
 * Single transmission page. Photo, title bar, prev/next nav, two drawer
 * toggles (INFO / SIGNALS), info drawer slides down, signals drawer slides up.
 * Flat structure — no nested wrappers, no inline styles.
 */
require_once dirname(__DIR__, 2) . '/core/layout_logic.php';


?>

<?php include __DIR__ . '/skin-header.php'; ?>

<div class="po-transmission">
    <div class="po-wrap">

        <!-- PHOTO -->
        <div class="po-photo-wrap">
            <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
            <img src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>"
                 alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                 id="main-image">
            <?php echo $download_button; ?>
        </div>

        <!-- TITLE BAR -->
        <div class="po-title-bar">
            <h2><?php echo htmlspecialchars($img['img_title']); ?></h2>
            <span class="po-title-date"><?php echo date('Y-m-d', strtotime($img['img_date'])); ?></span>
        </div>

        <!-- PREV / NEXT NAV -->
        <div class="po-nav">
            <?php if ($prev_slug): ?>
                <a href="<?php echo $prev_slug; ?>">&larr; PREV</a>
            <?php else: ?>
                <a class="disabled">&larr; PREV</a>
            <?php endif; ?>

            <?php if ($next_slug): ?>
                <a href="<?php echo $next_slug; ?>">&rarr; NEXT</a>
            <?php else: ?>
                <a class="disabled">&rarr; NEXT</a>
            <?php endif; ?>
        </div>

        <!-- DRAWER TOGGLES -->
        <div class="po-drawer-toggle">
            <button class="po-drawer-btn" onclick="poToggleDrawer('info')">INFO</button>
            <button class="po-drawer-btn" onclick="poToggleDrawer('signals')">SIGNALS</button>
        </div>

        <!-- INFO DRAWER (slides down) -->
        <div id="po-info-drawer" class="po-info-drawer">
            <div class="po-info-content">
                <?php if (!empty($img['img_description'])): ?>
                    <div class="po-description">
                        <?php echo $snapsmack->parseContent($img['img_description']); ?>
                    </div>
                <?php endif; ?>

                <?php
                $labels = [
                    'Model' => 'Camera', 'lens' => 'Lens', 'FNumber' => 'Aperture',
                    'ExposureTime' => 'Shutter', 'ISOSpeedRatings' => 'ISO',
                    'FocalLength' => 'Focal', 'film' => 'Film', 'flash' => 'Flash'
                ];
                $has_exif = false;
                foreach ($labels as $key => $label) {
                    if (!empty($exif_data[$key]) && $exif_data[$key] !== 'N/A') {
                        $has_exif = true;
                        break;
                    }
                }
                ?>
                <?php if ($has_exif): ?>
                    <div class="po-exif">
                        <div class="po-exif-title">SPECS</div>
                        <?php foreach ($labels as $key => $label): ?>
                            <?php if (!empty($exif_data[$key]) && $exif_data[$key] !== 'N/A'): ?>
                                <div class="po-exif-row">
                                    <span class="po-exif-label"><?php echo $label; ?></span>
                                    <span class="po-exif-value"><?php echo htmlspecialchars($exif_data[$key]); ?></span>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- SIGNALS DRAWER (slides up) -->
        <div id="po-signals-drawer" class="po-signals-drawer">
            <div class="po-signals-content">
                <?php include dirname(__DIR__, 2) . '/core/community-component.php'; ?>
            </div>
        </div>

    </div>
</div>

<?php include dirname(__DIR__, 2) . '/core/community-dock.php'; ?>
<?php include __DIR__ . '/skin-footer.php'; ?>
