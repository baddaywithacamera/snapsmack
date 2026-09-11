<?php
/**
 * The GAME ON puzzle field is a shared engine (script + stylesheet) so other
 * skins can host it. (PARADE hosted it for one build, then withdrew it.)
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
$go_man = json_decode(file_get_contents(__DIR__ . '/../skins/game-on/manifest.json'), true);

gs_check('shared sheet holds the field, modal and scoreboard',
    strpos($shared, '.go-puzzle-field {') !== false && strpos($shared, '.go-game-modal {') !== false && strpos($shared, '.go-scoreboard {') !== false);
gs_check('GAME ON stylesheet no longer duplicates the field', strpos($go_css, '.go-puzzle-field {') === false && strpos($go_css, '.go-game-modal {') === false);
gs_check('GAME ON keeps its own page rules', strpos($go_css, 'body:has(.go-puzzle-field)') !== false && strpos($go_css, '.go-content-wrap {') !== false);
gs_check('script inventory attaches the stylesheet', strpos($inv, "'css'          => 'assets/css/ss-engine-game-on.css'") !== false);
gs_check('host-overridable field colours', strpos($shared, 'var(--go-field-bg, #fff)') !== false && strpos($shared, 'var(--go-edge-color, #fff)') !== false);
gs_check('GAME ON requires the engine script', in_array('smack-game-on', \$go_man['require_scripts'], true));
echo "PASS: GAME ON shared field regression\n";
// ===== SNAPSMACK EOF =====
