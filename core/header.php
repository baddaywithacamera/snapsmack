<?php
/**
 * SNAPSMACK - Public Navigation Header
 *
 * Renders the site logo and main navigation bar. Navigation structure is
 * driven by nav_menu_json stored in snap_settings (managed via smack-menu.php).
 * Falls back to the legacy flat nav if no menu JSON is configured.
 *
 * Respects landing_only_active to suppress nav on landing-only installs.
 */

// --- LANDING-ONLY MODE ---
if (!empty($landing_only_active)) return;

// --- ENVIRONMENT BOOTSTRAP ---
if (!defined('BASE_URL')) {
    $db_defined_url = $settings['site_url'] ?? '/';
    $final_base = rtrim($db_defined_url, '/') . '/';
    define('BASE_URL', $final_base);
}

// --- SCOPE VARIABLES ---
$site_display_name = $site_name ?? 'SNAPSMACK';
$header_type = $settings['header_type'] ?? 'text';
$logo_path   = $settings['header_logo_url'] ?? '';

// --- HOMEPAGE MODE ---
$homepage_mode    = $settings['homepage_mode'] ?? 'latest_post';
$homepage_page_id = (int)($settings['homepage_page_id'] ?? 0);

// ── RESOLVE URL FOR A MENU ITEM ──────────────────────────────────────────
function ss_nav_item_url(array $item): string
{
    switch ($item['type']) {
        case 'home':     return BASE_URL;
        case 'blog':     return BASE_URL . 'blog.php';
        case 'archive':  return BASE_URL . 'archive.php';
        case 'albums':   return BASE_URL . 'albums.php';
        case 'wall':     return BASE_URL . 'gallery-wall.php';
        case 'blogroll': return BASE_URL . 'blogroll.php';
        case 'page':
            return BASE_URL . 'page.php?slug=' . htmlspecialchars($item['slug'] ?? '');
        case 'custom':
            return htmlspecialchars($item['url'] ?? '#');
        default:
            return '#';
    }
}

// ── LOAD MENU JSON ────────────────────────────────────────────────────────
$nav_menu_json = $settings['nav_menu_json'] ?? '';
$nav_menu = [];

if (!empty($nav_menu_json)) {
    $decoded = @json_decode($nav_menu_json, true);
    if (is_array($decoded)) {
        $nav_menu = $decoded;
    }
}

// ── LEGACY FALLBACK: build flat menu from old slot-based settings ─────────
// Used when no menu JSON is saved yet (fresh installs, upgrades that haven't
// visited smack-menu.php yet).
if (empty($nav_menu)) {
    $nav_menu[] = ['type' => 'home', 'label' => 'HOME', 'children' => []];

    if ($homepage_mode === 'static_page') {
        $nav_menu[] = ['type' => 'blog', 'label' => 'BLOG', 'children' => []];
    }

    $archive_layout = $settings['archive_layout'] ?? 'square';
    if ($archive_layout !== 'none') {
        $nav_menu[] = ['type' => 'archive', 'label' => 'ARCHIVE VIEW', 'children' => []];
    }

    if (($settings['albums_link_enabled'] ?? '0') === '1') {
        $nav_menu[] = ['type' => 'albums', 'label' => 'ALBUMS', 'children' => []];
    }

    if (($settings['show_wall_link'] ?? '0') === '1') {
        $nav_menu[] = ['type' => 'wall', 'label' => 'FLOATING GALLERY', 'children' => []];
    }

    if (($settings['blogroll_enabled'] ?? '1') == '1') {
        $nav_menu[] = ['type' => 'blogroll', 'label' => 'BLOGROLL', 'children' => []];
    }

    // Static pages (legacy: exclude homepage page).
    try {
        if ($homepage_mode === 'static_page' && $homepage_page_id > 0) {
            $pages_stmt = $pdo->prepare("SELECT title, slug FROM snap_pages WHERE is_active = 1 AND id != ? ORDER BY menu_order ASC");
            $pages_stmt->execute([$homepage_page_id]);
        } else {
            $pages_stmt = $pdo->query("SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC");
        }
        foreach ($pages_stmt->fetchAll(PDO::FETCH_ASSOC) as $pg) {
            $nav_menu[] = ['type' => 'page', 'label' => strtoupper($pg['title']), 'slug' => $pg['slug'], 'children' => []];
        }
    } catch (PDOException $e) {}
}

// ── CHECK WHETHER ANY ITEM HAS CHILDREN ───────────────────────────────────
$nav_has_children = false;
foreach ($nav_menu as $item) {
    if (!empty($item['children'])) { $nav_has_children = true; break; }
}

// ── DROPDOWN APPEARANCE CSS VARIABLES ─────────────────────────────────────
// Only output if at least one parent item exists (avoids dead CSS).
if ($nav_has_children) {
    $dd_bg      = $settings['nav_dropdown_bg']      ?? '#000000';
    $dd_opacity = (int)($settings['nav_dropdown_opacity'] ?? 88);
    $dd_text    = $settings['nav_dropdown_text']    ?? '#ffffff';

    // Convert hex + opacity (0-100) to rgba.
    $r = hexdec(substr(ltrim($dd_bg, '#'), 0, 2));
    $g = hexdec(substr(ltrim($dd_bg, '#'), 2, 2));
    $b = hexdec(substr(ltrim($dd_bg, '#'), 4, 2));
    $a = round($dd_opacity / 100, 2);

    echo '<style>:root{';
    echo '--nav-dropdown-bg:rgba(' . $r . ',' . $g . ',' . $b . ',' . $a . ');';
    echo '--nav-dropdown-text:' . htmlspecialchars($dd_text) . ';';
    echo '}</style>';
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
    <?php
    $sep = '<li class="nav-sep" aria-hidden="true"><span class="sep">|</span></li>';
    $first = true;
    foreach ($nav_menu as $item):
        $has_children = !empty($item['children']);
        $item_url     = ss_nav_item_url($item);
        $item_label   = htmlspecialchars($item['label'] ?? '');
        if (!$first) echo $sep;
        $first = false;
    ?>
    <li class="nav-item<?php echo $has_children ? ' nav-has-children' : ''; ?>">
        <a href="<?php echo $item_url; ?>"><?php echo $item_label; ?><?php if ($has_children): ?><span class="nav-caret" aria-hidden="true"> &#9662;</span><?php endif; ?></a>
        <?php if ($has_children): ?>
        <ul class="nav-submenu" role="menu">
            <?php foreach ($item['children'] as $child):
                $child_url   = ss_nav_item_url($child);
                $child_label = htmlspecialchars($child['label'] ?? '');
            ?>
            <li role="menuitem"><a href="<?php echo $child_url; ?>"><?php echo $child_label; ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </li>
    <?php endforeach; ?>
</ul>
