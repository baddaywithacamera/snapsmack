<?php
/**
 * Show N Tell - Single Image View
 *
 * Supports three frame modes: none, pixel, galleria.
 * Variables available from index.php: $pdo, $settings, $img, $active_skin, $site_name
 */
require_once dirname(__DIR__, 2) . '/core/layout-logic.php';

$frame_style = $settings['htbs_frame_style'] ?? 'none';
$frame_class = 'snt-frame-' . $frame_style;
?>

<?php include('skin-header.php'); ?>

<div class="snt-single">

    <div class="image-wrap <?php echo $frame_class; ?>">
        <?php if ($frame_style === 'galleria'): ?>
            <div class="frame-mount">
                <div class="frame-border">
                    <div class="frame-mat">
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
        <?php else: ?>
            <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
            <img src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>"
                 alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                 class="post-image">
            <?php echo $download_button; ?>
        <?php endif; ?>
    </div>

    <h1 class="post-title"><?php echo htmlspecialchars($img['img_title']); ?></h1>
    <div class="post-date"><?php echo date('F j, Y', strtotime($img['img_date'])); ?></div>

    <?php if (!empty($img['img_description'])): ?>
        <div class="post-description"><?php echo nl2br(htmlspecialchars($img['img_description'])); ?></div>
    <?php endif; ?>

    <div id="infobox">
        <?php include dirname(__DIR__, 2) . '/core/navigation-bar.php'; ?>
    </div>

    <!-- Center-expanding info/comments overlay -->
    <div id="htbs-info-overlay" class="htbs-overlay">
        <div class="htbs-overlay-backdrop"></div>
        <div class="htbs-overlay-box">
            <div class="htbs-overlay-tabs">
                <button class="htbs-tab active" data-pane="info">INFO</button>
                <button class="htbs-tab" data-pane="comments">SIGNALS</button>
                <button class="htbs-overlay-close" title="Close">&times;</button>
            </div>
            <div class="htbs-overlay-pane active" data-pane="info">
                <?php include dirname(__DIR__, 2) . '/core/info_block.php'; ?>
            </div>
            <div class="htbs-overlay-pane" data-pane="comments">
                <?php include dirname(__DIR__, 2) . '/core/comments_block.php'; ?>
            </div>
        </div>
    </div>

</div>

<?php include('skin-footer.php'); ?>
<?php // EOF
