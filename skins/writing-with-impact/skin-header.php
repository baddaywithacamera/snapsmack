<?php
/**
 * SNAPSMACK - Skin header for the WRITING WITH IMPACT skin
 * v1.0.0
 *
 * Emits the dot-matrix nameplate (custom logo or site title + tagline), the nav
 * bar, and OPENS the continuous-feed page frame: #wwi-page > #wwi-content.
 * skin-footer.php closes them. Paper stock (plain / green-bar) is applied as a
 * class on #wwi-page. Honours the shared Global Vibe logo settings.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$wwi_header_image = trim($settings['header_image'] ?? '');
$wwi_header_logo  = trim($settings['header_logo'] ?? ($settings['header_logo_url'] ?? ($settings['site_logo'] ?? '')));
$site_display_name = $settings['site_name'] ?? 'SNAPSMACK';
$wwi_tagline       = trim($settings['site_tagline'] ?? '');
$wwi_paper         = (($settings['paper_style'] ?? 'plain') === 'greenbar') ? 'paper-greenbar' : 'paper-plain';

// --- pages for default nav ---
$homepage_mode    = $settings['homepage_mode'] ?? 'latest_post';
$homepage_page_id = (int)($settings['homepage_page_id'] ?? 0);
try {
    if ($homepage_mode === 'static_page' && $homepage_page_id > 0) {
        $ps = $pdo->prepare("SELECT title, slug FROM snap_pages WHERE is_active = 1 AND id != ? ORDER BY menu_order ASC");
        $ps->execute([$homepage_page_id]);
        $wwi_pages = $ps->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $wwi_pages = $pdo->query("SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { $wwi_pages = []; }

if (!function_exists('_wwi_nav_url')) {
    function _wwi_nav_url(array $item, $pdo): string {
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
if (!function_exists('_wwi_nav_items')) {
    function _wwi_nav_items(array $items, $pdo, int $depth = 0): void {
        foreach ($items as $item) {
            if (isset($item['active']) && !$item['active']) continue;
            $url   = _wwi_nav_url($item, $pdo);
            $label = htmlspecialchars($item['label'] ?? '');
            $kids  = array_filter($item['children'] ?? [], fn($c) => !isset($c['active']) || $c['active']);
            $has   = !empty($kids) && $depth < 2;
            echo '<li' . ($has ? ' class="has-children"' : '') . '>';
            if (($item['type'] ?? '') === 'container' || $url === '') echo '<span>' . $label . '</span>';
            else echo '<a href="' . htmlspecialchars($url) . '">' . $label . '</a>';
            if ($has) { echo '<ul>'; _wwi_nav_items(array_values($kids), $pdo, $depth + 1); echo '</ul>'; }
            echo '</li>';
        }
    }
}
if (!function_exists('_wwi_default_nav')) {
    function _wwi_default_nav(array $settings, array $pages): array {
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
?>
<div id="wwi-page" class="<?php echo $wwi_paper; ?>">

    <header id="wwi-header">
        <?php if ($wwi_header_image !== ''):
            $img_url = preg_match('#^https?://#', $wwi_header_image) ? $wwi_header_image : BASE_URL . ltrim($wwi_header_image, '/'); ?>
        <div class="wwi-header-image"><img src="<?php echo htmlspecialchars($img_url); ?>" alt="<?php echo htmlspecialchars($site_display_name); ?>"></div>
        <?php endif; ?>
        <?php if ($wwi_header_logo !== ''):
            $logo_url = preg_match('#^https?://#', $wwi_header_logo) ? $wwi_header_logo : BASE_URL . ltrim($wwi_header_logo, '/'); ?>
        <a href="<?php echo BASE_URL; ?>" class="wwi-logo-link"><img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars($site_display_name); ?>" class="wwi-logo"></a>
        <?php else: ?>
        <h1 class="wwi-title"><a href="<?php echo BASE_URL; ?>"><?php echo htmlspecialchars($site_display_name); ?></a></h1>
        <?php endif; ?>
        <?php if ($wwi_tagline !== ''): ?><p class="wwi-tagline"><?php echo htmlspecialchars($wwi_tagline); ?></p><?php endif; ?>
    </header>

    <nav id="wwi-nav" class="navigation" role="navigation">
        <ul class="main-menu">
        <?php if ($_use_json) { _wwi_nav_items($_nav_items, $pdo); } else { foreach (_wwi_default_nav($settings, $wwi_pages) as $it): ?>
            <li><a href="<?php echo htmlspecialchars($it['url']); ?>"><?php echo htmlspecialchars($it['label']); ?></a></li>
        <?php endforeach; } ?>
        </ul>
        <button class="nav-toggle" aria-label="Toggle navigation">
            <div class="bars"><span class="bar"></span><span class="bar"></span><span class="bar"></span></div>
        </button>
        <div class="mobile-navigation">
            <ul class="mobile-menu">
            <?php if ($_use_json) { _wwi_nav_items($_nav_items, $pdo); } else { foreach (_wwi_default_nav($settings, $wwi_pages) as $it): ?>
                <li><a href="<?php echo htmlspecialchars($it['url']); ?>"><?php echo htmlspecialchars($it['label']); ?></a></li>
            <?php endforeach; } ?>
            </ul>
        </div>
    </nav>

    <div id="wwi-content">
<?php // ===== SNAPSMACK EOF =====
