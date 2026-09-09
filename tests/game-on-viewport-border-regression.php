<?php
/*
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 */
/** Static contracts for GAME ON viewport sizing and travelling frame borders. */
$root = dirname(__DIR__);
$engine = file_get_contents($root . '/assets/js/ss-engine-game-on.js');

$checks = [
    'visible viewport height is used' => str_contains($engine, 'var viewportHeight = window.innerHeight;'),
    'document height is not used' => !str_contains($engine, 'var viewportHeight = document.documentElement.clientHeight;'),
    'horizontal travel can change rows' => str_contains($engine, "direction === 'horizontal' && (col === 0 || col === columns - 1)"),
    'horizontal edge offers upper row' => str_contains($engine, "pool[at - columns], side: 'bottom'"),
    'horizontal edge offers lower row' => str_contains($engine, "pool[at + columns], side: 'top'"),
    'vertical travel can change columns' => str_contains($engine, "direction === 'vertical' && (row === 0 || at + columns >= pool.length)"),
    'each beat changes one to three borders' => str_contains($engine, 'var changes = rand(1, Math.min(3, Math.floor(pool.length / 2)));'),
    'simultaneous changes do not collide' => str_contains($engine, 'var occupied = new Set();'),
];

$failed = false;
foreach ($checks as $name => $ok) {
    if ($ok) continue;
    fwrite(STDERR, "FAIL: {$name}\n");
    $failed = true;
}
if ($failed) exit(1);
echo "PASS: GAME ON uses the visible viewport and border travel can leave its starting row or column.\n";
// ===== SNAPSMACK EOF =====
