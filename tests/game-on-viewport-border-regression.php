<?php
/*
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 */
/** Static contracts for GAME ON viewport sizing and travelling frame borders. */
$root = dirname(__DIR__);
$engine = file_get_contents($root . '/assets/js/ss-engine-game-on.js');
$style = file_get_contents($root . '/skins/game-on/style.css');
$manifest = file_get_contents($root . '/skins/game-on/manifest.json');
$landing = file_get_contents($root . '/skins/game-on/landing.php');

$checks = [
    'visible viewport height is used' => str_contains($engine, 'var viewportHeight = window.innerHeight;'),
    'document height is not used' => !str_contains($engine, 'var viewportHeight = document.documentElement.clientHeight;'),
    'horizontal travel can change rows' => str_contains($engine, "direction === 'horizontal' && (col === 0 || col === columns - 1)"),
    'horizontal edge offers upper row' => str_contains($engine, "pool[at - columns], side: 'bottom'"),
    'horizontal edge offers lower row' => str_contains($engine, "pool[at + columns], side: 'top'"),
    'vertical travel can change columns' => str_contains($engine, "direction === 'vertical' && (row === 0 || at + columns >= pool.length)"),
    'each beat changes one to three borders' => str_contains($engine, 'var maximum = [1, 1, 3, 3, 3][activity - 1];'),
    'simultaneous changes do not collide' => str_contains($engine, 'var occupied = new Set();'),
    'travel layer inherits rounded image corners' => str_contains($engine, 'layer.style.borderRadius = getComputedStyle(image).borderRadius;'),
    'border activity control is declared' => str_contains($manifest, 'go_border_activity'),
    'border activity reaches the engine' => str_contains($landing, 'data-border-activity='),
    'border activity changes timing' => str_contains($engine, 'var intervals = [[4200, 7000], [2400, 4400], [700, 1500], [400, 1000], [220, 650]];'),
    'whole-image preview lasts two seconds' => str_contains($engine, 'durationFor(active) + 2000'),
    'preview starts the timer when needed' => str_contains($engine, 'if (!active.startedAt) active.startedAt = performance.now();'),
    'preview preserves the exact puzzle state' => str_contains($engine, 'active.previewSlots = active.slots.slice();'),
    'preview restores the exact puzzle state' => str_contains($engine, 'board.slots = board.previewSlots.slice();'),
    'view image is an in-place button' => str_contains($engine, '<button data-game-preview type="button">View image</button>'),
    'view image is no longer an outbound link' => !str_contains($engine, 'data-game-post'),
    'solved puzzle reveals complete image' => str_contains($engine, "board.el.classList.add('is-complete');"),
    'complete image hides puzzle lines' => str_contains($style, '.go-game-board.is-complete .go-puzzle-piece { opacity: 0; }'),
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
