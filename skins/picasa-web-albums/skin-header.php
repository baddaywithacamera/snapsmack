<?php
/**
 * SNAPSMACK - Skin header for Picasa Web Albums
 * v1.0
 *
 * Minimal fixed header: site name left, nav right.
 * Picasa-style clean header that barely exists until you need it.
 */

if (!defined('BASE_URL')) {
    $db_url = $settings['site_url'] ?? '/';
    define('BASE_URL', rtrim($db_url, '/') . '/');
}

$site_display_name = $site_name ?? 'SNAPSMACK';
$show_nav = ($settings['show_header_nav'] ?? '1') === '1';

// Load dynamic pages for nav
try {
    $pages_stmt = $pdo->query("SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC");
    $dynamic_pages = $pages_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dynamic_pages = [];
}
?>
<header class="pwa-header" id="pwa-header">
    <div class="pwa-header-inner">
        <a href="<?php echo BASE_URL; ?>" class="pwa-header-logo">
            <?php echo htmlspecialchars($site_display_name); ?>
        </a>

        <?php if ($show_nav): ?>
        <nav class="pwa-header-nav">
            <a href="<?php echo BASE_URL; ?>">Home</a>
            <a href="<?php echo BASE_URL; ?>archive.php">Archive</a>
            <?php if (($settings['blogroll_enabled'] ?? '1') == '1'): ?>
                <a href="<?php echo BASE_URL; ?>blogroll.php">Blogroll</a>
            <?php endif; ?>
            <?php foreach ($dynamic_pages as $page): ?>
                <a href="<?php echo BASE_URL . 'page.php?slug=' . htmlspecialchars($page['slug']); ?>">
                    <?php echo htmlspecialchars($page['title']); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
    </div>
</header>

<script>
// Add shadow on scroll
(function() {
    var header = document.getElementById('pwa-header');
    if (!header) return;
    var scrolled = false;
    window.addEventListener('scroll', function() {
        var now = window.scrollY > 10;
        if (now !== scrolled) {
            scrolled = now;
            header.classList.toggle('scrolled', scrolled);
        }
    }, { passive: true });
})();
</script>
