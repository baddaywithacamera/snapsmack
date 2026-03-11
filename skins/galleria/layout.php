<?php
require_once dirname(__DIR__, 2) . '/core/layout_logic.php';

// Per-image frame overrides
$display_opts = [];
if (!empty($img['img_display_options'])) {
    $display_opts = json_decode($img['img_display_options'], true) ?? [];
}
$frame_color = $display_opts['frame_color'] ?? null;
$frame_width = $display_opts['frame_width'] ?? null;
$mat_color = $display_opts['mat_color'] ?? null;
$mat_width = $display_opts['mat_width'] ?? null;
$bevel = $display_opts['bevel'] ?? null;

// Build inline style overrides
$frame_style = '';
if ($frame_color) $frame_style .= "--frame-color:{$frame_color};";
if ($frame_width) $frame_style .= "--frame-width:{$frame_width}px;";
if ($mat_color) $frame_style .= "--mat-color:{$mat_color};";
if ($mat_width) $frame_style .= "--mat-width:{$mat_width}px;";
if ($bevel) $frame_style .= "--bevel-style:{$bevel};";


?>

<div id="scroll-stage" class="htbs-single">

    <?php include('skin-header.php'); ?>

    <div class="htbs-gallery-room">
        <div class="frame-mount"<?php echo $frame_style ? " style=\"{$frame_style}\"" : ''; ?>>
            <div class="frame-border">
                <div class="frame-mat">
                    <div class="frame-bevel">
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
        </div>

    </div>

    <div id="infobox">
        <?php include dirname(__DIR__, 2) . '/core/navigation_bar.php'; ?>
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
            <div class="htbs-overlay-content">

                <!-- INFO pane -->
                <div id="htbs-pane-info" class="htbs-pane active">
                    <div class="info-title-block">
                        <div class="info-title"><?php echo htmlspecialchars($img['img_title']); ?></div>
                        <div class="info-date"><?php echo date('F j, Y', strtotime($img['img_date'])); ?></div>
                    </div>
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

                <!-- SIGNALS pane -->
                <div id="htbs-pane-comments" class="htbs-pane">
                    <?php include dirname(__DIR__, 2) . '/core/community-component.php'; ?>
                </div>

            </div>
        </div>
    </div>

    <?php
    // --- FILMSTRIP ---
    // Horizontal scrollable strip of square thumbnails at bottom of single view.
    $show_filmstrip = ($settings['htbs_show_filmstrip'] ?? '1') === '1';
    if ($show_filmstrip):
        $now_local = date('Y-m-d H:i:s');
        $film_stmt = $pdo->prepare("
            SELECT id, img_slug, img_file, img_thumb_square, img_title
            FROM snap_images
            WHERE img_status = 'published' AND img_date <= ?
            ORDER BY img_date DESC
            LIMIT 50
        ");
        $film_stmt->execute([$now_local]);
        $filmstrip_images = $film_stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="htbs-filmstrip">
        <?php foreach ($filmstrip_images as $fi):
            $fi_link = BASE_URL . htmlspecialchars($fi['img_slug']);
            // Prefer DB thumb path; fall back to constructing from img_file
            if (!empty($fi['img_thumb_square'])) {
                $fi_thumb = BASE_URL . ltrim($fi['img_thumb_square'], '/');
            } elseif (!empty($fi['img_file'])) {
                $fi_path = pathinfo($fi['img_file']);
                $fi_thumb = BASE_URL . ltrim($fi_path['dirname'] . '/thumbs/t_' . $fi_path['basename'], '/');
            } else {
                $fi_thumb = '';
            }
            $is_active = ($fi['id'] == $img['id']) ? ' active' : '';
        ?>
            <a href="<?php echo $fi_link; ?>" class="htbs-filmstrip-item<?php echo $is_active; ?>" title="<?php echo htmlspecialchars($fi['img_title']); ?>">
                <img src="<?php echo $fi_thumb; ?>" alt="<?php echo htmlspecialchars($fi['img_title']); ?>" loading="lazy">
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php include dirname(__DIR__, 2) . '/core/community-dock.php'; ?>
    <?php include('skin-footer.php'); ?>
</div>

<script>
/**
 * Galleria — Center Overlay Controller
 * Info and Signals appear in an elegant box that expands from center.
 * Bypasses ss-engine-footer.js (no #footer element).
 * Same hotkeys (1/2) work via smackdown API bridge.
 */
document.addEventListener('DOMContentLoaded', function() {

    // --- Filmstrip auto-scroll ---
    var active = document.querySelector('.htbs-filmstrip-item.active');
    if (active) active.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });

    // --- Overlay elements ---
    var overlay  = document.getElementById('htbs-info-overlay');
    var backdrop = overlay ? overlay.querySelector('.htbs-overlay-backdrop') : null;
    var closeBtn = overlay ? overlay.querySelector('.htbs-overlay-close') : null;
    var tabs     = overlay ? overlay.querySelectorAll('.htbs-tab') : [];
    var panes    = overlay ? overlay.querySelectorAll('.htbs-pane') : [];
    var btnInfo  = document.getElementById('show-details');
    var btnComm  = document.getElementById('show-comments');

    function showPane(name) {
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('active', tabs[i].getAttribute('data-pane') === name);
        }
        for (var j = 0; j < panes.length; j++) {
            panes[j].classList.toggle('active', panes[j].id === 'htbs-pane-' + name);
        }
    }

    function openOverlay(pane) {
        if (!overlay) return;
        showPane(pane);
        overlay.classList.add('open');
    }

    function closeOverlay() {
        if (!overlay) return;
        overlay.classList.remove('open');
    }

    function isOpen() {
        return overlay && overlay.classList.contains('open');
    }

    function activePane() {
        for (var i = 0; i < tabs.length; i++) {
            if (tabs[i].classList.contains('active')) return tabs[i].getAttribute('data-pane');
        }
        return null;
    }

    // --- Tab clicks ---
    for (var i = 0; i < tabs.length; i++) {
        tabs[i].addEventListener('click', function() {
            var pane = this.getAttribute('data-pane');
            if (pane) showPane(pane);
        });
    }

    // --- Close triggers ---
    if (closeBtn) closeBtn.addEventListener('click', closeOverlay);
    if (backdrop) backdrop.addEventListener('click', closeOverlay);

    // --- Nav bar button intercepts ---
    if (btnInfo) {
        btnInfo.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (isOpen() && activePane() === 'info') { closeOverlay(); }
            else { openOverlay('info'); }
        });
    }

    if (btnComm) {
        btnComm.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            if (isOpen() && activePane() === 'comments') { closeOverlay(); }
            else { openOverlay('comments'); }
        });
    }

    // --- Smackdown API bridge (keyboard engine compat) ---
    window.smackdown = window.smackdown || {};
    window.smackdown.toggleFooter = function(target, e) {
        if (e) e.preventDefault();
        if (target === 'info') {
            if (isOpen() && activePane() === 'info') closeOverlay();
            else openOverlay('info');
        } else if (target === 'comments') {
            if (isOpen() && activePane() === 'comments') closeOverlay();
            else openOverlay('comments');
        }
    };
    window.smackdown.closeFooter = closeOverlay;
});
</script>
