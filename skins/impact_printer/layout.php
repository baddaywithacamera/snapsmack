<?php
/**
 * Impact Printer — Layout Controller
 * Version: 1.0
 * -------------------------------------------------------------------------
 * ip- prefixed selectors. DotMatrix typography. ASCII border frame.
 * The .ip-ascii-frame wraps the image with data-border-style attribute.
 * The JS engine (ss-engine-ascii-borders.js) measures the image and
 * generates the correct number of characters for top/bottom/left/right.
 * -------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/core/layout_logic.php';

// --- DOUBLE-LOCK SECURITY CHECK ---
$global_on = (($settings['global_comments_enabled'] ?? '1') == '1');
$post_on   = (($img['allow_comments'] ?? '1') == '1');
$comments_active = ($global_on && $post_on);

// --- ASCII BORDER STYLE ---
// Read from settings (set via skin admin). Falls back to 'box'.
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
        <div id="pane-info" class="footer-pane" style="display:none;">
            <h2 class="photo-title-footer"><?php echo htmlspecialchars($img['img_title']); ?></h2>
            <div class="description">
                <?php echo $snapsmack->parseContent($img['img_description'] ?? ''); ?>
            </div>
            
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
        </div>

        <div id="pane-comments" class="footer-pane" style="display:none;">
            <?php if ($comments_active): ?>
                
                <div class="meta-header" style="margin-bottom: 40px; text-align:center;">TRANSMISSIONS</div>
                
                <?php if ($comments): ?>
                    <table class="exif-table" style="width:100%; margin-bottom:40px;">
                        <?php foreach($comments as $c): ?>
                            <tr>
                                <td class="exif-label" style="vertical-align:top; width:120px;">
                                    <?php echo htmlspecialchars($c['comment_author']); ?>
                                </td>
                                <td class="exif-value">
                                    <?php echo nl2br(htmlspecialchars($c['comment_text'])); ?>
                                    <div style="font-size:0.7rem; color:var(--text-dim); margin-top:5px;">
                                        [<?php echo date('Y-m-d', strtotime($c['comment_date'])); ?>]
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <div class="description" style="text-align:center; color:var(--text-dim);">NO SIGNALS RECORDED.</div>
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
