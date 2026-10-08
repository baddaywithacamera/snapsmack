<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/skin-presentation.php';

$root = dirname(__DIR__);
$presentation = snapsmack_skin_presentation([
    'header_font_family' => 'Merriweather',
], '50-shades-of-noah-grey');
$style = (string)($presentation['style'] ?? '');
$manifest = json_decode((string)file_get_contents($root . '/skins/50-shades-of-noah-grey/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$inventory = include $root . '/core/manifest-inventory.php';

if (($manifest['version'] ?? '') !== '1.5.1') {
    throw new RuntimeException('50 SHADES OF NOAH GREY release is not sequentially versioned at 1.5.1.');
}
if (!isset($inventory['local_fonts']['Merriweather'])
    || ($inventory['local_fonts']['Merriweather']['file'] ?? '') !== 'assets/fonts/Merriweather/Merriweather-Regular.ttf') {
    throw new RuntimeException('Merriweather is absent from the bounded local font inventory.');
}
if (!str_contains($style, 'font-family:"Merriweather", sans-serif')
    || !str_contains($style, "font-family:'Merriweather'")
    || !str_contains($style, '/font.php?family=Merriweather')) {
    throw new RuntimeException('The selected 50 SHADES masthead face is not delivered through the same-origin provider.');
}
if (!is_file($root . '/assets/fonts/Merriweather/Merriweather-Regular.ttf')
    || !is_file($root . '/assets/fonts/Merriweather/OFL.txt')) {
    throw new RuntimeException('The Merriweather face or its licence is missing from the shared asset library.');
}

echo "50 SHADES masthead font regression: PASS\n";
// ===== SNAPSMACK EOF =====
