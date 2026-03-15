<?php
/**
 * SNAPSMACK - Single image layout for the a-grey-reckoning skin
 * Alpha v0.7.4
 *
 * Recreates the greyexpectations.com photolog format: title bar with
 * site name left / date right, centred photograph, caption below,
 * and a navigation row at the bottom. Everything is quiet and precise.
 *
 * Variables available from index.php: $pdo, $settings, $img, $active_skin, $site_name
 */
require_once dirname(__DIR__, 2) . '/core/layout_logic.php';

$site_display_name = $site_name ?? 'SNAPSMACK';

// Format date in the style Noah used: "Monday, March 14, 2026"
$img_date = $img['img_date'] ?? '';
$formatted_date = '';
if (!empty($img_date)) {
    $ts = strtotime($img_date);
    if ($ts !== false) {
        $formatted_date = strtoupper(date('l, F j, Y', $ts));
    }
}

// Comments visibility
$global_on = (($settings['global_comments_enabled'] ?? '1') == '1');
$post_on   = (($img['allow_comments'] ?? '1') == '1');
$show_comments = ($global_on && $post_on);
?>

<div id="scroll-stage">

    <?php include('skin-header.php'); ?>

    <div class="ge-canvas">

        <!-- TITLE BAR -->
        <div class="ge-title-bar">
            <span class="ge-title-text"><?php echo htmlspecialchars($site_display_name); ?></span>
            <span class="ge-title-date"><?php echo $formatted_date; ?></span>
        </div>

        <!-- PHOTOGRAPH -->
        <div class="ge-photo">
            <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
            <img src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>"
                 alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                 class="post-image"
                 id="main-image">
            <?php echo $download_button; ?>
        </div>

        <!-- CAPTION -->
        <?php if (!empty($img['img_description'])): ?>
        <div class="ge-caption">
            <?php echo $snapsmack->parseContent($img['img_description']); ?>
        </div>
        <?php endif; ?>

        <!-- NAVIGATION ROW -->
        <div class="ge-photo-nav">
            <?php if (!empty($prev_slug)): ?>
                <a href="<?php echo $prev_slug; ?>">BACK</a>
            <?php else: ?>
                <span class="dim">BACK</span>
            <?php endif; ?>

            <span class="sep">&middot;</span>

            <a href="#" id="show-details">INFO</a>

            <?php if ($show_comments): ?>
                <span class="sep">&middot;</span>
                <a href="#" id="show-comments">COMMENTS (<?php echo count($comments); ?>)</a>
            <?php endif; ?>

            <span class="sep">&middot;</span>

            <a href="<?php echo BASE_URL; ?>archive.php">ARCHIVES</a>

            <span class="sep">&middot;</span>

            <a href="<?php echo BASE_URL; ?>feed">RSS</a>

            <?php if (!empty($next_slug)): ?>
                <span class="sep">&middot;</span>
                <a href="<?php echo $next_slug; ?>">NEXT</a>
            <?php else: ?>
                <span class="sep">&middot;</span>
                <span class="dim">NEXT</span>
            <?php endif; ?>
        </div>

    </div>

    <!-- FOOTER PANES (INFO / COMMENTS) -->
    <div class="ge-canvas">
        <div class="ge-footer-content">
            <div id="pane-info" class="footer-pane">
                <h2 class="photo-title-footer"><?php echo htmlspecialchars($img['img_title']); ?></h2>
                <div class="description">
                    <?php echo $snapsmack->parseContent($img['img_description'] ?? ''); ?>
                </div>

                <?php if ($exif_display_enabled ?? true): ?>
                <div class="ge-exif">
                    <table>
                        <?php
                        $labels = [
                            'Model' => 'Camera', 'lens' => 'Lens', 'FNumber' => 'Aperture',
                            'ExposureTime' => 'Shutter', 'ISOSpeedRatings' => 'ISO',
                            'FocalLength' => 'Focal', 'film' => 'Film', 'flash' => 'Flash'
                        ];
                        foreach ($labels as $key => $label): ?>
                            <?php if (!empty($exif_data[$key]) && $exif_data[$key] !== 'N/A'): ?>
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
    </div>

    <?php include dirname(__DIR__, 2) . '/core/community-dock.php'; ?>
    <?php include('skin-footer.php'); ?>

</div>
