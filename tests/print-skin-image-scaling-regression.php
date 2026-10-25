<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/skin-presentation.php';
$skins = [
    'writing-with-impact' => ['style.css', '.post-content img', '.wwi-archive-item img'],
    'sudden-impact' => ['style.css', '.tg-tile img', '.tg-lightbox img'],
    'impact-printer' => ['style.css', '.ip-ascii-frame-inner img', '.square-grid .thumb-link img'],
];

foreach ($skins as $skin => [$cssFile, $soloHook, $gridHook]) {
    $manifestPath = $root . '/skins/' . $skin . '/manifest.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $option = $manifest['options']['image_scaling'] ?? null;
    if (!is_array($option)
        || ($option['default'] ?? null) !== 'pixelated'
        || ($option['selector'] ?? null) !== ':root'
        || ($option['property'] ?? null) !== '--print-image-rendering'
        || !isset($option['options']['pixelated'], $option['options']['auto'])) {
        throw new RuntimeException("{$skin} must offer crisp/smooth image scaling and default to crisp.");
    }
    $css = (string) file_get_contents($root . '/skins/' . $skin . '/' . $cssFile);
    if (!str_contains($css, $soloHook)
        || !str_contains($css, $gridHook)
        || !str_contains($css, 'image-rendering: var(--print-image-rendering, pixelated)')) {
        throw new RuntimeException("{$skin} must apply configurable scaling to solo and grid photographs.");
    }
    $smooth = snapsmack_declared_skin_style($skin, ['image_scaling' => 'auto']);
    if (!str_contains($smooth, ':root{--print-image-rendering:auto;}')) {
        throw new RuntimeException("{$skin} smooth selection must override its crisp default.");
    }
}

echo "Print-skin image scaling regression passed.\n";
