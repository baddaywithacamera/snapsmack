<?php
// SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
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

// SS-1007: the 950D name ban also rejected the real September ambient path.
// Its function is restored verbatim from 35f06bee (introduced by 42338a14).
// Pin that source instead of treating its name as evidence of reconstruction.
$normalizedEngine = str_replace("\r\n", "\n", $engine);
$coverageStart = strpos($normalizedEngine, '        function buildAmbientCoverage()');
$coverageEnd = $coverageStart === false ? false : strpos($normalizedEngine, "\n        function reshuffle()", $coverageStart);
if ($coverageStart === false || $coverageEnd === false
    || hash('sha256', trim(substr($normalizedEngine, $coverageStart, $coverageEnd - $coverageStart)))
        !== 'b933fed59e4d73ac959e06639363c178735c278b1d4fb613a2f99fd2f7016803') {
    throw new RuntimeException('Organized Mayhem ambient coverage differs from the September reference.');
}
foreach (['om-wobble', 'setAlive(', '--om-w', '--om-d'] as $forbidden) {
    if (str_contains($engine, $forbidden)) {
        throw new RuntimeException('Organized Mayhem reintroduced reconstructed or independently moving prints: ' . $forbidden);
    }
}

echo "Organized Mayhem restoration regression passed.\n";

// ===== SNAPSMACK EOF =====
