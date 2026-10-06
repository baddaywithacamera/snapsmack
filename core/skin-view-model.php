<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * SNAPSMACK - Skin View Model
 *
 * Central, reusable preparation of display-only values consumed by skins.
 * Skins declare media slots in their manifest; the CMS owns resolution and
 * any explicit activation-time initialization.
 */

require_once __DIR__ . '/skin-manifest.php';
require_once __DIR__ . '/public-route-aliases.php';
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);

function snapsmack_latest_asset_image(PDO $pdo): string
{
    try {
        $stmt = $pdo->query(
            "SELECT asset_path FROM snap_assets
             WHERE LOWER(asset_path) REGEXP '\\.(jpe?g|png|gif|webp|avif)$'
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        return trim((string)$stmt->fetchColumn());
    } catch (Throwable $e) {
        // Older installs may not yet have the Asset Repository.
        return '';
    }
}

function snapsmack_resolve_skin_media_slot(PDO $pdo, array $settings, array $slot, bool $allow_fallback = false): string
{
    $setting = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($slot['setting'] ?? ''));
    if ($setting !== '') {
        $configured = trim((string)($settings[$setting] ?? ''));
        if ($configured !== '') return $configured;
    }

    if ($allow_fallback && ($slot['fallback'] ?? '') === 'latest_asset_image') {
        return snapsmack_latest_asset_image($pdo);
    }
    return '';
}

function snapsmack_prepare_skin_view(PDO $pdo, array $settings, string $skin_slug): array
{
    $view = ['media_slots' => [], 'navigation' => [], 'route_aliases' => []];
    $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', $skin_slug);
    if ($slug === '') return $view;

    try {
        $manifest = snapsmack_load_manifest(dirname(__DIR__) . '/skins/' . $slug . '/manifest.json');
    } catch (Throwable $e) {
        return $view;
    }

    foreach (($manifest['cms_media_slots'] ?? []) as $name => $slot) {
        if (!is_string($name) || !is_array($slot)) continue;
        $safe_name = preg_replace('/[^a-zA-Z0-9_\-]/', '', $name);
        if ($safe_name === '') continue;
        // Rendering is deliberately read-only and does not run fallback queries.
        // Empty slots retain the skin's CSS/display fallback until an explicit
        // activation or owner choice initializes them.
        $view['media_slots'][$safe_name] = snapsmack_resolve_skin_media_slot($pdo, $settings, $slot, false);
    }
    $view['route_aliases'] = snapsmack_public_route_aliases($settings, $manifest);
    $view['navigation'] = snapsmack_prepare_skin_navigation($pdo, $settings, $manifest);
    return $view;
}

/** Build the read-only Flickr-style masthead model for SLICKR. */
function snapsmack_prepare_slickr_profile(PDO $pdo, array $settings, array $navigation = []): array
{
    $profile = [
        'cover_url' => '', 'location' => trim((string)($settings['slickr_location'] ?? '')),
        'photo_count' => 0, 'photo_views' => 0, 'photostream_views' => 0,
        'album_views' => 0, 'total_views' => 0, 'launch_year' => 0,
        'cover_pos_x' => max(0, min(100, (int)($settings['slickr_cover_pos_x'] ?? 50))),
        'cover_pos_y' => max(0, min(100, (int)($settings['slickr_cover_pos_y'] ?? 50))),
        'cover_zoom' => max(100, min(300, (int)($settings['slickr_cover_zoom'] ?? 100))) / 100,
    ];
    try {
        $profile['photo_count'] = (int)$pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND img_date <= NOW()")->fetchColumn();
        $seedRows = $pdo->query("SELECT setting_key, setting_val FROM snap_settings WHERE setting_key LIKE 'flickr_seed_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $seed = static fn(string $key, int $fallback = 0): int => isset($seedRows[$key]) && $seedRows[$key] !== '' ? (int)$seedRows[$key] : $fallback;
        $imageSeed = (int)$pdo->query("SELECT COALESCE(SUM(img_view_seed),0) FROM snap_images WHERE img_status='published'")->fetchColumn();
        $albumSeed = (int)$pdo->query('SELECT COALESCE(SUM(view_count),0) FROM snap_albums')->fetchColumn();
        $nativeImage = (int)$pdo->query('SELECT COUNT(*) FROM snap_stats WHERE is_bot=0 AND image_id IS NOT NULL')->fetchColumn();
        $nativeStream = (int)$pdo->query("SELECT COUNT(*) FROM snap_stats WHERE is_bot=0 AND image_id IS NULL AND page_type IN ('archive','landing')")->fetchColumn();
        $nativeAll = (int)$pdo->query('SELECT COUNT(*) FROM snap_stats WHERE is_bot=0')->fetchColumn();
        $photoSeed = $seed('flickr_seed_photo_views', $imageSeed);
        $streamSeed = $seed('flickr_seed_photostream_views');
        $albumViews = $seed('flickr_seed_album_views', $albumSeed);
        $profile['photo_views'] = $photoSeed + $nativeImage;
        $profile['photostream_views'] = $streamSeed + $nativeStream;
        $profile['album_views'] = $albumViews;
        $profile['total_views'] = $photoSeed + $streamSeed + $albumViews
            + $seed('flickr_seed_collection_views') + $seed('flickr_seed_gallery_views') + $nativeAll;
        $profile['launch_year'] = (int)$pdo->query("SELECT COALESCE(MIN(YEAR(img_date)),0) FROM snap_images WHERE img_status='published' AND img_date >= '1990-01-01'")->fetchColumn();

        $coverId = (int)($settings['slickr_cover_image_id'] ?? 0);
        if ($coverId > 0) {
            $stmt = $pdo->prepare("SELECT img_file FROM snap_images WHERE id=? AND img_status='published' LIMIT 1");
            $stmt->execute([$coverId]);
            $profile['cover_url'] = (string)($stmt->fetchColumn() ?: '');
        }
        if ($profile['cover_url'] === '') {
            $profile['cover_url'] = (string)($pdo->query("SELECT img_file FROM snap_images WHERE img_status='published' AND img_width>img_height AND img_date<=NOW() ORDER BY sort_order ASC,id DESC LIMIT 1")->fetchColumn() ?: '');
        }
        if ($profile['cover_url'] !== '') {
            $profile['cover_url'] = (defined('BASE_URL') ? BASE_URL : '/') . ltrim($profile['cover_url'], '/');
        }
    } catch (Throwable $e) {
        // Optional legacy counters may not exist on a new installation.
    }
    $profile['stats'] = [];
    foreach ([
        'photo_count' => 'Photos', 'photo_views' => 'Views',
        'photostream_views' => 'Photostream Views', 'album_views' => 'Album Views',
        'total_views' => 'Total Views', 'launch_year' => 'Launch Date',
    ] as $key => $label) {
        if (!empty($profile[$key])) $profile['stats'][] = [
            'value' => $key === 'launch_year' ? (string)$profile[$key] : number_format((int)$profile[$key]),
            'label' => $label,
        ];
    }
    $profile['tabs'] = [];
    foreach ($navigation as $item) {
        $label = strtolower(trim((string)($item['label'] ?? '')));
        if (in_array($label, ['home', 'photostream', 'albums', 'collections'], true) || empty($item['url'])) continue;
        $profile['tabs'][] = ['label' => (string)$item['label'], 'url' => (string)$item['url']];
    }
    return $profile;
}

function snapsmack_prepare_skin_navigation(PDO $pdo, array $settings, array $manifest): array
{
    $configured = json_decode((string)($settings['nav_menu_json'] ?? '[]'), true);
    $items = is_array($configured) && $configured ? $configured : ($manifest['cms_navigation'] ?? []);
    if (!is_array($items)) $items = [];

    // Preserve the original GRAM-family navigation contract when the owner has
    // not saved a Menu Manager configuration: Home, Blogroll, then active
    // static pages in their chosen order. The CMS resolves these destinations;
    // skins only render the bounded navigation model they receive.
    if (!$items) {
        $items = [
            ['type' => 'home', 'label' => 'Home'],
            ['type' => 'blogroll', 'label' => 'Blogroll'],
        ];
        try {
            $pages = $pdo->query('SELECT id, title, slug FROM snap_pages WHERE is_active = 1 ORDER BY menu_order ASC')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pages as $page) {
                $items[] = [
                    'type' => 'page',
                    'label' => (string)($page['title'] ?? ''),
                    'slug' => (string)($page['slug'] ?? ''),
                    'target_id' => (int)($page['id'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            // Older databases may not yet have the pages table. Home and
            // Blogroll remain available without leaking persistence to skins.
        }
    }

    $base = defined('BASE_URL') ? BASE_URL : '/';
    $controller = (string)($manifest['cms_controller'] ?? '');
    $strict_controller = in_array($controller, ['smacktalk', 'public'], true);
    $strict_smacktalk = $controller === 'smacktalk';
    $is_carousel = (($settings['site_mode'] ?? '') === 'carousel');
    $route_aliases = snapsmack_public_route_aliases($settings, $manifest);
    $resolve = function (array $item) use (&$resolve, $pdo, $base, $strict_controller, $strict_smacktalk, $is_carousel, $route_aliases): ?array {
        if (isset($item['active']) && !$item['active']) return null;
        $type = (string)($item['type'] ?? 'custom');
        $label = (string)($item['label'] ?? '');
        $url = (string)($item['url'] ?? '');
        // Existing owner menus may predate the strict SMACKTALK controller and
        // still store the old public PHP endpoint as a custom URL. Keep the
        // owner's label/order but route that known CMS destination through the
        // bounded controller just like a newly-created Blogroll item.
        if ($strict_controller && $type === 'custom'
            && preg_match('#(?:^|/)blogroll\.php(?:[?#].*)?$#i', trim($url))) {
            $type = 'blogroll';
        }
        if ($strict_controller && $type === 'custom'
            && preg_match('#(?:^|/)archive\.php(?:[?#].*)?$#i', trim($url))) {
            $type = 'image_archive';
        }
        if ($strict_controller && $type === 'custom'
            && preg_match('#(?:^|/)albums\.php(?:[?#].*)?$#i', trim($url))) {
            $type = 'albums';
        }
        // A GRAMOFSMACK landing page is already its complete chronological
        // archive. Do not expose a second Archive destination through strict
        // skin navigation (including old custom archive.php menu records).
        if ($is_carousel && in_array($type, ['archive', 'image_archive'], true)) return null;
        switch ($type) {
            case 'container': $url = ''; break;
            case 'home': $url = $base; break;
            case 'archive':
                $url = $strict_smacktalk
                    ? snapsmack_public_route_url($base, $route_aliases, stripos($label, 'categor') !== false ? 'categories' : 'diary', 'archive')
                    : $base . 'archive.php';
                break;
            case 'categories': $url = $strict_smacktalk ? snapsmack_public_route_url($base, $route_aliases, 'categories', 'categories') : $base . 'archive.php'; break;
            case 'image_archive': $url = $strict_controller ? snapsmack_public_route_url($base, $route_aliases, 'archive', 'images') : $base . 'archive.php'; break;
            case 'albums': $url = $strict_smacktalk ? snapsmack_public_route_url($base, $route_aliases, 'albums', 'albums') : $base . 'albums.php'; break;
            case 'collections': $url = $base . 'collections.php'; break;
            case 'wall': $url = $base . 'gallery-wall.php'; break;
            case 'blogroll': $url = $strict_controller ? snapsmack_public_route_url($base, $route_aliases, 'blogroll', 'blogroll') : $base . 'blogroll.php'; break;
            case 'blog': $url = $base . 'blog.php'; break;
            case 'page':
                $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($item['slug'] ?? ''));
                if ($slug === '' && !empty($item['target_id'])) {
                    try {
                        $stmt = $pdo->prepare('SELECT slug FROM snap_pages WHERE id = ? AND is_active = 1 LIMIT 1');
                        $stmt->execute([(int)$item['target_id']]);
                        $slug = (string)($stmt->fetchColumn() ?: '');
                    } catch (Throwable $e) { $slug = ''; }
                }
                // The front controller resolves clean root slugs as active
                // pages before posts. Keep controller parameters internal;
                // public navigation should be readable and shareable.
                $url = $slug !== '' ? $base . rawurlencode($slug) : '';
                break;
            case 'album':
            case 'category':
            case 'collection':
                if (!empty($item['target_id'])) {
                    $url = $strict_smacktalk
                        ? $base . '?view=archive&' . $type . '=' . (int)$item['target_id']
                        : $base . 'archive.php?' . $type . '=' . (int)$item['target_id'];
                } elseif ($strict_smacktalk && $type === 'category') {
                    $url = $base . '?view=categories';
                } elseif ($strict_smacktalk && $type === 'album') {
                    $url = $base . '?view=albums';
                }
                break;
        }
        $children = [];
        foreach (($item['children'] ?? []) as $child) {
            if (!is_array($child)) continue;
            $resolved = $resolve($child);
            if ($resolved !== null) $children[] = $resolved;
        }
        return [
            'label' => (string)($item['label'] ?? ''),
            'url' => $url,
            'target' => (($item['target'] ?? '') === '_blank') ? '_blank' : '',
            'children' => $children,
        ];
    };

    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $resolved = $resolve($item);
        if ($resolved !== null) $out[] = $resolved;
    }
    return $out;
}

/** Build the bounded, display-only footer model used by strict skins. */
function snapsmack_prepare_public_footer(array $settings, array $manifest): array
{
    $slots = [];
    $custom = static function (string $key) use ($settings): string {
        return trim((string)($settings[$key] ?? ''));
    };
    $mode = static function (string $key) use ($settings): string {
        return (string)($settings[$key] ?? 'on');
    };
    $site = (string)($settings['site_name'] ?? 'SnapSmack');

    if ($mode('footer_slot_copyright') === 'on') {
        $slots[] = ['kind' => 'copyright', 'year' => date('Y'), 'site_name' => $site];
    } elseif ($mode('footer_slot_copyright') === 'custom' && ($text = $custom('footer_slot_copyright_custom')) !== '') {
        $slots[] = ['kind' => 'text', 'text' => strtr($text, ['{year}' => date('Y'), '{site_name}' => $site])];
    }
    if ($mode('footer_slot_email') === 'on' && ($email = trim((string)($settings['site_email'] ?? ''))) !== '') {
        $slots[] = ['kind' => 'email', 'email' => $email];
    } elseif ($mode('footer_slot_email') === 'custom' && ($text = $custom('footer_slot_email_custom')) !== '') {
        $slots[] = ['kind' => 'text', 'text' => $text];
    }
    if ($mode('footer_slot_theme') === 'on') {
        $slots[] = ['kind' => 'theme', 'name' => mb_strtoupper((string)($manifest['name'] ?? $settings['active_skin'] ?? ''), 'UTF-8')];
    } elseif ($mode('footer_slot_theme') === 'custom' && ($text = $custom('footer_slot_theme_custom')) !== '') {
        $slots[] = ['kind' => 'text', 'text' => $text];
    }
    if ($mode('footer_slot_powered') === 'on') {
        $slots[] = ['kind' => 'powered', 'version' => defined('SNAPSMACK_VERSION') ? SNAPSMACK_VERSION : ''];
    } elseif ($mode('footer_slot_powered') === 'custom' && ($text = $custom('footer_slot_powered_custom')) !== '') {
        $slots[] = ['kind' => 'text', 'text' => $text];
    }
    if (($settings['privacy_policy_enabled'] ?? '0') === '1') {
        $slots[] = ['kind' => 'link', 'label' => (string)($settings['privacy_policy_title'] ?? 'Privacy Policy'), 'url' => (defined('BASE_URL') ? BASE_URL : '/') . 'privacy-policy.php'];
    }
    $slots[] = ['kind' => 'link', 'label' => 'RSS', 'url' => (defined('BASE_URL') ? BASE_URL : '/') . 'feed'];
    return ['lowercase' => (($settings['footer_lowercase'] ?? '0') === '1'), 'slots' => $slots];
}

/** Render CMS-resolved navigation. No routing or data access occurs here. */
function snapsmack_render_navigation_items(array $items, int $depth = 0): void
{
    foreach ($items as $item) {
        $children = is_array($item['children'] ?? null) ? $item['children'] : [];
        $has_children = $children && $depth < 2;
        echo '<li' . ($has_children ? ' class="menu-item-has-children"' : '') . '>';
        $label = htmlspecialchars((string)($item['label'] ?? ''), ENT_QUOTES);
        $url = (string)($item['url'] ?? '');
        if ($url === '') {
            echo '<span>' . $label . '</span>';
        } else {
            $target = (($item['target'] ?? '') === '_blank')
                ? ' target="_blank" rel="noopener noreferrer"' : '';
            echo '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '"' . $target . '>' . $label . '</a>';
        }
        if ($has_children) {
            echo '<ul class="menu-item-has-children">';
            snapsmack_render_navigation_items(array_values($children), $depth + 1);
            echo '</ul>';
        }
        echo '</li>';
    }
}

/** Emit only CMS-registered browser engines declared by the active manifest. */
function snapsmack_render_skin_scripts(string $skin_slug): void
{
    $manifest = load_skin_manifest($skin_slug);
    $inventory = require __DIR__ . '/manifest-inventory.php';
    foreach (($manifest['require_scripts'] ?? []) as $handle) {
        $script = $inventory['scripts'][$handle] ?? null;
        if (!is_array($script) || empty($script['path'])) continue;
        echo '<script src="' . htmlspecialchars(BASE_URL . $script['path'], ENT_QUOTES)
            . '?v=' . rawurlencode(SNAPSMACK_VERSION_SHORT) . '"></script>' . "\n";
    }
}

/**
 * Persist declared defaults only during an explicit CMS activation action.
 * Public rendering must never call this function.
 */
function snapsmack_initialize_skin_media_slots(PDO $pdo, array &$settings, string $skin_slug): void
{
    $slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', $skin_slug);
    if ($slug === '') return;

    try {
        $manifest = snapsmack_load_manifest(dirname(__DIR__) . '/skins/' . $slug . '/manifest.json');
    } catch (Throwable $e) {
        return;
    }

    foreach (($manifest['cms_media_slots'] ?? []) as $slot) {
        if (!is_array($slot) || empty($slot['initialize_on_activation'])) continue;
        $setting = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($slot['setting'] ?? ''));
        if ($setting === '') continue;

        $key = $slug . '__' . $setting;
        $existing = trim((string)($settings[$key] ?? $settings[$setting] ?? ''));
        if ($existing !== '') {
            $settings[$setting] = $existing;
            continue;
        }

        $value = snapsmack_resolve_skin_media_slot($pdo, $settings, $slot, true);
        if ($value === '') continue;

        $stmt = $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
        );
        $stmt->execute([$key, $value]);
        $settings[$key] = $value;
        $settings[$setting] = $value;
    }
}

// ===== SNAPSMACK EOF =====
