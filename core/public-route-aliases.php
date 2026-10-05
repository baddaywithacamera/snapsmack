<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/** Public menu labels become readable route segments; controller names stay internal. */
function snapsmack_public_route_slug(string $label, string $fallback): string
{
    $value = trim($label);
    if ($value !== '' && function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') $value = $ascii;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : $fallback;
}

/**
 * Return the bounded public aliases declared by the configured navigation.
 * Keys are public path segments; values are internal controller views.
 */
function snapsmack_public_route_aliases(array $settings, array $manifest = []): array
{
    $configured = json_decode((string)($settings['nav_menu_json'] ?? '[]'), true);
    $items = is_array($configured) && $configured ? $configured : ($manifest['cms_navigation'] ?? []);
    if (!is_array($items)) return [];

    $archiveView = (($manifest['cms_controller'] ?? '') === 'smacktalk') ? 'diary' : 'archive';
    $isCarousel = (($manifest['site_mode'] ?? '') === 'carousel');
    $typeToView = [
        // A SMACKTALK archive is the chronological essay/post diary. The
        // separate image_archive item owns the Gallery photograph wall.
        'archive' => $archiveView,
        'image_archive' => 'archive',
        'blogroll' => 'blogroll',
        'categories' => 'categories',
        'albums' => 'albums',
    ];
    $fallbacks = [
        'archive' => 'archive',
        'image_archive' => 'images',
        'blogroll' => 'blogroll',
        'categories' => 'categories',
        'albums' => 'albums',
    ];
    $aliases = [];
    $walk = static function (array $nodes) use (&$walk, &$aliases, $typeToView, $fallbacks, $isCarousel): void {
        foreach ($nodes as $item) {
            if (!is_array($item) || (isset($item['active']) && !$item['active'])) continue;
            $type = (string)($item['type'] ?? '');
            if ($type === 'custom') {
                $path = strtolower((string)(parse_url((string)($item['url'] ?? ''), PHP_URL_PATH) ?? ''));
                $file = basename($path);
                $type = match ($file) {
                    'archive.php' => 'image_archive',
                    'blogroll.php' => 'blogroll',
                    'albums.php' => 'albums',
                    default => $type,
                };
            }
            // A GRAMOFSMACK/carousel landing page already is its archive.
            // Do not manufacture a second public archive route from an old
            // menu record left behind by the legacy endpoint scheme.
            if ($isCarousel && in_array($type, ['archive', 'image_archive'], true)) {
                if (is_array($item['children'] ?? null)) $walk($item['children']);
                continue;
            }
            if (isset($typeToView[$type])) {
                $slug = snapsmack_public_route_slug((string)($item['label'] ?? ''), $fallbacks[$type]);
                if (!isset($aliases[$slug])) $aliases[$slug] = $typeToView[$type];
            }
            if (is_array($item['children'] ?? null)) $walk($item['children']);
        }
    };
    $walk($items);
    // Stable conventional paths remain valid even when the owner gives the
    // corresponding menu item a more personal label. The label-derived alias
    // stays first and is therefore the canonical URL emitted by navigation.
    foreach (['diary' => 'diary', 'images' => 'archive', 'blogroll' => 'blogroll',
                 'categories' => 'categories', 'albums' => 'albums'] as $slug => $view) {
        if (in_array($view, $aliases, true) && !isset($aliases[$slug])) $aliases[$slug] = $view;
    }
    return $aliases;
}

function snapsmack_public_route_url(string $base, array $aliases, string $view, string $fallback): string
{
    $slug = array_search($view, $aliases, true);
    if (!is_string($slug) || $slug === '') $slug = $fallback;
    return rtrim($base, '/') . '/' . rawurlencode($slug);
}

// ===== SNAPSMACK EOF =====
