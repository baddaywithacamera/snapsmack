<?php
/**
 * SNAPSMACK - 50 Shades of Noah Grey static page template
 * Alpha v0.7.9c
 *
 * Renders static pages (page.php) inside the 50 Shades shell.
 * Hero image sits inside .static-content so it is constrained to the
 * same column width as the text, not stretched full-bleed.
 */

include __DIR__ . '/skin-meta.php';
?>
<body class="static-transmission">
<div id="page-wrapper">
<div id="scroll-stage">

    <?php include __DIR__ . '/skin-header.php'; ?>

    <div class="static-content">
        <h1 class="static-page-title"><?php echo $page_title; ?></h1>

        <?php if (!empty($page_data['image_asset'])): ?>
            <div id="photobox" class="page-hero">
                <div class="main-photo">
                    <img src="<?php echo BASE_URL . ltrim($page_data['image_asset'], '/'); ?>"
                         class="post-image"
                         alt="<?php echo $page_title; ?>">
                </div>
            </div>
        <?php endif; ?>


        <div class="description">
            <?php
            if (!empty($page_data['content'])) {
                echo $snapsmack->parseContent($page_data['content']);
            } else {
                echo "<p class='dim'>No content signal found for this sector.</p>";
            }
            ?>
        </div>
    </div>

    <?php include __DIR__ . '/skin-footer.php'; ?>

</div>
</div>

<?php include dirname(__DIR__, 2) . '/core/footer-scripts.php'; ?>
</body>
</html>
// EOF
