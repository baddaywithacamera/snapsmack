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
require_once __DIR__ . '/indieweb.php';
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

/**
 * Build the bounded data model for the reusable CROP CIRCLES navigation.
 * Persistence, URL validation and taxonomy discovery stay in the CMS; skins
 * receive only display-ready values and choose where the component is placed.
 */
function snapsmack_prepare_crop_circles(PDO $pdo, array $settings, array $manifest, array $navigation): array
{
    // Public bootstrap settings can be deliberately narrowed. Crop Circles owns
    // this reusable CMS feature, so reload its persisted keys here rather than
    // making every skin (or caller) know which social settings it requires.
    try {
        $rows = $pdo->query(
            "SELECT setting_key, setting_val FROM snap_settings
             WHERE setting_key = 'social_dock_enabled'
                OR setting_key LIKE 'social_dock_%'"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        if (is_array($rows)) $settings = array_replace($settings, $rows);
    } catch (Throwable $e) {
        // The passed settings remain the compatibility source on old installs.
    }

    $base = defined('BASE_URL') ? BASE_URL : '/';
    $model = [
        'identity' => trim(str_replace('|', ' ', (string)($settings['scroll_masthead_lines'] ?? $settings['site_name'] ?? 'SnapSmack'))),
        'home_url' => $base,
        'navigation' => $navigation,
        'search' => ['action' => $base, 'placeholder' => substr(trim((string)($settings['search_placeholder'] ?? 'Search or #tag…')), 0, 120)],
        'filters' => [],
        'social' => [],
        'appearance' => [],
        'icon_sprite_url' => $base . 'assets/icons/crop-circles.svg',
    ];

    foreach ([
        'categories' => ['SELECT id, cat_name AS label FROM snap_categories WHERE show_in_archive = 1 ORDER BY cat_name', 'cat'],
        'albums' => ['SELECT id, album_name AS label FROM snap_albums ORDER BY album_name', 'alb'],
        'collections' => ['SELECT id, title AS label FROM snap_collections ORDER BY title', 'col'],
        'photographer' => ["SELECT u.id, u.username AS label FROM snap_users u WHERE EXISTS (SELECT 1 FROM snap_images i WHERE i.user_id=u.id AND i.img_status='published') ORDER BY u.username", 'usr'],
    ] as $label => [$sql, $type]) {
        try {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            if ($label === 'photographer' && count($rows) < 2) continue;
            if ($rows) $model['filters'][] = ['label' => $label, 'type' => $type, 'items' => $rows];
        } catch (Throwable $e) {
            // Optional taxonomy tables differ on old installs; omit unavailable groups.
        }
    }

    if (($settings['social_dock_enabled'] ?? '0') === '1') {
        $platforms = [
            'flickr'=>'Flickr','smugmug'=>'SmugMug','instagram'=>'Instagram','facebook'=>'Facebook',
            'youtube'=>'YouTube','500px'=>'500px','vero'=>'Vero','threads'=>'Threads',
            'mastodon'=>'Mastodon','bluesky'=>'Bluesky','linkedin'=>'LinkedIn','pinterest'=>'Pinterest',
            'tumblr'=>'Tumblr','deviantart'=>'DeviantArt','behance'=>'Behance','linktree'=>'Linktree','website'=>'Website',
        ];
        foreach ($platforms as $key => $label) {
            $url = snapsmack_indieweb_url((string)($settings['social_dock_' . $key] ?? ''));
            if ($url !== '') $model['social'][] = ['url' => $url, 'label' => $label, 'icon' => $key];
        }
    }
    $dock = is_array($manifest['social_dock'] ?? null) ? $manifest['social_dock'] : [];
    $dark = (($dock['color_mode'] ?? $settings['social_dock_color_mode'] ?? 'light') === 'dark');
    $color = (string)($dark
        ? ($dock['color_dark'] ?? $settings['social_dock_color_dark'] ?? '#1a1a1a')
        : ($dock['color_light'] ?? $settings['social_dock_color_light'] ?? '#ffffff'));
    if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) $color = $dark ? '#1a1a1a' : '#ffffff';
    $rgb = implode(',', [hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2))]);
    $model['appearance'] = [
        'icon' => $color,
        'background' => $dark ? 'rgba(255,255,255,.7)' : 'rgba(0,0,0,.7)',
        'background_hover' => $dark ? 'rgba(255,255,255,.95)' : 'rgba(0,0,0,.9)',
        'border' => 'rgba(' . $rgb . ',.3)',
        'border_hover' => 'rgba(' . $rgb . ',.7)',
        'opacity' => max(0, min(100, (int)($dock['opacity'] ?? $settings['social_dock_opacity'] ?? 50))) / 100,
    ];
    return $model;
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
                // Home and Blogroll are already in this list, two lines up.
                // photofri.day has an active page of its own called Home at
                // /home, so the auto-built menu read "HOME BLOGROLL HOME HOW
                // IT WORKS" -- two items with the same word, going to two
                // different places. A page the CMS has already linked is not
                // added a second time. This runs only when the owner has
                // saved no Menu Manager configuration; a menu they built
                // themselves is theirs, duplicates and all.
                $slug = strtolower(trim((string)($page['slug'] ?? '')));
                if ($slug === 'home' || $slug === 'blogroll') continue;
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
    $blogroll_enabled = (($settings['blogroll_enabled'] ?? '1') === '1');
    $resolve = function (array $item) use (&$resolve, $pdo, $base, $strict_controller, $strict_smacktalk, $is_carousel, $route_aliases, $blogroll_enabled): ?array {
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
        if ($type === 'blogroll' && !$blogroll_enabled) return null;
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
            case 'collections': $url = $strict_controller ? snapsmack_public_route_url($base, $route_aliases, 'collections', 'collections') : $base . 'collections.php'; break;
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
    // The h-card identity core/footer.php published. Strict skins lost it with
    // the rest of the footer, and it is what fediverse and IndieWeb readers
    // parse to learn who a site belongs to.
    return [
        'lowercase'   => (($settings['footer_lowercase'] ?? '0') === '1'),
        'site_name'   => $site,
        'site_url'    => defined('BASE_URL') ? BASE_URL : '/',
        'site_note'   => trim(strip_tags((string)($settings['site_description'] ?? ''))),
        'slots'       => $slots,
    ];
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

/**
 * The presentation half of a strict skin view, built once.
 *
 * index.php assembles this inline before every controller render. The legacy
 * standalone pages -- collections.php and its siblings -- never could, which
 * is why they fell back to their own document with one stylesheet and a menu
 * of their own invention. foreverphotograph.ing/collections was that document
 * for its whole life, and the handoff added in 0.7.903D could not save it:
 * Apache MultiViews resolves /collections to collections.php before any
 * rewrite runs, so the redirect's loop guard correctly declined to send the
 * address back to itself. Measured 18 October: /collectionszz reaches
 * index.php and renders a skinned 404 with thirteen stylesheets, while
 * /collections renders the legacy page with one.
 *
 * So a legacy page has to render the skin ITSELF, and to do that it needs
 * exactly this array. index.php still builds its own copy; converging the two
 * is a change to the front controller and wants its own daylight.
 */
function snapsmack_public_presentation(PDO $pdo, array $settings, string $active_skin,
                                       array $manifest, array $navigation,
                                       array $registered_assets = []): array
{
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $version = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($manifest['version'] ?? ''));
    if ($version === '') $version = defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '1';

    $base_style = $base . 'assets/css/public-base.css'
        . (defined('SNAPSMACK_VERSION_SHORT') ? '?v=' . rawurlencode(SNAPSMACK_VERSION_SHORT) : '');
    $skin_style = $base . 'skins/' . rawurlencode($active_skin) . '/style.css?v=' . rawurlencode($version);

    $variant = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($settings['active_skin_variant'] ?? '')));
    $variant_url = '';
    if ($variant !== '' && array_key_exists($variant, $manifest['variants'] ?? [])
        && is_file(dirname(__DIR__) . '/skins/' . $active_skin . '/variant-' . $variant . '.css')) {
        $variant_url = $base . 'skins/' . rawurlencode($active_skin)
            . '/variant-' . rawurlencode($variant) . '.css?v=' . rawurlencode($version);
    }

    return [
        'site_name' => (string)($settings['site_name'] ?? ''),
        'tagline' => (string)($settings['site_tagline'] ?? ''),
        'base_url' => $base,
        'language' => 'en',
        'direction' => 'ltr',
        'owner_name' => (string)($settings['site_author'] ?? ''),
        'site_description' => strip_tags((string)($settings['site_description'] ?? '')),
        'avatar_url' => function_exists('snapsmack_public_avatar_url')
            ? snapsmack_public_avatar_url($settings) : '',
        'skin_slug' => $active_skin,
        'skin_style_url' => $skin_style,
        'base_style_url' => $base_style,
        'skin_variant_url' => $variant_url,
        'skin_custom_style' => function_exists('snapsmack_skin_custom_style')
            ? snapsmack_skin_custom_style($settings) : '',
        'skin_presentation' => function_exists('snapsmack_skin_presentation')
            ? snapsmack_skin_presentation($settings, $active_skin) : [],
        'archive_layout' => (string)($settings['archive_layout'] ?? 'square'),
        'archive_thumb_style' => (string)($settings['archive_thumb_style'] ?? 'cropped'),
        'archive_grid' => snapsmack_archive_grid_metrics(
            $settings, (string)($settings['archive_thumb_style'] ?? 'cropped')),
        'search_dock' => function_exists('snapsmack_gram_search_dock_presentation')
            ? snapsmack_gram_search_dock_presentation($settings) : [],
        'owner_custom_code' => function_exists('snapsmack_owner_custom_code')
            ? snapsmack_owner_custom_code($settings) : '',
        'registered_assets' => $registered_assets,
        'footer' => snapsmack_prepare_public_footer($settings, $manifest),
        // Asked of the skin, not of a slug list in core.
        //
        // features.profile_header is the contract, but a manifest key only
        // answers for skins new enough to carry it, and an installed skin can
        // be many releases behind this repo -- foreverphotograph.ing was on
        // SLICKR 1.0.38 when the key landed in 1.0.42, so the gate said no and
        // the masthead rendered its empty defaults: no cover, no stats, and
        // initials where the avatar should be. Falling back to what the skin's
        // own layout actually draws keeps the decision in the skin and answers
        // for every build of it, old or new.
        'slickr_profile' => snapsmack_skin_wants_profile($active_skin, $manifest)
            ? snapsmack_prepare_slickr_profile($pdo, $settings, $navigation) : [],
        'crop_circles' => snapsmack_prepare_crop_circles($pdo, $settings, $manifest, $navigation),
    ];
}

/**
 * Does this skin draw the profile masthead?
 *
 * The manifest is asked first. A build too old to declare it is asked the only
 * other way that does not put a skin's name in core: whether its layout
 * renders the masthead at all. Cached per request -- this is a file read on a
 * public page.
 */
function snapsmack_skin_wants_profile(string $slug, array $manifest): bool
{
    if (!empty($manifest['features']['profile_header'])) return true;
    static $seen = [];
    if (array_key_exists($slug, $seen)) return $seen[$slug];
    $layout = dirname(__DIR__) . '/skins/' . $slug . '/layout.php';
    $seen[$slug] = is_file($layout)
        && str_contains((string)@file_get_contents($layout), 'sl-cover-profile');
    return $seen[$slug];
}

/**
 * The avatar a strict skin shows in its masthead.
 *
 * Lived inside index.php, which meant it existed only for pages the front
 * controller rendered. collections.php renders the skin itself and had no
 * such function, so avatar_url came back empty and SLICKR drew its initials
 * placeholder beside a perfectly good cover photograph.
 *
 * Guarded: index.php defines its own copy behind the same guard, so whichever
 * file is reached first wins and neither redeclares.
 */
if (!function_exists('snapsmack_public_avatar_url')) {
    function snapsmack_public_avatar_url(array $settings): string {
        $skin = (string)($settings['active_skin'] ?? '');
        $file = '';
        foreach ([$settings['skin_avatar'] ?? '',
                  $skin !== '' ? ($settings[$skin . '__skin_avatar'] ?? '') : '',
                  $settings['header_logo_url'] ?? '',
                  $settings['site_logo'] ?? '',
                  $settings['favicon_url'] ?? ''] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') { $file = $candidate; break; }
        }
        if ($file === '') return '';
        if (preg_match('#^https?://#i', $file)) return $file;
        return (defined('BASE_URL') ? BASE_URL : '/') . ltrim($file, '/');
    }
}

/**
 * How wide a thumbnail tile is, and how many fit a row.
 *
 * archive.php has emitted these as --grid-cols and --thumb-width since the
 * 0.7.79 layout model; the controller-rendered archive emitted neither, so the
 * stylesheet fell through to its own defaults -- 200px tiles with an 80px gap,
 * whatever the owner had chosen. Measured on pixhellated.ca: --thumb-width
 * resolved to nothing and every tile came out 200px wide.
 *
 * The map and the legacy numeric fallback are archive.php's, unchanged. Two
 * copies of a size table disagree eventually, and the one that drifts is the
 * one nobody is looking at.
 */
function snapsmack_archive_grid_metrics(array $settings, string $grid_layout): array
{
    $map = [
        'square'  => ['xs' => 120, 's' => 150, 'm' => 200, 'l' => 250, 'xl' => 300],
        'cropped' => ['xs' => 150, 's' => 190, 'm' => 250, 'l' => 310, 'xl' => 375],
    ];
    $step = $settings['thumb_size'] ?? 'm';
    if (is_numeric($step)) {
        $px = (int)$step;
        if ($px <= 130)      $step = 'xs';
        elseif ($px <= 170)  $step = 's';
        elseif ($px <= 230)  $step = 'm';
        elseif ($px <= 290)  $step = 'l';
        else                 $step = 'xl';
    }
    if (!in_array($step, ['xs', 's', 'm', 'l', 'xl'], true)) $step = 'm';
    if (!isset($map[$grid_layout])) $grid_layout = 'square';

    return [
        'cols' => max(1, min(12, (int)($settings['browse_cols'] ?? 4))),
        'step' => $step,
        'thumb_width' => $map[$grid_layout][$step] ?? $map['square']['m'],
    ];
}

// ===== SNAPSMACK EOF =====
