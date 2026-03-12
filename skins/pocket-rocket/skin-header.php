<?php
/**
 * SNAPSMACK - Skin header for the pocket-rocket skin
 * Alpha v0.7.2
 *
 * Fixed 48px header bar with hamburger toggle and slide-down nav drawer.
 * No hotkeys, no floating gallery link — mobile-first, tap-only navigation.
 */

// Pull header config and dynamic pages from core
$site_display_name = $site_name ?? 'SNAPSMACK';
$header_type = $settings['header_type'] ?? 'text';
$logo_path   = $settings['header_logo_url'] ?? '';

try {
    $pages_stmt = $pdo->query("SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC");
    $dynamic_pages = $pages_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dynamic_pages = [];
}
?>

<header class="po-header">
    <div class="site-title">
        <a href="<?php echo BASE_URL; ?>">
            <?php if ($header_type === 'image' && !empty($logo_path)): ?>
                <img src="<?php echo BASE_URL . ltrim($logo_path, '/'); ?>"
                     alt="<?php echo htmlspecialchars($site_display_name); ?>"
                     class="site-logo">
            <?php else: ?>
                <?php echo htmlspecialchars($site_display_name); ?>
            <?php endif; ?>
        </a>
    </div>
    <button class="po-burger" onclick="document.getElementById('po-nav').classList.toggle('open');">&#9776;</button>
</header>

<nav id="po-nav" class="po-nav-drawer">
    <a href="<?php echo BASE_URL; ?>">HOME</a>
    <a href="<?php echo BASE_URL; ?>archive.php">ARCHIVE</a>
    <?php if (($settings['blogroll_enabled'] ?? '1') == '1'): ?>
        <a href="<?php echo BASE_URL; ?>blogroll.php">BLOGROLL</a>
    <?php endif; ?>
    <?php if (!empty($dynamic_pages)): ?>
        <?php foreach ($dynamic_pages as $page): ?>
            <a href="<?php echo BASE_URL . 'page.php?slug=' . htmlspecialchars($page['slug']); ?>">
                <?php echo strtoupper(htmlspecialchars($page['title'])); ?>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</nav>
