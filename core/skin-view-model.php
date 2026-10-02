<?php
/**
 * SNAPSMACK - Skin View Model
 *
 * Central, reusable preparation of display-only values consumed by skins.
 * Skins declare media slots in their manifest; the CMS owns resolution and
 * any explicit activation-time initialization.
 */

require_once __DIR__ . '/skin-manifest.php';
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
    $view = ['media_slots' => [], 'navigation' => []];
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
    $view['navigation'] = snapsmack_prepare_skin_navigation($pdo, $settings, $manifest);
    return $view;
}

function snapsmack_prepare_skin_navigation(PDO $pdo, array $settings, array $manifest): array
{
    $configured = json_decode((string)($settings['nav_menu_json'] ?? '[]'), true);
    $items = is_array($configured) && $configured ? $configured : ($manifest['cms_navigation'] ?? []);
    if (!is_array($items)) return [];

    $base = defined('BASE_URL') ? BASE_URL : '/';
    $strict_smacktalk = (($manifest['cms_controller'] ?? '') === 'smacktalk');
    $resolve = function (array $item) use (&$resolve, $pdo, $base, $strict_smacktalk): ?array {
        if (isset($item['active']) && !$item['active']) return null;
        $type = (string)($item['type'] ?? 'custom');
        $url = (string)($item['url'] ?? '');
        // Existing owner menus may predate the strict SMACKTALK controller and
        // still store the old public PHP endpoint as a custom URL. Keep the
        // owner's label/order but route that known CMS destination through the
        // bounded controller just like a newly-created Blogroll item.
        if ($strict_smacktalk && $type === 'custom'
            && preg_match('#(?:^|/)blogroll\.php(?:[?#].*)?$#i', trim($url))) {
            $type = 'blogroll';
        }
        switch ($type) {
            case 'container': $url = ''; break;
            case 'home': $url = $base; break;
            case 'archive': $url = $base . 'archive.php'; break;
            case 'image_archive': $url = $base . '?view=archive'; break;
            case 'albums': $url = $base . 'albums.php'; break;
            case 'collections': $url = $base . 'collections.php'; break;
            case 'wall': $url = $base . 'gallery-wall.php'; break;
            case 'blogroll': $url = $strict_smacktalk ? $base . '?view=blogroll' : $base . 'blogroll.php'; break;
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
                $url = $slug !== '' ? $base . '?view=page&slug=' . rawurlencode($slug) : '';
                break;
            case 'album':
            case 'category':
            case 'collection':
                $url = !empty($item['target_id'])
                    ? $base . 'archive.php?' . $type . '=' . (int)$item['target_id'] : $url;
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
