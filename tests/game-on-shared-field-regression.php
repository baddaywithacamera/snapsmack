<?php
/**
 * The GAME ON puzzle field is a shared engine (script + stylesheet) so other
 * skins can host it. PARADE is the first (Sean, 2026-09-11).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
function gs_check(string $label, bool $ok): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS {$label}\n";
}
$shared = file_get_contents(__DIR__ . '/../assets/css/ss-engine-game-on.css');
$go_css = file_get_contents(__DIR__ . '/../skins/game-on/style.css');
$inv    = file_get_contents(__DIR__ . '/../core/manifest-inventory.php');
$pa_man = json_decode(file_get_contents(__DIR__ . '/../skins/parade/manifest.json'), true);
$go_man = json_decode(file_get_contents(__DIR__ . '/../skins/game-on/manifest.json'), true);
$pa_php = file_get_contents(__DIR__ . '/../skins/parade/landing.php');
$pa_css = file_get_contents(__DIR__ . '/../skins/parade/style.css');

gs_check('shared sheet holds the field, modal and scoreboard',
    strpos($shared, '.go-puzzle-field {') !== false && strpos($shared, '.go-game-modal {') !== false && strpos($shared, '.go-scoreboard {') !== false);
gs_check('GAME ON stylesheet no longer duplicates the field', strpos($go_css, '.go-puzzle-field {') === false && strpos($go_css, '.go-game-modal {') === false);
gs_check('GAME ON keeps its own page rules', strpos($go_css, 'body:has(.go-puzzle-field)') !== false && strpos($go_css, '.go-content-wrap {') !== false);
gs_check('script inventory attaches the stylesheet', strpos($inv, "'css'          => 'assets/css/ss-engine-game-on.css'") !== false);
gs_check('host-overridable field colours', strpos($shared, 'var(--go-field-bg, #fff)') !== false && strpos($shared, 'var(--go-edge-color, #fff)') !== false);
gs_check('both skins require the engine script',
    in_array('smack-game-on', $go_man['require_scripts'], true) && in_array('smack-game-on', $pa_man['require_scripts'], true));
gs_check('PARADE offers the field, off by default', ($pa_man['options']['pa_puzzles']['default'] ?? '') === 'off'
    && isset($pa_man['options']['pa_go_density'], $pa_man['options']['pa_go_activity'], $pa_man['options']['pa_go_speed'], $pa_man['options']['pa_go_modal_theme']));
gs_check('PARADE emits the engine root only when on', strpos($pa_php, "if (\$_pa_puzzles !== 'off')") !== false && strpos($pa_php, 'class="go-puzzle-field pa-puzzle-field"') !== false && strpos($pa_php, 'data-game-on') !== false);
gs_check('PARADE candidate list is a sample', strpos($pa_php, 'array_slice($_pa_go_pool, 0, 288)') !== false);
gs_check('PARADE field sits between flag and content, transparent', strpos($pa_css, '--go-field-bg:  transparent') !== false && strpos($pa_css, '--go-edge-color: transparent') !== false);
gs_check('PARADE maps its viewer backdrop onto the puzzle window', strpos($pa_css, '--go-modal-backdrop-color:   var(--pa-modal-backdrop-color') !== false);
echo "PASS: GAME ON shared field regression\n";
// ===== SNAPSMACK EOF =====
