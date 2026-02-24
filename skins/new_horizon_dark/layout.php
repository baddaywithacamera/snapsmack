<?php
/**
 * SnapSmack - Layout Controller
 * Version: 3.7 - Unified UI Integration
 * -------------------------------------------------------------------------
 * - FIXED: Script pointer moved to assets/js/smack-ui-public.js
 * - FIXED: Kept empty comment containers in DOM to prevent JS "null" errors.
 * - FIXED: Both Info and Comment toggles restored via Centralized Engine.
 * -------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/core/layout_logic.php';

// --- DOUBLE-LOCK SECURITY CHECK ---
$global_on = (($settings['global_comments_enabled'] ?? '1') == '1');
$post_on   = (($img['allow_comments'] ?? '1') == '1');
$comments_active = ($global_on && $post_on);
?>

<div id="scroll-stage">

    <?php include('header.php'); ?>

    <div id="photobox">
        <div class="main-photo">
            <img src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>" 
                 alt="<?php echo htmlspecialchars($img['img_title']); ?>" 
                 class="post-image">
        </div>
    </div>

    <div id="infobox">
        <?php 
        /* The navigation bar handles its own link logic */
        include dirname(__DIR__, 2) . '/core/navigation_bar.php'; 
        ?>
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
                                    <div style="font-size:0.7rem; color:#444; margin-top:5px;">
                                        [<?php echo date('Y-m-d', strtotime($c['comment_date'])); ?>]
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php else: ?>
                    <div class="description" style="text-align:center; color:#444;">NO SIGNALS RECORDED.</div>
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

    <?php include('footer.php'); ?>

</div>