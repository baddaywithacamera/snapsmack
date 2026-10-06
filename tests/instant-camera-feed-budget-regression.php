<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
require_once __DIR__ . '/strict-skin-feature-helper.inc';
snapsmack_assert_strict_skin_feature('instant-camera');
$root = dirname(__DIR__);
$mayhem = (string)file_get_contents($root . '/assets/js/ss-engine-organized-mayhem.js');
$endpoint = (string)file_get_contents($root . '/core/mayhem-data.php');
foreach ([
    'no empty coverage cells' => 'var rows = Math.max(1, Math.floor(M / cols));',
    'real photograph aspect ratio' => 'var h = w / aspect;',
    'responsive coverage rebuild' => "window.addEventListener('resize'",
] as $rule => $needle) if (!str_contains($mayhem, $needle)) throw new RuntimeException("Organized Mayhem lost {$rule}.");
if (!str_contains($endpoint, "'aspect'=>")) throw new RuntimeException('Mayhem endpoint lost source aspect ratios.');
echo "PASS: instant-camera behavior is CMS-owned and its presentation contract is strict.
";
// ===== SNAPSMACK EOF =====
