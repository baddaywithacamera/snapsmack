<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$component = (string)file_get_contents($root . '/core/galleria-page-component.php');
$css = (string)file_get_contents($root . '/skins/galleria/style.css');
$manifest = json_decode((string)file_get_contents($root . '/skins/galleria/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

if (str_contains($component, 'class="site-header htbs-header" data-sticky-header')) {
    throw new RuntimeException('GALLERIA still requests sticky-header behavior.');
}
if (!preg_match('/\.htbs-header\s*\{[^}]*position:\s*relative\s*;/s', $css)) {
    throw new RuntimeException('GALLERIA header is not explicitly in document flow.');
}
if (preg_match('/\.htbs-header\s*\{[^}]*(?:position:\s*(?:fixed|sticky)|(?:top|left|right):\s*0)/s', $css)) {
    throw new RuntimeException('GALLERIA header still carries viewport-pinned geometry.');
}
if (!preg_match('/body\s*\{[^}]*display:\s*flex\s*;[^}]*flex-direction:\s*column\s*;/s', $css)) {
    throw new RuntimeException('GALLERIA document is not a header/main/footer flex column.');
}
if (!preg_match('/body\s*>\s*#scroll-stage\s*\{[^}]*flex:\s*1\s+1\s+auto\s*;/s', $css)) {
    throw new RuntimeException('GALLERIA main region does not consume the available viewport space.');
}
if (!preg_match('/\.htbs-slider-landing\s*\{[^}]*flex:\s*1\s+1\s+auto\s*;[^}]*min-height:\s*0\s*;/s', $css)) {
    throw new RuntimeException('GALLERIA landing does not fit the available main region.');
}
if (preg_match('/\.htbs-slider-landing\s*\{[^}]*(?:height:\s*100d?vh|padding-top:\s*var\(--header-height)/s', $css)) {
    throw new RuntimeException('GALLERIA landing still duplicates viewport or header height.');
}
// At least 1.3.15, not exactly: an equality check here means every later
// GALLERIA package breaks this test, which says nothing about the landing
// flow it exists to guard. What matters is that the fix shipped.
if (version_compare((string)($manifest['version'] ?? '0'), '1.3.15', '<')) {
    throw new RuntimeException('GALLERIA package version is behind 1.3.15, which carried this fix.');
}
if (preg_match('/<style\b|\bstyle\s*=/i', $component)) {
    throw new RuntimeException('GALLERIA component contains inline CSS.');
}

fwrite(STDOUT, "GALLERIA landing-flow regression passed.\n");

// ===== SNAPSMACK EOF =====
