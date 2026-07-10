<?php
/**
 * SNAPSMACK - Skin header for the STANLEY skin
 * v1.0.0
 *
 * Emits the Kubrick blue banner (custom logo or blog title + tagline), the nav
 * bar (nav_menu_json structured menu, or a sensible default), and OPENS the
 * page frame: #stanley-page > #stanley-wrapper > #stanley-content. skin-footer.php
 * closes them and renders the sidebar. Honours the shared Global Vibe logo
 * settings (header_logo / header_logo_url / site_logo).
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$stanley_header_image = trim($settings['header_image'] ?? '');
$stanley_header_logo  = trim($settings['header_logo'] ?? ($settings['header_logo_url'] ?? ($settings['site_logo'] ?? '')));
$site_display_name    = $settings['site_name'] ?? 'SNAPSMACK';
$stanley_tagline      = trim($settings['site_tagline'] ?? '');

$banner_style = '';
if ($stanley_header_image !== '') {
    $img_url = preg_match('#^https?://#', $stanley_header_image)
        ? $stanley_header_image
        : BASE_URL . ltrim($stanley_header_image, '/');
    $banner_style = ' style="background-image:linear-gradient(rgba(0,0,0,.12),rgba(0,0,0,.42)),url(\'' . htmlspecialchars($img_url, ENT_QUOTES) . '\');background-size:cover;background-position:center;"';
}

// --- pages for default nav ---
$homepage_mode    = $settings['homepage_mode'] ?? 'latest_post';
$homepage_page_id = (int)($settings['homepage_page_id'] ?? 0);
try {
    if ($homepage_mode === 'static_page' && $homepage_page_id > 0) {
        $ps = $pdo->prepare("SELECT title, slug FROM snap_pages WHERE is_active = 1 AND id != ? ORDER BY menu_order ASC");
        $ps->execute([$homepage_page_id]);
        $stanley_pages = $ps->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stanley_pages = $pdo->query("SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { $stanley_pages = []; }

if (!function_exists('_stanley_nav_url')) {
    function _stanley_nav_url(array $item, $pdo): string {
        $type = $item['type'] ?? 'custom';
        $url  = $item['url']  ?? '';
        $base = defined('BASE_URL') ? BASE_URL : '/';
        switch ($type) {
            case 'container': return '';
            case 'custom':
            case 'external':  return $url;
            case 'home':      return $base;
            case 'archive':   return $base . '?view=archive';
            case 'albums':    return $base . 'albums.php';
            case 'wall':      return $base . 'gallery-wall.php';
            case 'blogroll':  return $base . 'blogroll.php';
            case 'blog':      return $base . 'blog.php';
            case 'page':
                if (!empty($item['slug'])) return $base . 'page.php?slug=' . rawurlencode($item['slug']);
                if (!empty($item['target_id'])) {
                    try {
                        $r = $pdo->prepare("SELECT slug FROM snap_pages WHERE id = ? AND is_active = 1 LIMIT 1");
                        $r->execute([(int)$item['target_id']]);
                        $s = $r->fetchColumn();
                        return $s ? $base . 'page.php?slug=' . $s : '';
                    } catch (Exception $e) { return ''; }
                }
                return $url;
            case 'album':
            case 'category':
            case 'collection':
                return !empty($item['target_id']) ? $base . 'archive.php?' . $type . '=' . (int)$item['target_id'] : $url;
        }
        return $url;
    }
}
if (!function_exists('_stanley_nav_items')) {
    function _stanley_nav_items(array $items, $pdo, int $depth = 0): void {
        foreach ($items as $item) {
            if (isset($item['active']) && !$item['active']) continue;
            $url   = _stanley_nav_url($item, $pdo);
            $label = htmlspecialchars($item['label'] ?? '');
            $kids  = array_filter($item['children'] ?? [], fn($c) => !isset($c['active']) || $c['active']);
            $has   = !empty($kids) && $depth < 2;
            echo '<li' . ($has ? ' class="has-children"' : '') . '>';
            if (($item['type'] ?? '') === 'container' || $url === '') echo '<span>' . $label . '</span>';
            else echo '<a href="' . htmlspecialchars($url) . '">' . $label . '</a>';
            if ($has) { echo '<ul>'; _stanley_nav_items(array_values($kids), $pdo, $depth + 1); echo '</ul>'; }
            echo '</li>';
        }
    }
}
if (!function_exists('_stanley_default_nav')) {
    function _stanley_default_nav(array $settings, array $pages): array {
        $base  = defined('BASE_URL') ? BASE_URL : '/';
        $items = [['label' => 'HOME', 'url' => $base]];
        if (($settings['homepage_mode'] ?? 'latest_post') === 'static_page') $items[] = ['label' => 'BLOG', 'url' => $base . 'blog.php'];
        if (($settings['archive_layout'] ?? 'square') !== 'none')            $items[] = ['label' => 'ARCHIVE', 'url' => $base . '?view=archive'];
        if (($settings['blogroll_enabled'] ?? '1') == '1')                    $items[] = ['label' => 'BLOGROLL', 'url' => $base . 'blogroll.php'];
        foreach ($pages as $p) $items[] = ['label' => strtoupper($p['title']), 'url' => $base . 'page.php?slug=' . rawurlencode($p['slug'])];
        return $items;
    }
}

$_nav_items = json_decode($settings['nav_menu_json'] ?? '[]', true);
$_use_json  = is_array($_nav_items) && count($_nav_items) > 0;
$stanley_show_sidebar = ($settings['show_sidebar'] ?? '1') === '1';
?>
<div id="stanley-page" class="<?php echo $stanley_show_sidebar ? 'has-sidebar' : 'no-sidebar'; ?>">

    <div id="stanley-header"<?php echo $banner_style; ?>>
        <?php if ($stanley_header_logo !== ''):
            $logo_url = preg_match('#^https?://#', $stanley_header_logo) ? $stanley_header_logo : BASE_URL . ltrim($stanley_header_logo, '/'); ?>
        <a href="<?php echo BASE_URL; ?>" class="stanley-logo-link"><img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars($site_display_name); ?>" class="stanley-logo"></a>
        <?php else: ?>
        <h1 class="stanley-blog-title"><a href="<?php echo BASE_URL; ?>"><?php echo htmlspecialchars($site_display_name); ?></a></h1>
        <?php endif; ?>
        <?php if ($stanley_tagline !== ''): ?><p class="stanley-blog-desc"><?php echo htmlspecialchars($stanley_tagline); ?></p><?php endif; ?>
    </div>

    <nav id="stanley-nav" class="navigation" role="navigation">
        <ul class="main-menu">
        <?php if ($_use_json) { _stanley_nav_items($_nav_items, $pdo); } else { foreach (_stanley_default_nav($settings, $stanley_pages) as $it): ?>
            <li><a href="<?php echo htmlspecialchars($it['url']); ?>"><?php echo htmlspecialchars($it['label']); ?></a></li>
        <?php endforeach; } ?>
        </ul>
        <button class="nav-toggle" aria-label="Toggle navigation">
            <div class="bars"><span class="bar"></span><span class="bar"></span><span class="bar"></span></div>
        </button>
        <div class="mobile-navigation">
            <ul class="mobile-menu">
            <?php if ($_use_json) { _stanley_nav_items($_nav_items, $pdo); } else { foreach (_stanley_default_nav($settings, $stanley_pages) as $it): ?>
                <li><a href="<?php echo htmlspecialchars($it['url']); ?>"><?php echo htmlspecialchars($it['label']); ?></a></li>
            <?php endforeach; } ?>
            </ul>
        </div>
    </nav>

    <div id="stanley-wrapper">
        <div id="stanley-content">
<?php // ===== SNAPSMACK EOF =====
