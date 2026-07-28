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
<div class="scroll-profile scroll-profile-compact">
    <?php include __DIR__ . '/skin-header.php'; ?>
</div>
<main class="scroll-static-content">
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
</main>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php include dirname(__DIR__, 2) . '/core/footer-scripts.php'; ?>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
