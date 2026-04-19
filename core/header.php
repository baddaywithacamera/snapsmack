<?php
/**
 * SNAPSMACK - Public Navigation Header
 *
 * Renders the site logo and main navigation bar. Navigation items are: HOME,
 * BLOG (when homepage is a static page), ARCHIVE VIEW, FLOATING GALLERY (conditional),
 * BLOGROLL (conditional), and any dynamic pages. Respects homepage_mode setting
 * and show_wall_link setting to hide floating gallery on mobile.
 */

// --- LANDING-ONLY MODE ---
// When landing_only is active the entire nav is suppressed; the skin's header
// wrapper div still renders (so skin-specific CSS framing is intact) but empty.
if (!empty($landing_only_active)) return;

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
        <?php
        // Each nav item carries its own leading separator so disabling any item
        // (archive, blogroll, etc.) never leaves a dangling pipe.
        $archive_enabled = ($settings['archive_layout'] ?? 'square') !== 'none';
        $sep = '<span class="sep">|</span>';
        $nav_started = true; // HOME is always first
        ?>
        <a href="<?php echo BASE_URL; ?>">HOME</a>

        <?php if ($homepage_mode === 'static_page'): ?>
            <?php echo $sep; ?>
            <a href="<?php echo BASE_URL; ?>blog.php">BLOG</a>
        <?php endif; ?>

        <?php if ($archive_enabled): ?>
            <?php echo $sep; ?>
            <a href="<?php echo BASE_URL; ?>archive.php">ARCHIVE VIEW</a>
        <?php endif; ?>

        <?php if (($settings['albums_link_enabled'] ?? '0') === '1'): ?>
            <?php echo $sep; ?>
            <a href="<?php echo BASE_URL; ?>albums.php">ALBUMS</a>
        <?php endif; ?>

        <?php if (($settings['show_wall_link'] ?? '0') === '1'): ?>
            <span class="sep wall-nav-item">|</span>
            <a href="<?php echo BASE_URL; ?>gallery-wall.php" class="wall-nav-item">FLOATING GALLERY</a>
        <?php endif; ?>

        <?php if (($settings['blogroll_enabled'] ?? '1') == '1'): ?>
            <?php echo $sep; ?>
            <a href="<?php echo BASE_URL; ?>blogroll.php">BLOGROLL</a>
        <?php endif; ?>

        <?php if (!empty($dynamic_pages)): ?>
            <?php echo $sep; ?>
            <?php
            $count = count($dynamic_pages);
            foreach ($dynamic_pages as $index => $page):
                $p_title = strtoupper(htmlspecialchars($page['title']));
                $p_url = BASE_URL . 'page.php?slug=' . htmlspecialchars($page['slug']);
                echo '<a href="' . $p_url . '">' . $p_title . '</a>';
                if ($index < $count - 1) {
                    echo $sep;
                }
            endforeach;
            ?>
        <?php endif; ?>
    </li>
</ul>
