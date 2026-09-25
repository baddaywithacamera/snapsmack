<?php
/**
 * SNAPSMACK - Galleria static page template
 * Alpha v0.7.8
 *
 * Renders static pages (page.php) inside the Galleria shell: wall background,
 * sticky header, hero image wrapped in the full frame system, proper content
 * column with gutters. Replaces the generic page.php template entirely.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */




// Skin-level frame settings (from compiled custom_css_public / manifest defaults)
$frame_color = $settings['htbs_frame_color'] ?? '#2c2017';
$frame_width = $settings['htbs_frame_width'] ?? '8';
$mat_color   = $settings['htbs_mat_color']   ?? '#f5f0eb';
$mat_width   = $settings['htbs_mat_width']   ?? '24';

// Derive inline root overrides for this page's frame
$frame_vars = implode('', [
    "--frame-color:{$frame_color};",
    "--frame-width:{$frame_width}px;",
    "--mat-color:{$mat_color};",
    "--mat-width:{$mat_width}px;",
]);

include __DIR__ . '/skin-meta.php';
?>
<body class="static-transmission htbs-page">
<div id="page-wrapper">
    <div id="scroll-stage" class="htbs-page-stage" style="<?php echo $frame_vars; ?>">

        <?php include __DIR__ . '/skin-header.php'; ?>

        <div class="htbs-page-content">
            <h1 class="static-page-title"><?php echo $page_title; ?></h1>

            <?php if (!empty($page_data['image_asset'])): ?>
                <div class="htbs-page-hero">
                    <div class="frame-mount">
                        <div class="frame-border">
                            <div class="frame-mat">
                                <div class="frame-bevel">
                                    <div class="frame-image">
                                        <img src="<?php echo BASE_URL . ltrim($page_data['image_asset'], '/'); ?>"
                                             class="post-image"
                                             alt="<?php echo $page_title; ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
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
<?php include __DIR__ . '/../../core/footer-scripts.php'; ?>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
