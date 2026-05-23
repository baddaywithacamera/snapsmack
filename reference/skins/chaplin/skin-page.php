<?php
/**
 * SNAPSMACK - Chaplin skin: static page template
 *
 * Renders static pages inside the Chaplin shell: dark wall background,
 * sticky header, optional hero image in the frame system, content card.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


$frame_color = $settings['chap_frame_color'] ?? '#1a1410';
$frame_width = $settings['chap_frame_width'] ?? '10';
$mat_color   = $settings['chap_mat_color']   ?? '#f5efdf';
$mat_width   = $settings['chap_mat_width']   ?? '28';

$frame_vars = implode('', [
    "--frame-color:{$frame_color};",
    "--frame-width:{$frame_width}px;",
    "--mat-color:{$mat_color};",
    "--mat-width:{$mat_width}px;",
]);

include __DIR__ . '/skin-meta.php';
?>
<body class="static-transmission chap-page">
<div id="page-wrapper">
    <div id="scroll-stage" class="chap-page-stage" style="<?php echo $frame_vars; ?>">

        <?php include __DIR__ . '/skin-header.php'; ?>

        <div class="chap-page-content">
            <h1 class="static-page-title"><?php echo $page_title; ?></h1>

            <?php if (!empty($page_data['image_asset'])): ?>
                <div class="chap-page-hero">
                    <div class="frame-mount">
                        <div class="frame-border">
                            <div class="frame-mat">
                                <div class="frame-bevel">
                                    <div class="frame-image">
                                        <img src="<?php echo BASE_URL . ltrim($page_data['image_asset'], '/'); ?>"
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
                    echo "<p style='color:var(--chap-sepia);font-style:italic;'>The projector is dark.</p>";
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
