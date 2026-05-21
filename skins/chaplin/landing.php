<?php
/**
 * SNAPSMACK - Chaplin skin: landing page
 *
 * Single centered framed image — most recent published photo.
 * Full-viewport presentation with Art Deco ornament frame overlay
 * and animated film background. Replaces the broken slider layout.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$now_local = date('Y-m-d H:i:s');
$stmt = $pdo->prepare("
    SELECT id, img_title, img_slug, img_file, img_thumb_square,
           img_description, img_date, img_display_options
    FROM snap_images
    WHERE img_status = 'published' AND img_date <= ?
    ORDER BY sort_order ASC, img_date DESC
    LIMIT 1
");
$stmt->execute([$now_local]);
$hero = $stmt->fetch(PDO::FETCH_ASSOC);

// Per-image frame overrides
$frame_vars = '';
if (!empty($hero['img_display_options'])) {
    $d = json_decode($hero['img_display_options'], true) ?? [];
    $parts = [];
    if (!empty($d['frame_color'])) $parts[] = "--frame-color:{$d['frame_color']}";
    if (!empty($d['frame_width'])) $parts[] = "--frame-width:{$d['frame_width']}px";
    if (!empty($d['mat_color']))   $parts[] = "--mat-color:{$d['mat_color']}";
    if (!empty($d['mat_width']))   $parts[] = "--mat-width:{$d['mat_width']}px";
    if ($parts) $frame_vars = implode(';', $parts);
}

include __DIR__ . '/skin-meta.php';
?>
<body class="chap-landing-body">
<canvas id="chap-film-bg" aria-hidden="true"></canvas>
<div id="page-wrapper">
<div id="scroll-stage" class="chap-landing">

    <?php include __DIR__ . '/skin-header.php'; ?>

    <?php if ($hero): ?>
    <div class="chap-presentation">

        <?php include __DIR__ . '/frame-deco.php'; ?>

        <div class="chap-frame-area">
            <a href="<?php echo BASE_URL . htmlspecialchars($hero['img_slug']); ?>"
               class="chap-main-link"
               title="<?php echo htmlspecialchars($hero['img_title']); ?>">
                <div class="frame-mount"<?php echo $frame_vars ? " style=\"{$frame_vars}\"" : ''; ?>>
                    <div class="frame-border">
                        <div class="frame-mat">
                            <div class="frame-bevel">
                                <div class="frame-image">
                                    <img src="<?php echo BASE_URL . ltrim($hero['img_file'], '/'); ?>"
                                         alt="<?php echo htmlspecialchars($hero['img_title']); ?>"
                                         loading="eager">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
        </div>

        <div class="chap-intertitle">
            <div class="chap-intertitle-title">
                <?php echo htmlspecialchars($hero['img_title']); ?>
            </div>
            <div class="chap-intertitle-rule"></div>
            <div class="chap-intertitle-date">
                <?php echo date('F j, Y', strtotime($hero['img_date'])); ?>
            </div>
        </div>

    </div>
    <?php else: ?>
    <div class="chap-presentation chap-presentation--empty">
        <p class="chap-no-photos">The projector is dark.</p>
    </div>
    <?php endif; ?>

    <?php include __DIR__ . '/skin-footer.php'; ?>
</div>
</div>
<?php include __DIR__ . '/../../core/footer-scripts.php'; ?>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
