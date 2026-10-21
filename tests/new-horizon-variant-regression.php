<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * The strict NEW HORIZON template must keep loading its selected palette.
 */

define('SNAPSMACK_SKIN_RENDER', true);
require_once __DIR__ . '/../core/trusted-html.php';
require_once __DIR__ . '/../core/skin-render-helpers.php';

$view = [
    'site' => [
        'language' => 'en',
        'direction' => 'ltr',
        'site_name' => 'Curb Appeal',
        'base_style_url' => '/assets/css/public-base.css',
        'skin_style_url' => '/skins/new-horizon/style.css',
        'skin_variant_url' => '/skins/new-horizon/variant-dark.css',
        'skin_custom_style' => '',
        'skin_presentation' => ['style' => ''],
        'footer' => [],
        'registered_assets' => [],
        'owner_custom_code' => '',
    ],
    'response' => [
        'kind' => 'not_found',
        'page_title' => 'Not found',
        'navigation' => [],
    ],
];

ob_start();
require __DIR__ . '/../skins/new-horizon/layout.php';
$html = (string)ob_get_clean();

$variant = '<link rel="stylesheet" href="/skins/new-horizon/variant-dark.css">';
$skin = '<link rel="stylesheet" href="/skins/new-horizon/style.css">';
if (!str_contains($html, $variant)) {
    fwrite(STDERR, "FAIL: NEW HORIZON did not emit its selected palette stylesheet.\n");
    exit(1);
}
if (strpos($html, $variant) > strpos($html, $skin)) {
    fwrite(STDERR, "FAIL: NEW HORIZON emitted its palette after the skin stylesheet.\n");
    exit(1);
}

echo "PASS: NEW HORIZON emits its selected palette before the skin stylesheet.\n";
// ===== SNAPSMACK EOF =====
