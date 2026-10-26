<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$engine = (string)file_get_contents($root . '/assets/js/ss-engine-organized-mayhem.js');
$source = (string)file_get_contents($root . '/core/mayhem-data.php');

$required = [
    '400px aspect-preserving thumbnails' => "img_thumb_aspect",
    'original region scatter' => 'ensureRegionsFor(visibleWorldRect())',
    'overscan for solid moving coverage' => 'var WORLD_MARGIN = 500',
    'whole-wall horizontal float' => 'view.x += driftVX',
    'whole-wall vertical float' => 'view.y += driftVY',
    'fixed per-print horizontal warp' => "rotateX('",
    'fixed per-print vertical warp' => "rotateY('",
];
foreach ($required as $contract => $needle) {
    $haystack = $contract === '400px aspect-preserving thumbnails' ? $source : $engine;
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException('Organized Mayhem lost ' . $contract . '.');
    }
}

foreach (['buildAmbientCoverage', 'coverageMode', 'om-wobble', 'setAlive(', '--om-w', '--om-d'] as $forbidden) {
    if (str_contains($engine, $forbidden)) {
        throw new RuntimeException('Organized Mayhem reintroduced reconstructed or independently moving prints: ' . $forbidden);
    }
}

echo "Organized Mayhem restoration regression passed.\n";
