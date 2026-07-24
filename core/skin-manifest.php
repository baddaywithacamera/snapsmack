<?php
/**
 * SNAPSMACK - Declarative Skin Manifest Loader
 *
 * Skin metadata is untrusted data. This loader reads manifest.json, validates
 * its shape, and never executes skin-provided PHP.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

const SNAPSMACK_MANIFEST_SCHEMA_VERSION = 1;

/**
 * Cosmetic core controls a skin may suppress. Security, account, update,
 * signing, breach, save, and cost-responsibility controls are never hideable.
 */
const SNAPSMACK_MANIFEST_HIDEABLE_CONTROLS = [
    'collections_index_rows',
    'wall_settings',
    'exif_display_toggle',
    'masthead_cover',
    'carousel_cosmetics',
];

/**
 * Return a normalized declarative manifest for an installed skin.
 */
function load_skin_manifest(string $slug): array {
    static $cache = [];

    $slug = strtolower(trim($slug));
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug)) {
        error_log('SnapSmack: refused invalid skin manifest slug');
        return [];
    }
    if (array_key_exists($slug, $cache)) {
        return $cache[$slug];
    }

    $path = dirname(__DIR__) . '/skins/' . $slug . '/manifest.json';
    if (!is_file($path)) {
        return $cache[$slug] = [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || strlen($raw) > 1048576) {
        error_log("SnapSmack: unreadable or oversized manifest.json for {$slug}");
        return $cache[$slug] = [];
    }

    try {
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        error_log("SnapSmack: invalid manifest.json for {$slug}: " . $e->getMessage());
        return $cache[$slug] = [];
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        error_log("SnapSmack: manifest.json root must be an object for {$slug}");
        return $cache[$slug] = [];
    }

    return $cache[$slug] = snapsmack_normalize_skin_manifest($decoded, $slug);
}

/**
 * Transitional call-site adapter. The path is used only to identify the
 * containing skin directory; its contents are never included or executed.
 */
function snapsmack_load_manifest(string $path): array {
    return load_skin_manifest(basename(dirname(str_replace('\\', '/', $path))));
}

function skin_manifest_exists(string $slug): bool {
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug)) return false;
    return is_file(dirname(__DIR__) . '/skins/' . $slug . '/manifest.json');
}

/**
 * Validate the top-level schema while preserving the existing consumer shape.
 */
function snapsmack_normalize_skin_manifest(array $input, string $slug = ''): array {
    $string_keys = [
        'name', 'version', 'author', 'author_email', 'support', 'description',
        'status', 'demo_url', 'default_variant', 'edit_page', 'post_page',
        'skin_preload', 'cover_aspect',
    ];
    $array_keys = [
        'features', 'variants', 'allowed_fonts', 'require_scripts',
        'hide_controls', 'options', 'admin_styling', 'css_variables',
        'incompatible', 'modes',
    ];
    $boolean_keys = [
        'community_comments', 'community_likes', 'community_reactions',
    ];
    $known = array_flip(array_merge(
        ['schema_version'],
        $string_keys,
        $array_keys,
        $boolean_keys
    ));

    $out = ['schema_version' => SNAPSMACK_MANIFEST_SCHEMA_VERSION];
    foreach ($input as $key => $value) {
        if (!is_string($key) || !isset($known[$key])) {
            error_log("SnapSmack: ignored unknown manifest key {$key} for {$slug}");
            continue;
        }
        if ($key === 'schema_version') {
            if ((int)$value !== SNAPSMACK_MANIFEST_SCHEMA_VERSION) {
                error_log("SnapSmack: unsupported manifest schema for {$slug}");
            }
            continue;
        }
        if (in_array($key, $string_keys, true)) {
            if (is_string($value) && strlen($value) <= 16384) {
                $out[$key] = $value;
            }
            continue;
        }
        if (in_array($key, $boolean_keys, true)) {
            if (is_bool($value) || $value === 0 || $value === 1) {
                $out[$key] = (bool)$value;
            }
            continue;
        }
        if ($key === 'options') {
            $out[$key] = snapsmack_normalize_manifest_options($value, $slug);
            continue;
        }
        if ($key === 'hide_controls') {
            $requested = is_array($value) ? $value : [];
            $out[$key] = array_values(array_intersect(
                array_filter($requested, 'is_string'),
                SNAPSMACK_MANIFEST_HIDEABLE_CONTROLS
            ));
            continue;
        }
        if (is_array($value) && snapsmack_manifest_data_is_safe($value)) {
            $out[$key] = $value;
        }
    }

    foreach (['features', 'variants', 'require_scripts', 'options'] as $key) {
        if (!isset($out[$key])) $out[$key] = [];
    }
    return manifest_resolve_fonts($out);
}

/**
 * Validate skin option descriptors. Unknown descriptor fields are discarded.
 */
function snapsmack_normalize_manifest_options(mixed $options, string $slug): array {
    if (!is_array($options)) return [];

    $types = ['color', 'hidden', 'image', 'range', 'range_numeric', 'select', 'text', 'asset'];
    $fields = [
        'accept', 'admin_page', 'css_var', 'default', 'font_filter', 'help',
        'hint', 'is_font', 'is_greyscale', 'label', 'max', 'min', 'min_height',
        'min_width', 'no_size_slider', 'options', 'property', 'section',
        'selector', 'show_when', 'size', 'step', 'sz_key_override', 'type',
        'unit', 'uppercase', 'lowercase', 'capitalize',
    ];
    $normalized = [];

    foreach ($options as $key => $descriptor) {
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{1,96}$/', $key)) continue;
        if (!is_array($descriptor) || array_is_list($descriptor)) continue;
        $type = $descriptor['type'] ?? '';
        if (!is_string($type) || !in_array($type, $types, true)) {
            error_log("SnapSmack: ignored unknown option type for {$slug}:{$key}");
            continue;
        }
        $item = [];
        foreach ($descriptor as $field => $value) {
            if (!is_string($field) || !in_array($field, $fields, true)) continue;
            if (snapsmack_manifest_data_is_safe($value)) $item[$field] = $value;
        }
        $item['type'] = $type;
        $normalized[$key] = $item;
    }
    return $normalized;
}

/**
 * Permit data scalars and bounded nested arrays only.
 */
function snapsmack_manifest_data_is_safe(mixed $value, int $depth = 0): bool {
    if ($depth > 12) return false;
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) return true;
    if (is_string($value)) return strlen($value) <= 65536;
    if (!is_array($value) || count($value) > 4096) return false;
    foreach ($value as $key => $item) {
        if (!(is_int($key) || (is_string($key) && strlen($key) <= 128))) return false;
        if (!snapsmack_manifest_data_is_safe($item, $depth + 1)) return false;
    }
    return true;
}

/**
 * Populate font-picker choices from trusted core inventory when requested.
 */
function manifest_resolve_fonts(array $manifest): array {
    if (empty($manifest['options']) || !is_array($manifest['options'])) return $manifest;

    $needs_fonts = false;
    foreach ($manifest['options'] as $descriptor) {
        if (!empty($descriptor['is_font'])) {
            $needs_fonts = true;
            break;
        }
    }
    if (!$needs_fonts) return $manifest;

    $inventory_path = __DIR__ . '/manifest-inventory.php';
    $inventory = is_file($inventory_path) ? include $inventory_path : [];
    $fonts = [];
    foreach (($inventory['local_fonts'] ?? []) as $key => $font) {
        $fonts[(string)$key] = (string)($font['label'] ?? $key);
    }

    foreach ($manifest['options'] as &$descriptor) {
        if (empty($descriptor['is_font'])) continue;
        // Converted official manifests already carry the exact curated choices
        // they exposed before migration. Preserve those verbatim. Dynamic
        // inventory expansion is only for new declarative manifests that omit
        // their font choices.
        if (!empty($descriptor['options']) && is_array($descriptor['options'])) continue;
        $choices = $fonts;
        $filters = $descriptor['font_filter'] ?? [];
        if (is_string($filters)) $filters = [$filters];
        if (is_array($filters) && $filters) {
            $choices = array_filter($choices, static function ($label, $key) use ($filters) {
                $haystack = strtolower((string)$key . ' ' . (string)$label);
                foreach ($filters as $filter) {
                    if (is_string($filter) && $filter !== '' && str_contains($haystack, strtolower($filter))) {
                        return true;
                    }
                }
                return false;
            }, ARRAY_FILTER_USE_BOTH);
        }
        $descriptor['options'] = $choices;
    }
    unset($descriptor);
    return $manifest;
}

function manifest_hides(array $manifest, string $control_key): bool {
    return in_array($control_key, $manifest['hide_controls'] ?? [], true)
        && in_array($control_key, SNAPSMACK_MANIFEST_HIDEABLE_CONTROLS, true);
}

// ===== SNAPSMACK EOF =====
