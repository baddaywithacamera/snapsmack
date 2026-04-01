<?php
/**
 * SNAPSMACK - 50 Shades of Noah Grey static page template
 * Alpha v0.7.7
 *
 * Renders static pages (page.php) inside the 50 Shades shell.
 * Hero image is capped to 55vh with object-fit:cover so it doesn't
 * swallow the viewport. Content flows in the standard static-content
 * column that matches the archive width.
 */

include __DIR__ . '/skin-meta.php';
?>
<body class="static-transmission">
<div id="page-wrapper">
<div id="scroll-stage">

    <?php include __DIR__ . '/skin-header.php'; ?>

    <?php if (!empty($page_data['image_asset'])): ?>
        <div id="photobox" class="page-hero">
            <div class="main-photo">
                <img src="<?php echo BASE_URL . ltrim($page_data['image_asset'], '/'); ?>"
                     class="post-image"
                     alt="<?php echo $page_title; ?>">
            </div>
        </div>
    <?php endif; ?>

    <div class="static-content">
        <h1 class="static-page-title"><?php echo $page_title; ?></h1>

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
</body>
</html>
