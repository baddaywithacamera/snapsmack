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

// SECAUDIT 048 follow-up (2026-08-15): emit HTTP security headers from here.
// skin-manifest.php is a SHIPPING file (not in protected_paths.json) that
// core/constants.php require_once's on every request, so this reliably reaches
// upgraded installs even when their protected/frozen constants.php cannot carry
// header changes. Kept at the top so headers are set before any output.
require_once __DIR__ . '/http-security-headers.php';

const SNAPSMACK_MANIFEST_SCHEMA_VERSION = 2;
const SNAPSMACK_MANIFEST_RUNTIME_SCHEMAS = [1, 2];

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
 * Tolerates a null/empty slug (no skins installed, unset active skin) by
 * returning an empty manifest — passing null here 500'd the whole Skin
 * Manager on installs whose skins all predate manifests (2026-09-05).
 */
function load_skin_manifest(?string $slug): array {
    static $cache = [];

    if ($slug === null) {
        return [];
    }
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

/** The only three words a skin's `status` may be. */
const SNAPSMACK_SKIN_STATUSES = ['stable', 'beta', 'development'];

/** Words found in the wild, each mapped to the legal word with the same behaviour. */
const SNAPSMACK_SKIN_STATUS_ALIASES = [
    'alpha'        => 'development',
    'experimental' => 'development',
    'dev'          => 'beta',
    'available'    => 'beta',
];

/**
 * A skin's maturity is one of three words, and only three.
 *
 * `status` is not a label, it decides behaviour. core/skin-registry.php makes a
 * skin uninstallable when it reads exactly "development", and SMACK CENTRAL's
 * packager pre-ticks a skin into the registry when it reads exactly "stable".
 * Both comparisons are string-exact, so a skin that said "dev" counted as
 * shippable while one that said "development" did not -- the same intent, the
 * opposite outcome, decided by three letters.
 *
 * Measured 26 October 2026: seven different words were in use across 30 skins
 * (stable 16, beta 7, alpha 3, available 1, dev 1, experimental 1,
 * development 1) against a vocabulary of three declared in skin-registry.php.
 * Three of those four extra words were never asked for.
 *
 * Each alias maps to the legal word that keeps the behaviour the manifest
 * already had, so canonicalising changes no skin's installability:
 *
 *   dev / available -> beta
 *     both were installable and neither was auto-ticked, which is exactly
 *     what "beta" does.
 *
 *   alpha / experimental -> development
 *     the owner's ruling, 26 October 2026: he calls HEURISTIC experimental and
 *     means it is not ready to go out. Both words say a skin is earlier than
 *     beta, and the word for that is "development", which withholds it from
 *     installation. This is the one alias that changes behaviour rather than
 *     preserving it, and it does so in the safe direction: a skin someone
 *     called alpha stops being offered until a person writes a word that
 *     ships it.
 *
 * An unrecognised word is NOT guessed at. It becomes "development", so a typo
 * withholds a skin rather than shipping it.
 *
 * tests/skin-status-vocabulary-regression.php holds the vocabulary from both
 * sides: no manifest may carry a word outside the three, and this map may not
 * grow a target outside them either.
 */
function snapsmack_skin_status(?string $declared): string
{
    $word = strtolower(trim((string)$declared));
    if ($word === '') return 'development';
    if (in_array($word, SNAPSMACK_SKIN_STATUSES, true)) return $word;
    return SNAPSMACK_SKIN_STATUS_ALIASES[$word] ?? 'development';
}

/**
 * Validate the top-level schema while preserving the existing consumer shape.
 */
function snapsmack_normalize_skin_manifest(array $input, string $slug = ''): array {
    $string_keys = [
        'name', 'version', 'author', 'author_email', 'support', 'description',
        'status', 'demo_url', 'default_variant', 'edit_page', 'post_page',
        'cover_aspect', 'cms_controller', 'view_model',
    ];
    $array_keys = [
        'features', 'variants', 'allowed_fonts', 'require_scripts',
        'hide_controls', 'options', 'admin_styling', 'css_variables',
        'incompatible', 'modes', 'social_dock', 'cms_media_slots', 'cms_navigation',
        'templates', 'capabilities', 'require_styles',
        // A skin's own colour packs. They used to be PHP arrays inside
        // core/skin-presentation.php, which meant a new flag or colourway was
        // a CMS release and the CMS held 114 hex colours belonging to three
        // skins. Colour belongs to the skin.
        'palettes',
    ];
    $boolean_keys = [
        'community_comments', 'community_likes', 'community_reactions',
    ];
    $known = array_flip(array_merge(
        ['schema_version', 'security_policy'],
        $string_keys,
        $array_keys,
        $boolean_keys
    ));

    $schema_version = (int)($input['schema_version'] ?? 0);
    // Installed schema-v1 skins remain inert declarative data and must keep
    // their CMS asset declarations while the fleet migrates. Package install
    // and the security gate still require schema v2; this compatibility path
    // grants no installation or executable authority.
    if (!in_array($schema_version, SNAPSMACK_MANIFEST_RUNTIME_SCHEMAS, true)) {
        error_log("SnapSmack: unsupported manifest schema for {$slug}");
        return ['schema_version' => 0, 'features' => [], 'variants' => [], 'require_scripts' => [], 'options' => []];
    }
    $out = ['schema_version' => $schema_version];
    foreach ($input as $key => $value) {
        if (!is_string($key) || !isset($known[$key])) {
            error_log("SnapSmack: ignored unknown manifest key {$key} for {$slug}");
            continue;
        }
        if ($key === 'schema_version') {
            continue;
        }
        if ($key === 'security_policy') {
            if (is_int($value)) $out[$key] = $value;
            continue;
        }
        if (in_array($key, $string_keys, true)) {
            if (is_string($value) && strlen($value) <= 16384) {
                // Maturity is a decision the registry and the packager act on,
                // so it is reduced to the three legal words here rather than
                // compared string-exact at each call site.
                $out[$key] = $key === 'status' ? snapsmack_skin_status($value) : $value;
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
        'font_source', 'hint', 'inherit_label', 'is_font', 'is_greyscale', 'label', 'max', 'min', 'min_height',
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
    $google_fonts = [];
    foreach (($inventory['fonts'] ?? []) as $key => $label) {
        $google_fonts[(string)$key] = (string)$label;
    }
    $fonts = [];
    foreach (($inventory['local_fonts'] ?? []) as $key => $font) {
        $fonts[(string)$key] = (string)($font['label'] ?? $key);
    }
    foreach (($inventory['fonts'] ?? []) as $key => $label) {
        $fonts[(string)$key] = (string)$label;
    }

    foreach ($manifest['options'] as &$descriptor) {
        if (empty($descriptor['is_font'])) continue;
        // Converted official manifests already carry the exact curated choices
        // they exposed before migration. Preserve those verbatim. Dynamic
        // inventory expansion is only for new declarative manifests that omit
        // their font choices.
        if (!empty($descriptor['options']) && is_array($descriptor['options'])) continue;
        $choices = (($descriptor['font_source'] ?? '') === 'google' && $google_fonts)
            ? $google_fonts
            : $fonts;
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
