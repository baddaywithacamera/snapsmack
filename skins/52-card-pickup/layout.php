<?php
/**
 * 52 Card Pickup - Single Image View
 *
 * Standard single-image view with title, description, EXIF, and comments.
 * Variables available from index.php: $pdo, $settings, $img, $active_skin, $site_name
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once dirname(__DIR__, 2) . '/core/layout-logic.php';
?>

<?php include('skin-header.php'); ?>

<div class="pickup-single">

    <div class="image-wrap">
        <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
        <img src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>"
             alt="<?php echo htmlspecialchars($img['img_title']); ?>"
             class="post-image">
        <?php echo $download_button; ?>
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

    <a href="<?php echo BASE_URL; ?>" class="return-link">&larr; Return to Pile</a>

</div>

<?php include('skin-footer.php'); ?>
<?php // ===== SNAPSMACK EOF =====
