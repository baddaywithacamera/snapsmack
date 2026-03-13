<?php
/**
 * SNAPSMACK - Photogram Static Page View
 * Alpha v0.7.3
 *
 * Photogram-native layout for static pages (About, etc.).
 * Full-width hero image, page title, body content with Photogram gutters.
 * Invoked by page.php when the active skin is Photogram and this file exists.
 *
 * Variables in scope from page.php:
 *   $pdo, $settings, $active_skin, $site_name, $page_data, $page_title,
 *   $skin_path, $snapsmack
 */

$pg_active_tab = 'profile'; // About page lives under the profile nav tab

include __DIR__ . '/skin-meta.php';
?>
<body class="static-transmission">
<?php include __DIR__ . '/skin-header.php'; ?>

<div id="pg-app">
<div class="pg-content">

    <!-- ── Top Bar ─────────────────────────────────────────────────────── -->
    <header class="pg-top-bar">
        <button class="pg-top-bar-btn" onclick="history.back()" aria-label="Back">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </button>
        <span class="pg-top-bar-title"><?php echo $page_title; ?></span>
        <div class="pg-top-bar-btn" aria-hidden="true"></div><!-- spacer to keep title centred -->
    </header>

    <!-- ── Hero image (first image on the page, full 100% width) ────────── -->
    <?php if (!empty($page_data['image_asset'])): ?>
        <div class="pg-page-hero">
            <img src="<?php echo htmlspecialchars(BASE_URL . ltrim($page_data['image_asset'], '/')); ?>"
                 alt="<?php echo $page_title; ?>"
                 class="pg-page-hero-img">
        </div>
    <?php endif; ?>

    <!-- ── Page title + body content ──────────────────────────────────── -->
    <div class="pg-page-body">
        <h1 class="pg-page-title"><?php echo $page_title; ?></h1>
        <div class="pg-page-content">
            <?php
            if (!empty($page_data['content'])) {
                echo $snapsmack->parseContent($page_data['content']);
            } else {
                echo '<p class="pg-text-muted">No content available.</p>';
            }
            ?>
        </div>
    </div>

</div><!-- /.pg-content -->
</div><!-- /#pg-app -->

<?php include __DIR__ . '/skin-footer.php'; ?>
<?php include dirname(__DIR__, 2) . '/core/footer-scripts.php'; ?>
</body>
</html>
