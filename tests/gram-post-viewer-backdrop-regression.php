<?php
/**
 * Every gram skin exposes Post Viewer Backdrop Colour + Opacity (Sean,
 * 2026-09-11: "make sure the grid, aurora, jive turkey and anything else
 * have it too"). No gram skin may hard-code the viewer backdrop.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
function gv_check(string $label, bool $ok): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS {$label}\n";
}
$root = dirname(__DIR__);
$controls = [
    'aurora'         => ['au_post_viewer_backdrop_color', 'au_post_viewer_backdrop_opacity'],
    'heuristic'      => ['he_post_viewer_backdrop_color', 'he_post_viewer_backdrop_opacity'],
    'instant-camera' => ['ic_post_viewer_backdrop_color', 'ic_post_viewer_backdrop_opacity'],
    'sliders'        => ['sl_post_viewer_backdrop_color', 'sl_post_viewer_backdrop_opacity'],
    'sudden-impact'  => ['tg_post_viewer_backdrop_color', 'tg_post_viewer_backdrop_opacity'],
    'the-grid'       => ['tg_post_viewer_backdrop_color', 'tg_post_viewer_backdrop_opacity'],
    'photogram'      => ['pg_post_viewer_backdrop_color', 'pg_post_viewer_backdrop_opacity'],
    'game-on'        => ['go_post_viewer_backdrop_color', 'go_post_viewer_backdrop_opacity'],
    'parade'         => ['pa_modal_backdrop_color', 'pa_modal_backdrop_opacity'],
    'jive-turkey'    => ['jt_solo_scrim_color', 'jt_solo_scrim_opacity'],
];
foreach ($controls as $skin => [$c, $o]) {
    $man = json_decode(file_get_contents("$root/skins/$skin/manifest.json"), true);
    gv_check("$skin has colour + opacity controls", isset($man['options'][$c], $man['options'][$o]));
    $css = file_get_contents("$root/skins/$skin/style.css");
    gv_check("$skin viewer backdrop is not hard-coded",
        !preg_match('/modal-backdrop\s*\{[^}]*background:\s*rgba\(0,\s*0,\s*0,\s*0?\.[89]\);/', $css)
        && !preg_match('/lightbox-backdrop\s*\{[^}]*background:\s*rgba\(0,\s*0,\s*0,\s*0?\.[89]\);/', $css));
}
echo "PASS: gram post viewer backdrop regression\n";
// ===== SNAPSMACK EOF =====
