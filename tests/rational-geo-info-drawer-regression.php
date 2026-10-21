<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/skins/rational-geo/style.css');
$manifest = json_decode((string) file_get_contents($root . '/skins/rational-geo/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

foreach (['.rg-single > #footer', 'position: absolute', 'bottom: var(--infobox-height, 50px)', 'overflow-y: auto'] as $hook) {
    if (!str_contains($css, $hook)) {
        throw new RuntimeException("RATIONAL GEO lost its non-destructive info tray hook: {$hook}");
    }
}
if (version_compare((string)($manifest['version'] ?? '0'), '2.1.30', '<')) {
    throw new RuntimeException('RATIONAL GEO version was not advanced for the info-tray repair.');
}

echo "rational geo info-drawer regression: ok\n";
