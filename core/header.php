<?php
/**
 * SnapSmack Core Header
 * Version: 6.2 - Wall Link Conditional
 * -------------------------------------------------------------------------
 * - ADDED: Gallery Wall link respects show_wall_link setting.
 * - ADDED: .wall-nav-item class for mobile CSS hide.
 * - Navigation order: HOME | ARCHIVE VIEW | GALLERY VIEW | BLOGROLL | DYNAMIC PAGES
 * -------------------------------------------------------------------------
 */

// 1. BOOTSTRAP ENVIRONMENT
if (!defined('BASE_URL')) {
    $db_defined_url = $settings['site_url'] ?? '/';
    $final_base = rtrim($db_defined_url, '/') . '/';
    define('BASE_URL', $final_base);
}

// 2. SCOPE SAFETY
$site_display_name = $site_name ?? 'SNAPSMACK';
$header_type = $settings['header_type'] ?? 'text';
$logo_path   = $settings['header_logo_url'] ?? '';

// 3. DYNAMIC PAGE RETRIEVAL
try {
    $pages_stmt = $pdo->query("SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC");
    $dynamic_pages = $pages_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dynamic_pages = [];
}
?>

<script>
/**
 * Persistence Check: If the user has seen help this session, 
 * we set a global flag for hotkey-engine.js to respect.
 */
if (sessionStorage.getItem('snapsmack_help_seen')) {
    window.HIDE_SNAP_HELP = true;
}
</script>

<div class="logo-area">
    <a href="<?php echo BASE_URL; ?>">
        <?php if ($header_type === 'image' && !empty($logo_path)): ?>
            <img src="<?php echo BASE_URL . ltrim($logo_path, '/'); ?>" 
                 alt="<?php echo htmlspecialchars($site_display_name); ?>" 
                 class="site-logo">
        <?php else: ?>
            <h1 class="site-title-text"><?php echo htmlspecialchars($site_display_name); ?></h1>
        <?php endif; ?>
    </a>
</div>

<ul class="nav-menu">
    <li>
        <a href="<?php echo BASE_URL; ?>">HOME</a>
        <span class="sep">|</span>

        <a href="<?php echo BASE_URL; ?>archive.php">ARCHIVE VIEW</a>

        <?php if (($settings['show_wall_link'] ?? '1') === '1'): ?>
            <span class="sep wall-nav-item">|</span>
            <a href="<?php echo BASE_URL; ?>gallery-wall.php" class="wall-nav-item">GALLERY VIEW</a>
        <?php endif; ?>

        <?php if (($settings['blogroll_enabled'] ?? '1') == '1'): ?>
            <span class="sep">|</span>
            <a href="<?php echo BASE_URL; ?>blogroll.php">BLOGROLL</a>
        <?php endif; ?>

        <?php if (!empty($dynamic_pages)): ?>
            <span class="sep">|</span>
            <?php 
            $count = count($dynamic_pages);
            foreach ($dynamic_pages as $index => $page): 
                $p_title = strtoupper(htmlspecialchars($page['title']));
                $p_url = BASE_URL . 'page.php?slug=' . htmlspecialchars($page['slug']);
                echo '<a href="' . $p_url . '">' . $p_title . '</a>';
                if ($index < $count - 1) {
                    echo '<span class="sep">|</span>';
                }
            endforeach; 
            ?>
        <?php endif; ?>
    </li>
</ul>
