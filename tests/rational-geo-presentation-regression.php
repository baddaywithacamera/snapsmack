<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/skin-presentation.php';

$presentation = snapsmack_skin_presentation([
    'masthead_font' => 'Marcellus',
    'show_map_background' => '1',
    'map_opacity' => '30',
], 'rational-geo');
$style = (string)($presentation['style'] ?? '');
$layout = (string)file_get_contents(dirname(__DIR__) . '/skins/rational-geo/layout.php');
$css = (string)file_get_contents(dirname(__DIR__) . '/skins/rational-geo/style.css');
$manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/skins/rational-geo/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$inventory = include dirname(__DIR__) . '/core/manifest-inventory.php';

// A FLOOR, not a pin. Pinning the exact version made every legitimate
// skin release fail this test, so the cheap way out was to change the
// files and leave the version alone — which is exactly how sites ended
// up frozen on an old package under a current-looking version number.
if (version_compare((string)($manifest['version'] ?? '0'), '2.1.11', '<')) {
    throw new RuntimeException('RATIONAL GEO release must stay at or above 2.1.11.');
}
if (!isset($inventory['local_fonts']['Marcellus'])
    || ($inventory['local_fonts']['Marcellus']['file'] ?? '') !== 'assets/fonts/Marcellus/Marcellus-Regular.ttf') {
    throw new RuntimeException('Marcellus is absent from the bounded local font inventory.');
}
if (!str_contains($style, "font-family:'Marcellus'")
    || !str_contains($style, '/font.php?family=Marcellus')) {
    throw new RuntimeException('The selected RATIONAL GEO masthead face is not delivered through the same-origin provider.');
}
if (!str_contains($style, '--rg-map-pct:30')
    || !str_contains($css, "background-image: url('assets/topo-map.png')")
    || !str_contains($css, 'opacity: calc(var(--rg-map-pct, 30) / 100)')) {
    throw new RuntimeException('RATIONAL GEO relief-map presentation no longer preserves its saved 30% intensity.');
}
if (!str_contains($layout, 'rg-map-hidden')) {
    throw new RuntimeException('RATIONAL GEO no longer honors the bounded map visibility setting.');
}
foreach (['rg-header-inside', 'rg-header-nav', 'rg-logo-link', 'rg-masthead'] as $hook) {
    if (!str_contains($layout, $hook)) {
        throw new RuntimeException('RATIONAL GEO lost its pre-strip landing-page hook: ' . $hook);
    }
}
if (!str_contains($layout, "skin_presentation']['style")) {
    throw new RuntimeException('RATIONAL GEO does not emit its bounded compiled presentation stylesheet.');
}
foreach (['--rg-bg-page:', '--rg-bg-chrome:', '--rg-text-primary:', '--rg-nav-color:'] as $variable) {
    if (!str_contains($css, $variable)) {
        throw new RuntimeException('RATIONAL GEO base CSS has no fail-safe palette value for ' . $variable);
    }
}

echo "RATIONAL GEO presentation regression: PASS\n";
// ===== SNAPSMACK EOF =====
