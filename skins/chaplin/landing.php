<?php
/**
 * SNAPSMACK - Chaplin skin: landing page
 *
 * Most recent published photo, framed identically to the solo view.
 * Same #rg-photobox / chap-img-frame / frame-deco structure as layout.php.
 * Clicking the image links to the solo post page.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$now_local = date('Y-m-d H:i:s');
$stmt = $pdo->prepare("
    SELECT id, img_title, img_slug, img_file, img_date
    FROM snap_images
    WHERE img_status = 'published' AND img_date <= ?
    ORDER BY sort_order ASC, img_date DESC
    LIMIT 1
");
$stmt->execute([$now_local]);
$hero = $stmt->fetch(PDO::FETCH_ASSOC);

?>
<canvas id="chap-film-bg" aria-hidden="true"></canvas>
<div id="scroll-stage" class="chap-landing">

    <?php include __DIR__ . '/skin-header.php'; ?>

    <?php if ($hero): ?>

    <div id="rg-photobox">
        <div class="rg-photo-wrap">
            <a href="<?php echo BASE_URL . htmlspecialchars($hero['img_slug']); ?>"
               class="chap-landing-link"
               title="<?php echo htmlspecialchars($hero['img_title']); ?>">
                <div class="chap-img-frame">
                    <?php include __DIR__ . '/frame-deco.php'; ?>
                    <img class="rg-image post-image chap-photo"
                         src="<?php echo BASE_URL . ltrim($hero['img_file'], '/'); ?>"
                         alt="<?php echo htmlspecialchars($hero['img_title']); ?>"
                         loading="eager">
                </div>
            </a>
        </div>
    </div>

    <div class="chap-intertitle">
        <div class="chap-intertitle-rule"></div>
        <div class="chap-intertitle-title"><?php echo htmlspecialchars($hero['img_title']); ?></div>
        <div class="chap-intertitle-date"><?php echo date('F j, Y', strtotime($hero['img_date'])); ?></div>
        <div class="chap-intertitle-rule"></div>
    </div>

    <?php else: ?>

    <div id="rg-photobox" class="chap-photobox-empty">
        <p class="chap-no-photos">The projector is dark.</p>
    </div>

    <?php endif; ?>

    <?php include __DIR__ . '/skin-footer.php'; ?>

</div>
<?php // ===== SNAPSMACK EOF =====
