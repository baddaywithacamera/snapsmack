<?php
/*
 * SNAPSMACK_EOF_HEADER
 * Last non-empty line must be: // ===== SNAPSMACK EOF =====
 */
/** Static contracts for GAME ON viewport sizing and travelling frame borders. */
$root = dirname(__DIR__);
$engine = file_get_contents($root . '/assets/js/ss-engine-game-on.js');
$style = file_get_contents($root . '/skins/game-on/style.css') . "
" . file_get_contents($root . '/assets/css/ss-engine-game-on.css');   // field/modal styles are shared now
$manifest = file_get_contents($root . '/skins/game-on/manifest.json');
$landing = file_get_contents($root . '/skins/game-on/landing.php');
$staticPage = file_get_contents($root . '/skins/game-on/skin-page.php');
$puzzleField = file_get_contents($root . '/skins/game-on/puzzle-field.php');

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
    'modal explains the legal move' => str_contains($engine, 'Move a tile beside the empty space'),
    'legal modal tiles are identified' => str_contains($engine, "tile.classList.toggle('is-movable'"),
    'border colours cannot return for five seconds' => str_contains($engine, 'now - token.history.get(frame) >= 5000'),
    'border return memory follows the colour' => str_contains($engine, 'target.borderToken = sourceToken;'),
    'touch swipes suppress their synthetic click' => str_contains($engine, 'suppressBoardClickUntil = performance.now() + 400;'),
    'puzzle keyboard handler runs before global navigation' => str_contains($engine, "document.addEventListener('keydown', keyBoard, true);"),
    'puzzle navigation keys stop other handlers' => str_contains($engine, 'event.stopImmediatePropagation();'),
    'high-score initials do not trigger WASD moves' => str_contains($engine, 'editable && letterMove'),
    'post viewer backdrop colour is configurable' => str_contains($manifest, 'go_post_viewer_backdrop_color'),
    'post viewer backdrop opacity is configurable' => str_contains($manifest, 'go_post_viewer_backdrop_opacity'),
    'post viewer backdrop uses its own colour variable' => str_contains($style, '--go-post-viewer-backdrop-color'),
    'post viewer backdrop uses its own opacity variable' => str_contains($style, '--go-post-viewer-backdrop-opacity'),
    'modal uses the dynamic viewport height' => str_contains($style, 'calc(100dvh - 260px)'),
    'short modal has a scroll fallback' => str_contains($style, 'overflow-y: auto;'),
    'solved puzzle reveals complete image' => str_contains($engine, "board.el.classList.add('is-complete');"),
    'complete image hides puzzle lines' => str_contains($style, '.go-game-board.is-complete .go-puzzle-piece { opacity: 0; }'),
    'static pages render the living puzzle field' => str_contains($staticPage, "include __DIR__ . '/puzzle-field.php'"),
    'static puzzle field uses the same engine contract' => str_contains($puzzleField, 'data-game-on'),
    'static page width obeys the global layout variable' => str_contains($style, 'max-width: var(--static-content-width, 850px);'),
    'static page gutters obey the global layout variable' => str_contains($style, 'var(--static-content-gutter, 40px)'),
    'puzzle close control suppresses browser box styling' => str_contains($style, '.go-game-close:focus-visible') && str_contains($style, 'appearance: none;'),
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
