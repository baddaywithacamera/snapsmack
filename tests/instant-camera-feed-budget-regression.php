<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
require_once __DIR__ . '/strict-skin-feature-helper.inc';
snapsmack_assert_strict_skin_feature('instant-camera');
$root = dirname(__DIR__);
$mayhem = (string)file_get_contents($root . '/assets/js/ss-engine-organized-mayhem.js');
$endpoint = (string)file_get_contents($root . '/core/mayhem-data.php');
foreach ([
    'gap-resistant region overscan' => 'var WORLD_MARGIN = 500',
    'original scattered region placement' => 'ensureRegionsFor(visibleWorldRect())',
    'coherent whole-wall drift' => 'view.x += driftVX',
    'interactive print warp' => "!ambient && budget.warp",
    'historical ambient coverage' => 'function buildAmbientCoverage()',
    'bounded background drift' => 'if (coverageMode)',
] as $rule => $needle) if (!str_contains($mayhem, $needle)) throw new RuntimeException("Organized Mayhem lost {$rule}.");
if (!str_contains($endpoint, 'img_thumb_aspect')) throw new RuntimeException('Mayhem endpoint lost its 400px aspect thumbnails.');
foreach (['om-wobble', 'setAlive('] as $forbidden) {
    if (str_contains($mayhem, $forbidden)) throw new RuntimeException('Organized Mayhem reintroduced independent print motion.');
}
echo "PASS: instant-camera behavior is CMS-owned and its presentation contract is strict.
";
// ===== SNAPSMACK EOF =====
