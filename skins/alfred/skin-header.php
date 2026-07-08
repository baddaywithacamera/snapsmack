<?php
/**
 * SNAPSMACK - Skin header for the Alfred skin
 * v1.0.0
 *
 * Emits:
 *   1. nav.navigation — dark bar with ul.main-menu (desktop) + hamburger + mobile drawer
 *   2. div.header-image — full-width background image (user-replaceable via skin option)
 *   3. header.header.section-inner — custom logo or blog title text
 *
 * Respects skin options: header_image, header_logo, retina_logo.
 * Nav supports nav_menu_json (structured) or falls back to default flat nav.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// --- Skin options ---
$alfred_header_image = trim($settings['header_image'] ?? '');
$alfred_header_logo  = trim($settings['header_logo']  ?? '');
$alfred_retina_logo  = ($settings['retina_logo'] ?? '0') === '1';

// Build header-image inline style
$header_image_style = '';
if ($alfred_header_image !== '') {
    $img_url = (preg_match('#^https?://#', $alfred_header_image))
        ? $alfred_header_image
        : BASE_URL . ltrim($alfred_header_image, '/');
    $header_image_style = ' style="background-image: url(\'' . htmlspecialchars($img_url, ENT_QUOTES) . '\')"';
}

// --- Nav data ---
$site_display_name = $settings['site_name'] ?? 'SNAPSMACK';
$homepage_mode     = $settings['homepage_mode'] ?? 'latest_post';
$homepage_page_id  = (int)($settings['homepage_page_id'] ?? 0);

try {
    if ($homepage_mode === 'static_page' && $homepage_page_id > 0) {
        $pages_stmt = $pdo->prepare(
            "SELECT title, slug FROM snap_pages WHERE is_active = 1 AND id != ? ORDER BY menu_order ASC"
        );
        $pages_stmt->execute([$homepage_page_id]);
        $alfred_pages = $pages_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $pages_stmt   = $pdo->query("SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC");
        $alfred_pages = $pages_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $alfred_pages = [];
}

// --- Nav menu render ---
// Prefer nav_menu_json if configured; otherwise build default Alfred nav.
$_nav_json  = $settings['nav_menu_json'] ?? '[]';
$_nav_items = json_decode($_nav_json, true);
$_use_json  = is_array($_nav_items) && count($_nav_items) > 0;

/**
 * Render one level of Alfred nav items into <li> elements for ul.main-menu.
 * Mirrors core/header.php _snap_nav_resolve_url/_snap_nav_render_items but
 * uses Alfred's class conventions (menu-item-has-children) and separator style.
 */
if (!function_exists('_alfred_nav_resolve_url')) {
    function _alfred_nav_resolve_url(array $item, array $settings, $pdo): string {
        $type = $item['type'] ?? 'custom';
        $url  = $item['url']  ?? '';
        $base = defined('BASE_URL') ? BASE_URL : '/';
        switch ($type) {
            case 'container':  return '';
            case 'custom':
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

if (!function_exists('_alfred_nav_render_items')) {
    function _alfred_nav_render_items(array $items, array $settings, $pdo, int $depth = 0): void {
        foreach ($items as $item) {
            if (isset($item['active']) && !$item['active']) continue;
            $children = array_filter($item['children'] ?? [], fn($c) => !isset($c['active']) || $c['active']);
            $has_kids = !empty($children) && $depth < 2;
            $li_class = $has_kids ? ' class="menu-item-has-children"' : '';
            echo '<li' . $li_class . '>';
            $url    = _alfred_nav_resolve_url($item, $settings, $pdo);
            $label  = htmlspecialchars($item['label'] ?? '');
            $target = (!empty($item['target']) && $item['target'] === '_blank')
                      ? ' target="_blank" rel="noopener noreferrer"' : '';
            if ($item['type'] === 'container' || $url === '') {
                echo '<span>' . $label . '</span>';
            } else {
                echo '<a href="' . htmlspecialchars($url) . '"' . $target . '>' . $label . '</a>';
            }
            if ($has_kids) {
                echo '<ul class="menu-item-has-children">';
                _alfred_nav_render_items(array_values($children), $settings, $pdo, $depth + 1);
                echo '</ul>';
            }
            echo '</li>';
        }
    }
}

// Default nav item builder
function _alfred_default_nav_items(array $settings, array $alfred_pages): array {
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $items = [['label' => 'HOME', 'url' => $base]];

    if (($settings['homepage_mode'] ?? 'latest_post') === 'static_page') {
        $items[] = ['label' => 'BLOG', 'url' => $base . 'blog.php'];
    }

    $archive_enabled = ($settings['archive_layout'] ?? 'square') !== 'none';
    if ($archive_enabled) {
        $items[] = ['label' => 'ARCHIVE', 'url' => $base . 'archive.php'];
    }

    if (($settings['blogroll_enabled'] ?? '1') == '1') {
        $items[] = ['label' => 'BLOGROLL', 'url' => $base . 'blogroll.php'];
    }

    foreach ($alfred_pages as $page) {
        $items[] = [
            'label' => strtoupper($page['title']),
            'url'   => $base . 'page.php?slug=' . rawurlencode($page['slug']),
        ];
    }

    return $items;
}
?>
<nav class="navigation" role="navigation">
    <div class="section-inner">

        <!-- Desktop nav -->
        <ul class="main-menu">
        <?php if ($_use_json): ?>
            <?php _alfred_nav_render_items($_nav_items, $settings, $pdo); ?>
        <?php else: ?>
            <?php foreach (_alfred_default_nav_items($settings, $alfred_pages) as $item): ?>
            <li><a href="<?php echo htmlspecialchars($item['url']); ?>"><?php echo htmlspecialchars($item['label']); ?></a></li>
            <?php endforeach; ?>
        <?php endif; ?>
        </ul>

        <!-- Mobile toggle -->
        <button class="nav-toggle" aria-label="Toggle navigation">
            <div class="bars">
                <span class="bar"></span>
                <span class="bar"></span>
                <span class="bar"></span>
            </div>
        </button>

        <!-- Mobile drawer -->
        <div class="mobile-navigation">
            <ul class="mobile-menu">
            <?php if ($_use_json): ?>
                <?php _alfred_nav_render_items($_nav_items, $settings, $pdo); ?>
            <?php else: ?>
                <?php foreach (_alfred_default_nav_items($settings, $alfred_pages) as $item): ?>
                <li><a href="<?php echo htmlspecialchars($item['url']); ?>"><?php echo htmlspecialchars($item['label']); ?></a></li>
                <?php endforeach; ?>
            <?php endif; ?>
            </ul>
        </div>

    </div><!-- /.section-inner -->
</nav><!-- /.navigation -->

<!-- Full-viewport header image -->
<div class="header-image"<?php echo $header_image_style; ?>></div>

<!-- Blog title / logo -->
<header class="header section-inner">
<?php if ($alfred_header_logo !== ''): ?>
    <?php
    $logo_url = (preg_match('#^https?://#', $alfred_header_logo))
        ? $alfred_header_logo
        : BASE_URL . ltrim($alfred_header_logo, '/');
    ?>
    <a href="<?php echo BASE_URL; ?>" class="custom-logo-link blog-logo">
        <img src="<?php echo htmlspecialchars($logo_url); ?>"
             alt="<?php echo htmlspecialchars($site_display_name); ?>"
             class="custom-logo"
             <?php if ($alfred_retina_logo): ?>
             style="width: auto; max-width: 100%;"
             <?php endif; ?>>
    </a>
<?php else: ?>
    <h1 class="blog-title"><a href="<?php echo BASE_URL; ?>"><?php echo htmlspecialchars($site_display_name); ?></a></h1>
<?php endif; ?>
<?php $alfred_tagline = trim($settings['site_tagline'] ?? ''); if ($alfred_tagline !== ''): ?>
    <p class="blog-description"><?php echo htmlspecialchars($alfred_tagline); ?></p>
<?php endif; ?>
</header><!-- /.header -->
<?php // ===== SNAPSMACK EOF =====
