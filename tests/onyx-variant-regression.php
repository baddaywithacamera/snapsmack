<?php
declare(strict_types=1);

define('SNAPSMACK_SKIN_RENDER', true);
require_once __DIR__ . '/../core/trusted-html.php';
require_once __DIR__ . '/../core/skin-render-helpers.php';

$view = [
    'site' => [
        'language' => 'en',
        'direction' => 'ltr',
        'site_name' => 'Mirror Project',
        'base_style_url' => '/assets/css/public-base.css',
        'skin_style_url' => '/skins/onyx/style.css',
        'skin_variant_url' => '/skins/onyx/variant-topaz.css',
        'skin_custom_style' => '',
        'skin_presentation' => ['style' => ''],
        'body_class' => '',
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
require __DIR__ . '/../core/onyx-page-component.php';
$html = (string)ob_get_clean();

if (!str_contains($html, '<link rel="stylesheet" href="/skins/onyx/variant-topaz.css">')) {
    fwrite(STDERR, "FAIL: ONYX did not emit its selected palette stylesheet.\n");
    exit(1);
}

echo "PASS: ONYX emits its selected palette stylesheet.\n";
// ===== SNAPSMACK EOF =====
