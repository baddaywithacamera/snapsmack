<?php
/**
 * SNAPSMACK - Shared GramOfSmack sticky-nav links
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * Emits the <li> items for a GRAMOFSMACK/carousel skin's sticky nav
 * (<ul class="tg-sticky-nav-links">). Included by each gram skin's
 * skin-profile.php so nav ORDER is driven by the Menu Manager (nav_menu_json)
 * instead of being hardcoded — previously these skins ignored the Menu Manager
 * entirely and rebuilt a fixed Home + Blogroll + pages list.
 *
 * Behaviour:
 *   - When nav_menu_json is a non-empty array → render that order verbatim.
 *   - When it's empty (site never touched the Menu Manager) → fall back to the
 *     legacy Home + Blogroll + pages-by-menu_order list, so nothing changes on
 *     upgrade until the user actually saves a menu.
 *   - Archive items are skipped when archive is disabled (archive_layout ===
 *     'none'), matching core/header.php and smack-menu.php.
 *
 * Labels: the Menu Manager stores built-in/page labels UPPERCASE. Gram skins
 * render natural case and let CSS (--nav-text-transform / ic_nav_case) decide,
 * so this partial resolves natural-case labels for built-ins and pulls real
 * page titles from the DB rather than echoing the stored uppercase label.
 *
 * Requires in scope: $settings, $pdo, BASE_URL. Optional active-state hints:
 * $_tg_on_home, $_tg_on_blogroll, $_tg_active_slug (defaulted defensively).
 */

$_gn_base = defined('BASE_URL') ? BASE_URL : '/';

// Active-state is computed here from the request, not read from the skin, so
// this partial is namespace-agnostic — The Grid uses $_tg_*, AURORA $_au_*,
// PARADE $_pa_*, etc. Every gram skin derives these the same way, so we just
// reproduce that logic once. (A skin that already set these hints wins.)
$_gn_script      = basename($_SERVER['SCRIPT_NAME'] ?? '');
$_gn_active_slug = $_GET['slug'] ?? null;
$_gn_on_blogroll = ($_gn_script === 'blogroll.php');
$_gn_on_home     = ($_gn_script === 'index.php' && !isset($_GET['s']) && $_gn_active_slug === null);
$_gn_archive_off = (($settings['archive_layout'] ?? 'square') === 'none');

// URL for a nav item by type (guarded — may already exist from core/header.php).
if (!function_exists('_snap_gram_nav_url')) {
    function _snap_gram_nav_url(array $item, $pdo, string $base): string {
        $type = $item['type'] ?? 'custom';
        switch ($type) {
            case 'container': return '';
            case 'home':      return $base;
            case 'archive':   return $base . 'archive.php';
            case 'albums':    return $base . 'albums.php';
            case 'wall':      return $base . 'gallery-wall.php';
            case 'blogroll':  return $base . 'blogroll.php';
            case 'blog':      return $base . 'blog.php';
            case 'custom':
            case 'external':  return $item['url'] ?? '';
            case 'page':
                if (!empty($item['slug'])) {
                    return $base . 'page.php?slug=' . rawurlencode($item['slug']);
                }
                if (!empty($item['target_id'])) {
                    try {
                        $r = $pdo->prepare("SELECT slug FROM snap_pages WHERE id = ? AND is_active = 1 LIMIT 1");
                        $r->execute([(int)$item['target_id']]);
                        $slug = $r->fetchColumn();
                        return $slug ? $base . 'page.php?slug=' . rawurlencode($slug) : '';
                    } catch (Exception $e) { return ''; }
                }
                return $item['url'] ?? '';
            case 'album':
            case 'category':
            case 'collection':
                return !empty($item['target_id'])
                    ? $base . 'archive.php?' . $type . '=' . (int)$item['target_id']
                    : ($item['url'] ?? '');
        }
        return $item['url'] ?? '';
    }
}

// Natural-case label for an item (built-ins fixed; pages from DB; custom as-typed).
if (!function_exists('_snap_gram_nav_label')) {
    function _snap_gram_nav_label(array $item, $pdo): string {
        $builtins = [
            'home' => 'Home', 'blogroll' => 'Blogroll', 'archive' => 'Archive',
            'albums' => 'Albums', 'wall' => 'Gallery', 'blog' => 'Blog',
        ];
        $type = $item['type'] ?? 'custom';
        if (isset($builtins[$type])) return $builtins[$type];
        if ($type === 'page') {
            try {
                if (!empty($item['slug'])) {
                    $r = $pdo->prepare("SELECT title FROM snap_pages WHERE slug = ? AND is_active = 1 LIMIT 1");
                    $r->execute([$item['slug']]);
                } elseif (!empty($item['target_id'])) {
                    $r = $pdo->prepare("SELECT title FROM snap_pages WHERE id = ? AND is_active = 1 LIMIT 1");
                    $r->execute([(int)$item['target_id']]);
                } else {
                    $r = null;
                }
                if ($r && ($t = $r->fetchColumn())) return $t;
            } catch (Exception $e) {}
        }
        // Fall back to the stored label (custom links, or anything unusual).
        return (string)($item['label'] ?? '');
    }
}

// ── Decide: JSON-driven order, or legacy fallback ──────────────────────────
$_gn_items = json_decode($settings['nav_menu_json'] ?? '[]', true);

if (is_array($_gn_items) && count($_gn_items) > 0) {
    foreach ($_gn_items as $_gn_item) {
        if (!is_array($_gn_item)) continue;
        if (isset($_gn_item['active']) && !$_gn_item['active']) continue;
        $_gn_type = $_gn_item['type'] ?? 'custom';
        if ($_gn_type === 'archive' && $_gn_archive_off) continue;   // archive disabled

        $_gn_url   = _snap_gram_nav_url($_gn_item, $pdo, $_gn_base);
        $_gn_label = _snap_gram_nav_label($_gn_item, $pdo);
        if ($_gn_label === '') continue;

        // Active state for the current page.
        $_gn_active = ($_gn_type === 'home'     && $_gn_on_home)
                   || ($_gn_type === 'blogroll' && $_gn_on_blogroll)
                   || ($_gn_type === 'page'     && !empty($_gn_item['slug']) && $_gn_item['slug'] === $_gn_active_slug);
        $_gn_cls = $_gn_active ? ' class="active"' : '';

        if ($_gn_type === 'container' || $_gn_url === '') {
            echo '<li><span>' . htmlspecialchars($_gn_label) . '</span></li>' . "\n";
        } else {
            echo '<li><a href="' . htmlspecialchars($_gn_url) . '"' . $_gn_cls . '>'
               . htmlspecialchars($_gn_label) . '</a></li>' . "\n";
        }
    }
} else {
    // ── Legacy fallback: Home + Blogroll + pages by menu_order ─────────────
    echo '<li><a href="' . htmlspecialchars($_gn_base) . '"'
       . ($_gn_on_home ? ' class="active"' : '') . '>Home</a></li>' . "\n";

    if (($settings['blogroll_enabled'] ?? '1') == '1') {
        echo '<li><a href="' . htmlspecialchars($_gn_base . 'blogroll.php') . '"'
           . ($_gn_on_blogroll ? ' class="active"' : '') . '>Blogroll</a></li>' . "\n";
    }

    $_gn_pages = $nav_pages ?? [];
    if (empty($_gn_pages)) {
        try {
            $_gn_pages = $pdo->query(
                "SELECT title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $_gn_pages = []; }
    }
    foreach ($_gn_pages as $_gn_pg) {
        $_gn_ps = $_gn_pg['slug'] ?? '';
        echo '<li><a href="' . htmlspecialchars($_gn_base . 'page.php?slug=' . $_gn_ps) . '"'
           . ($_gn_ps === $_gn_active_slug ? ' class="active"' : '') . '>'
           . htmlspecialchars($_gn_pg['title'] ?? '') . '</a></li>' . "\n";
    }
}
// ===== SNAPSMACK EOF =====
