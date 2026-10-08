<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$layout = (string)file_get_contents($root . '/skins/slickr/layout.php');
$css = (string)file_get_contents($root . '/skins/slickr/style.css');
$manifest = json_decode((string)file_get_contents($root . '/skins/slickr/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

if (($manifest['version'] ?? '') !== '1.0.32') throw new RuntimeException('SLICKR solo repair is not versioned at 1.0.32.');
$fit = $manifest['options']['solo_small_photos']['options'] ?? [];
foreach (['fill','native'] as $mode) {
    $rule = (string)($fit[$mode]['css'] ?? '');
    if ($rule === '' || str_contains($rule, '{') || str_contains($rule, '}')) {
        throw new RuntimeException('SLICKR ' . $mode . ' solo-fit option must be a declaration, not a nested rule.');
    }
}
foreach (['sl-solo-page', 'sl-single-flow h-entry', 'id="sl-photobox"', 'sl-focus-container', 'sl-main-column', 'sl-sidebar'] as $hook) {
    if (!str_contains($layout, $hook)) throw new RuntimeException('SLICKR solo layout lost ' . $hook . '.');
}
$photo = strpos($layout, 'id="sl-photobox"');
$info = strpos($layout, 'sl-focus-container', $photo ?: 0);
$title = strpos($layout, 'sl-photo-title', $info ?: 0);
if ($photo === false || $info === false || $title === false || !($photo < $info && $info < $title)) {
    throw new RuntimeException('SLICKR solo composition must place the photo stage before its bounded information area and title.');
}
if (!str_contains($css, '.sl-solo-page .sl-cover { display: none; }')) {
    throw new RuntimeException('SLICKR solo route does not suppress the oversized cover.');
}
echo "SLICKR solo presentation regression passed.\n";

// ===== SNAPSMACK EOF =====
