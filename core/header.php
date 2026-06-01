<?php
/**
 * SNAPSMACK - Public Navigation Header
 *
 * Renders the site logo and main navigation bar. Navigation items are: HOME,
 * BLOG (when homepage is a static page), ARCHIVE VIEW, FLOATING GALLERY (conditional),
 * BLOGROLL (conditional), and any dynamic pages. Respects homepage_mode setting
 * and show_wall_link setting to hide floating gallery on mobile.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
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

<?php
// ── NAV MENU ─────────────────────────────────────────────────────────────────
// If nav_menu_json is configured, render from JSON. Otherwise use the legacy
// flat nav (all items in one <li> with pipe separators).

/**
 * Resolve a nav item's URL from its type and settings.
 */
if (!function_exists('_snap_nav_resolve_url')) {
    function _snap_nav_resolve_url(array $item, array $settings, $pdo): string {
        $type = $item['type'] ?? 'custom';
        $url  = $item['url']  ?? '';
        $base = defined('BASE_URL') ? BASE_URL : '/';
        switch ($type) {
            case 'container':  return '';
            case 'custom':     return $url;
            case 'external':   return $url;
            case 'home':       return $base;
            case 'archive':    return $base . 'archive.php';
            case 'albums':     return $base . 'albums.php';
            case 'wall':       return $base . 'gallery-wall.php';
            case 'blogroll':   return $base . 'blogroll.php';
            case 'blog':       return $base . 'blog.php';
            case 'page':
                if (!empty($item['target_id'])) {
                    static $_pc = [];
                    $id = (int)$item['target_id'];
                    if (!array_key_exists($id, $_pc)) {
                        try {
                            $r = $pdo->prepare("SELECT slug FROM snap_pages WHERE id = ? AND is_active = 1 LIMIT 1");
                            $r->execute([$id]);
                            $_pc[$id] = $r->fetchColumn() ?: null;
                        } catch (Exception $e) { $_pc[$id] = null; }
                    }
                    return $_pc[$id] ? $base . 'page.php?slug=' . $_pc[$id] : '';
                }
                // smack-menu.php stores slug directly on the item (no target_id)
                if (!empty($item['slug'])) {
                    return $base . 'page.php?slug=' . rawurlencode($item['slug']);
                }
                return $url;
            case 'album':
            case 'category':
            case 'collection':
                return !empty($item['target_id'])
                    ? $base . 'archive.php?' . $type . '=' . (int)$item['target_id']
                    : $url;
        }
        return $url;
    }
}

/**
 * Render one level of nav items into <li> elements.
 * $depth: 0 = top level, 1 = submenu, 2 = sub-submenu (max).
 */
if (!function_exists('_snap_nav_render_items')) {
    function _snap_nav_render_items(array $items, array $settings, $pdo, int $depth = 0): void {
        $first = ($depth === 0);
        $sep   = '<span class="sep">|</span>';
        foreach ($items as $item) {
            if (isset($item['active']) && !$item['active']) continue;
            $children = array_filter($item['children'] ?? [], fn($c) => !isset($c['active']) || $c['active']);
            $has_kids = !empty($children) && $depth < 2;
            $li_class = $has_kids ? ' class="nav-has-children"' : '';
            echo '<li' . $li_class . '>';
            if ($first) { $first = false; } elseif ($depth === 0) { echo $sep; }
            $url    = _snap_nav_resolve_url($item, $settings, $pdo);
            $label  = htmlspecialchars($item['label'] ?? '');
            $target = (!empty($item['target']) && $item['target'] === '_blank')
                      ? ' target="_blank" rel="noopener noreferrer"' : '';
            if ($item['type'] === 'container' || $url === '') {
                echo '<span>' . $label . '</span>';
            } else {
                echo '<a href="' . htmlspecialchars($url) . '"' . $target . '>' . $label . '</a>';
            }
            if ($has_kids) {
                echo '<ul class="nav-submenu">';
                _snap_nav_render_items(array_values($children), $settings, $pdo, $depth + 1);
                echo '</ul>';
            }
            echo '</li>';
        }
    }
}

$_nav_json  = $settings['nav_menu_json'] ?? '[]';
$_nav_items = json_decode($_nav_json, true);
$_use_json_nav = is_array($_nav_items) && count($_nav_items) > 0;
?>
<?php if ($_use_json_nav): ?>
<ul class="nav-menu">
<?php _snap_nav_render_items($_nav_items, $settings, $pdo); ?>
</ul>
<?php else: ?>
<ul class="nav-menu">
    <li>
        <?php
        $archive_enabled = ($settings['archive_layout'] ?? 'square') !== 'none';
        $sep = '<span class="sep">|</span>';
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
                if ($index < $count - 1) { echo $sep; }
            endforeach;
            ?>
        <?php endif; ?>
    </li>
</ul>
<?php endif; ?>
<?php // ===== SNAPSMACK EOF =====
