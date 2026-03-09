<?php
/**
 * SNAPSMACK - Main layout template for the impact-printer skin
 * Alpha v0.6
 *
 * Renders the photo display with ASCII frame borders, navigation, metadata, and comments sections.
 */
require_once dirname(__DIR__, 2) . '/core/layout_logic.php';

// Check if comments are enabled globally and for this specific post
$global_on = (($settings['global_comments_enabled'] ?? '1') == '1');
$post_on   = (($img['allow_comments'] ?? '1') == '1');
$comments_active = ($global_on && $post_on);

// Determine the ASCII border style from settings
$border_style = $settings['image_frame_style'] ?? 'box';
?>

<div id="scroll-stage">

    <?php include('skin-header.php'); ?>

    <div id="ip-photobox">
        <div class="ip-photo-wrap">
            <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>

            <div class="ip-ascii-frame" data-border-style="<?php echo htmlspecialchars($border_style); ?>">
                <span class="ip-border-left"></span>
                <div class="ip-ascii-frame-inner">
                    <img src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>" 
                         alt="<?php echo htmlspecialchars($img['img_title']); ?>" 
                         class="ip-image post-image"
                         id="main-image">
                </div>
                <span class="ip-border-right"></span>
            </div>

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
            <?php if ($comments_active): ?>

                <div class="meta-header signals-header">SIGNALS</div>

                <?php if ($comments): ?>
                    <table class="exif-table signals-table">
                        <?php foreach($comments as $c): ?>
                            <tr>
                                <td class="exif-label">
                                    <?php echo htmlspecialchars($c['comment_author']); ?>
                                </td>
                                <td class="exif-value">
                                    <?php echo nl2br(htmlspecialchars($c['comment_text'])); ?>
                                    <div class="signal-date">
                                        [<?php echo date('Y-m-d', strtotime($c['comment_date'])); ?>]
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <div class="description signals-empty">NO SIGNALS RECORDED.</div>
                <?php endif; ?>

                <form action="<?php echo BASE_URL; ?>process-comment.php" method="POST" class="comment-form">
                    <input type="hidden" name="img_id" value="<?php echo $img['id']; ?>">
                    <div class="form-row">
                        <input type="text" name="author" placeholder="CALLSIGN" required>
                        <input type="email" name="email" placeholder="EMAIL" required>
                    </div>
                    <textarea name="comment_text" placeholder="MESSAGE..." required></textarea>
                    <button type="submit">TRANSMIT</button>
                </form>

            <?php endif; ?>
        </div>
    </div>

    <?php include('skin-footer.php'); ?>

</div>
