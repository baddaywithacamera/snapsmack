<?php
/**
 * SNAPSMACK — SCROLL solo image layout.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once dirname(__DIR__, 2) . '/core/layout-logic.php';
?>
<div id="scroll-stage" class="scroll-solo-stage">
    <header class="scroll-solo-header">
        <div class="scroll-solo-header-inside">
            <?php include dirname(__DIR__, 2) . '/core/header.php'; ?>
        </div>
    </header>
    <main class="scroll-solo-photobox">
        <div class="scroll-photo-wrap">
            <?php include dirname(__DIR__, 2) . '/core/download-overlay.php'; ?>
            <img src="<?php echo BASE_URL . ltrim($img['img_file'], '/'); ?>"
                 alt="<?php echo htmlspecialchars($img['img_alt'] ?? $img['img_title']); ?>"
                 class="scroll-image post-image"
                 id="main-image">
            <?php echo $download_button; ?>
        </div>
    </main>
    <div id="infobox" class="scroll-infobox">
        <?php include dirname(__DIR__, 2) . '/core/navigation-bar.php'; ?>
    </div>
    <div id="footer">
        <div id="pane-info" class="footer-pane">
            <h1 class="scroll-solo-title"><?php echo htmlspecialchars($img['img_title']); ?></h1>
            <div class="description"><?php echo $snapsmack->parseContent($img['img_description'] ?? ''); ?></div>
        </div>
        <div id="pane-comments" class="footer-pane">
            <?php include dirname(__DIR__, 2) . '/core/community-component.php'; ?>
        </div>
    </div>
    <?php include dirname(__DIR__, 2) . '/core/community-dock.php'; ?>
    <?php include __DIR__ . '/skin-footer.php'; ?>
</div>
<?php // ===== SNAPSMACK EOF =====
