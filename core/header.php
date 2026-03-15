<?php
/**
 * SNAPSMACK - Public Navigation Header
 * Alpha v0.7.4
 *
 * Renders the site logo and main navigation bar. Navigation items are: HOME,
 * BLOG (when homepage is a static page), ARCHIVE VIEW, GALLERY VIEW (conditional),
 * BLOGROLL (conditional), and any dynamic pages. Respects homepage_mode setting
 * and show_wall_link setting to hide gallery view on mobile.
 */

// --- ENVIRONMENT BOOTSTRAP ---
if (!defined('BASE_URL')) {
    $db_defined_url = $settings['site_url'] ?? '/';
    $final_base = rtrim($db_defined_url, '/') . '/';
    define('BASE_URL', $final_base);
}

// --- SCOPE VARIABLES ---
// Ensure all config variables are defined to prevent undefined index errors
$site_display_name = $site_name ?? 'SNAPSMACK';
$header_type = $settings['header_type'] ?? 'text';
$logo_path   = $settings['header_logo_url'] ?? '';

// --- HOMEPAGE MODE ---
$homepage_mode    = $settings['homepage_mode'] ?? 'latest_post';
$homepage_page_id = (int)($settings['homepage_page_id'] ?? 0);

// --- DYNAMIC PAGE RETRIEVAL ---
// Load all active pages from the database for menu insertion.
// If a static page is set as homepage, exclude it from the dynamic list
// (it's already the homepage — no need to show it twice in the nav).
try {
    if ($homepage_mode === 'static_page' && $homepage_page_id > 0) {
        $pages_stmt = $pdo->prepare("SELECT title, slug FROM snap_pages WHERE is_active = 1 AND id != ? ORDER BY menu_order ASC");
        $pages_stmt->execute([$homepage_page_id]);
        $dynamic_pages = $pages_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $pages_stmt = $pdo->query("SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC");
        $dynamic_pages = $pages_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $dynamic_pages = [];
}
?>

<script>
/**
 * Session help persistence: if the user has already seen the help tutorial
 * this session, set a flag so hotkey-engine.js can respect it.
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

        <?php if ($homepage_mode === 'static_page'): ?>
            <a href="<?php echo BASE_URL; ?>blog.php">BLOG</a>
            <span class="sep">|</span>
        <?php endif; ?>

        <a href="<?php echo BASE_URL; ?>archive.php">ARCHIVE VIEW</a>

        <?php if (($settings['albums_link_enabled'] ?? '0') === '1'): ?>
            <span class="sep">|</span>
            <a href="<?php echo BASE_URL; ?>albums.php">ALBUMS</a>
        <?php endif; ?>

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
