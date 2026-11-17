<?php
/**
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be // ===== SNAPSMACK EOF =====
 */

require_once __DIR__ . '/skin-render-helpers.php';

function snapsmack_public_favicon_url(array $settings, string $skin): string
{
    foreach ([
        $settings['favicon_url'] ?? '',
        $skin !== '' ? ($settings[$skin . '__skin_avatar'] ?? '') : '',
        $settings['skin_avatar'] ?? '',
    ] as $candidate) {
        $url = snapsmack_public_brand_image_url($candidate);
        if ($url !== '') return $url;
    }
    return '';
}

function snapsmack_public_brand_image_url(string $value): string
{
    $url = snap_validate_url($value);
    if ($url === '' || preg_match('#^https?://#i', $url)) return $url;
    return (defined('BASE_URL') ? BASE_URL : '/') . ltrim($url, '/');
}

function snapsmack_public_favicon_link(string $url): string
{
    $href = snap_escape_url($url);
    return $href === '' ? '' : '<link rel="icon" href="' . $href . '">';
}

// ===== SNAPSMACK EOF =====
