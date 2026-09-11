<?php
/**
 * GAME ON must not take an older browser down (reported 2026-09-10: current
 * Firefox crashing on older machines). Pins the three load reductions.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$css = preg_replace('~/\*.*?\*/~s', '', (file_get_contents(__DIR__ . '/../skins/game-on/style.css') . "
" . file_get_contents(__DIR__ . '/../assets/css/ss-engine-game-on.css')));   // comments explain, they don't style
$js  = file_get_contents(__DIR__ . '/../assets/js/ss-engine-game-on.js');
$php = file_get_contents(__DIR__ . '/../skins/game-on/landing.php');

function gl_check(string $label, bool $ok): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS {$label}\n";
}

// 1. no standing GPU layer per piece; only while a board slides
preg_match('/\.go-puzzle-piece\s*\{[^}]*\}/s', $css, $piece);
gl_check('piece rule has no standing will-change', isset($piece[0]) && strpos($piece[0], 'will-change') === false);
gl_check('layers only while moving', strpos($css, '.go-puzzle.is-moving .go-puzzle-piece { will-change: transform; }') !== false);
gl_check('engine marks a sliding board', strpos($js, "board.el.classList.add('is-moving')") !== false
    && strpos($js, "board.el.classList.remove('is-moving')") !== false
    && strpos($js, 'if (animate) markMoving(board, dur);') !== false);
// 2. candidate list is a sample, not the archive
gl_check('candidate list is a 288-photo sample', strpos($php, 'array_slice($_go_puzzle_pool, 0, 288)') !== false
    && strpos($php, 'foreach ($_go_candidate_sample as $_go_candidate)') !== false);
// 3. low-end machines get at most half the boards
gl_check('low-end cap applied to density', strpos($js, 'amount = Math.min(amount, lowEndCap());') !== false
    && strpos($js, 'navigator.deviceMemory') !== false && strpos($js, 'navigator.hardwareConcurrency') !== false);
// 0.1.20: server-side fold, per-board containment, adaptive LITE mode
gl_check('feed is folded on the server past the first batch', strpos($php, "\$_go_fold_after = 120;") !== false
    && substr_count($php, "(++\$_go_emitted > \$_go_fold_after) ? ' go-fold' : ''") === 2);
gl_check('reveal batch matches the server fold', strpos(file_get_contents(__DIR__ . '/../assets/js/ss-engine-progressive-reveal.js'), 'var GRID_BATCH = 120;') !== false);
gl_check('each board is contained', strpos($css, 'contain: layout paint style;') !== false);
gl_check('LITE mode drops shadows', strpos($css, '.go-puzzle-field.is-lite .go-puzzle-piece { box-shadow: none;') !== false);
gl_check('LITE switches on from measured frame time', strpos($js, "goLite('frame-time')") !== false && strpos($js, 'slow / frames > 0.35') !== false);
echo "PASS: GAME ON browser-load regression\n";
// ===== SNAPSMACK EOF =====
