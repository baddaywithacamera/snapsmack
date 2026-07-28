<?php
/**
 * SNAPSMACK — SCROLL compact page header.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

try {
    $scroll_nav_pages = $pdo->query(
        "SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $scroll_nav_pages = [];
}
$grid_nav_config = [
    'prefix' => 'scroll',
    'observer_class' => 'scroll-profile',
    'identity' => $settings['photographer_name'] ?? ($settings['site_name'] ?? 'SnapSmack'),
    'inline_social' => true,
    'links' => [
        ['label' => 'Home', 'url' => BASE_URL, 'icon' => 'home'],
        ['label' => 'Archive', 'url' => BASE_URL . 'archive.php', 'icon' => 'archive'],
        ['label' => 'Blogroll', 'url' => BASE_URL . 'blogroll.php', 'icon' => 'people']
    ]
];
include dirname(__DIR__, 2) . '/core/components/grid-sticky-nav.php';
?>
<header class="scroll-page-header">
    <a class="scroll-horizontal-title" href="<?php echo BASE_URL; ?>">
        <?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?>
    </a>
    <nav class="scroll-text-nav" aria-label="Page navigation">
        <a href="<?php echo BASE_URL; ?>">Home</a>
        <a href="<?php echo BASE_URL; ?>archive.php">Archive</a>
        <?php foreach ($scroll_nav_pages as $scroll_page): ?>
            <a href="<?php echo BASE_URL . 'page.php?slug=' . urlencode($scroll_page['slug']); ?>">
                <?php echo htmlspecialchars($scroll_page['title']); ?>
            </a>
        <?php endforeach; ?>
    </nav>
</header>
<?php // ===== SNAPSMACK EOF =====
