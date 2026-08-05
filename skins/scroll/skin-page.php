<?php
/**
 * SNAPSMACK — SCROLL static page.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

include __DIR__ . '/skin-meta.php';
?>
<body class="scroll-static">
<?php // Header is skin-header.php's .scroll-profile verbatim — the SAME header as the
      // landing. Do NOT wrap it in another .scroll-profile: that double-nested the grid
      // (90% of 90%) and pushed the icon bar ~86px in. ?>
<?php include __DIR__ . '/skin-header.php'; ?>
<main class="scroll-static-content">
    <div class="scroll-static-inner">
        <h1 class="scroll-horizontal-title"><?php echo htmlspecialchars($page_title); ?></h1>
        <?php if (!empty($page_data['image_asset'])): ?>
            <img src="<?php echo BASE_URL . ltrim($page_data['image_asset'], '/'); ?>"
                 class="post-image"
                 alt="<?php echo htmlspecialchars($page_title); ?>">
        <?php endif; ?>
        <div class="description">
            <?php echo !empty($page_data['content'])
                ? $snapsmack->parseContent($page_data['content'])
                : '<p>No content yet.</p>'; ?>
        </div>
    </div>
</main>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php include dirname(__DIR__, 2) . '/core/footer-scripts.php'; ?>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
