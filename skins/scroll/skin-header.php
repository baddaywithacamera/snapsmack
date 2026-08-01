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
    'always_visible' => true,
    'inline_social' => true,
    'links' => [
        ['label' => 'Home', 'url' => BASE_URL, 'icon' => 'home']
    ]
];
include dirname(__DIR__, 2) . '/core/components/grid-sticky-nav.php';
?>
<?php // ===== SNAPSMACK EOF =====
