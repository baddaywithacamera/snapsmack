<?php
/**
 * SNAPSMACK - Skin View Model
 *
 * Central, reusable preparation of display-only values consumed by skins.
 * Skins declare media slots in their manifest; the CMS owns resolution and
 * any explicit activation-time initialization.
 */

require_once __DIR__ . '/skin-manifest.php';

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
    $view = ['media_slots' => []];
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
    return $view;
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
