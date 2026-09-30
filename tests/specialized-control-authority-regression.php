<?php
declare(strict_types=1);
define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';

function special_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function special_render(string $skin, array $settings): string {
    $response = ['kind'=>'landing','photo_count'=>1,'navigation'=>[],'items'=>[['img_title'=>'Photo','img_slug'=>'photo','img_thumb_square'=>'/thumb.jpg','img_file'=>'/photo.jpg']]];
    $view = snapsmack_build_skin_view($response, ['site_name'=>'Example','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skin.css','skin_presentation'=>snapsmack_skin_presentation($settings, $skin)]);
    ob_start(); special_check(snapsmack_render_strict_skin_template(dirname(__DIR__).'/skins/'.$skin, 'layout.php', $view), "{$skin} did not render"); return (string)ob_get_clean();
}

$aurora = special_render('aurora', ['au_l1_opacity'=>'83','au_cycle_time'=>'37','au_panel_opacity'=>'64','au_navbar_opacity'=>'27','au_border_width'=>'9']);
foreach (['data-au-opacity="0.83"','data-au-cycle="37"','--panel-bg:rgba(10,14,26,0.64)','--au-navbar-bg:rgba(10,14,26,0.27)','--tile-bw:9px','class="au-panel"'] as $value) special_check(str_contains($aurora, $value), "AURORA control missing: {$value}");

$jive = special_render('jive-turkey', ['jt_mode'=>'flow','jt_speed'=>'72','jt_cycle_time'=>'29','jt_panel_opacity'=>'61','jt_navbar_opacity'=>'24','jt_solo_scrim_opacity'=>'73']);
foreach (['data-jt-mode="flow"','data-jt-speed="72"','data-jt-cycle="29"','--panel-bg:rgba(255,255,255,0.61)','--jt-navbar-bg:rgba(255,255,255,0.24)','--jt-solo-scrim:rgba(0,0,0,0.73)','class="jt-panel"'] as $value) special_check(str_contains($jive, $value), "JIVE TURKEY control missing: {$value}");

$heuristic = special_render('heuristic', ['he_system_mode'=>'quiet','he_first_delay'=>'31','he_hold_seconds'=>'11','he_rest_seconds'=>'22','he_pulse_count'=>'2','he_memory_red'=>'#123456']);
foreach (['data-mode="quiet"','data-first-delay="31"','data-hold="11"','data-rest="22"','data-pulses="2"','--he-memory-red:#123456','data-heuristic-memory'] as $value) special_check(str_contains($heuristic, $value), "HEURISTIC control missing: {$value}");

$game = special_render('game-on', ['go_treatment_overlay'=>'-42','go_border_radius'=>'17']);
foreach (['--go-puzzle-tone:rgba(0,0,0,0.42)','--tile-radius:17px','data-game-on'] as $value) special_check(str_contains($game, $value), "GAME ON control missing: {$value}");

$parade = special_render('parade', ['pa_border_style'=>'sweep','pa_border_dir'=>'rtl','pa_border_rhythm'=>'constant','pa_wave_speed'=>'91','pa_border_opacity'=>'44']);
foreach (['data-pa-border-style="sweep"','data-pa-border-dir="rtl"','data-pa-border-rhythm="constant"','data-pa-border-cycle="91"','data-pa-border-minl="44"'] as $value) special_check(str_contains($parade, $value), "PARADE control missing: {$value}");

$instant = special_render('instant-camera', ['ic_bg_mode'=>'rainfall','ic_rf_density'=>'66','ic_rf_speed'=>'77','ic_rf_angle'=>'-23','ic_rf_thickness'=>'4','ic_rf_color'=>'#123456','ic_rf_opacity'=>'81']);
foreach (['data-rainfall','data-density="66"','data-speed="77"','data-angle="-23"','data-thickness="4"','data-color="#123456"','data-opacity="81"'] as $value) special_check(str_contains($instant, $value), "INSTANT CAMERA control missing: {$value}");

$chaplin = snapsmack_skin_presentation(['chap_line_count'=>'3','chap_line_1_width'=>'2','chap_line_2_width'=>'3','chap_line_3_width'=>'4','chap_line_gap'=>'5','chap_grain_intensity'=>'7'], 'chaplin');
foreach (['--chap-frame-total:19px','--chap-grain-opacity:0.07','--chap-frame-stripes:linear-gradient'] as $value) special_check(str_contains((string)$chaplin['style'], $value), "CHAPLIN control missing: {$value}");

echo "Specialized control authority regression passed.\n";
