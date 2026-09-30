<?php
declare(strict_types=1);
define('SNAPSMACK_SKIN_RENDER', true);
require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';
require_once dirname(__DIR__) . '/core/public-controller.php';

function special_check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function special_render(string $skin, array $settings, string $kind = 'landing'): string {
    $response = $kind === 'photo'
        ? ['kind'=>'photo','photo_count'=>1,'navigation'=>[],'item'=>['img_title'=>'Photo','img_slug'=>'photo','img_file'=>'/photo.jpg','img_focus_x'=>17,'img_focus_y'=>83,'img_zoom'=>240,'img_description'=>snapsmack_trusted_html('<p>Description</p>')]]
        : ['kind'=>'landing','photo_count'=>1,'navigation'=>[],'items'=>[['img_title'=>'Photo','img_slug'=>'photo','img_thumb_square'=>'/thumb.jpg','img_file'=>'/photo.jpg']]];
    $view = snapsmack_build_skin_view($response, ['site_name'=>'Example','language'=>'en','direction'=>'ltr','skin_style_url'=>'/skin.css','skin_presentation'=>snapsmack_skin_presentation($settings, $skin)]);
    ob_start(); special_check(snapsmack_render_strict_skin_template(dirname(__DIR__).'/skins/'.$skin, 'layout.php', $view), "{$skin} did not render"); return (string)ob_get_clean();
}

$aurora = special_render('aurora', ['au_l1_opacity'=>'83','au_cycle_time'=>'37','au_panel_opacity'=>'64','au_navbar_opacity'=>'27','au_border_width'=>'9']);
foreach (['data-au-opacity="0.83"','data-au-cycle="37"','--panel-bg:rgba(10,14,26,0.64)','--au-navbar-bg:rgba(10,14,26,0.27)','--tile-bw:9px','class="au-panel"'] as $value) special_check(str_contains($aurora, $value), "AURORA control missing: {$value}");
$auroraTreatment = special_render('aurora', ['au_treatment_mode'=>'image','au_treatment_image'=>'img_uploads/backdrop.jpg','au_treatment_position'=>'top','au_treatment_overlay'=>'-55','au_navbar_opacity_inner'=>'83'], 'photo');
foreach (['class="au-treatment-bg"','data-image="/img_uploads/backdrop.jpg"','--au-treatment-image:url("/img_uploads/backdrop.jpg")','--au-treatment-position:center top','--au-treatment-overlay:rgba(0,0,0,0.55)','--au-navbar-bg-inner:rgba(10,14,26,0.83)'] as $value) special_check(str_contains($auroraTreatment, $value), "AURORA treatment/nav control missing: {$value}");

$jive = special_render('jive-turkey', ['jt_mode'=>'flow','jt_speed'=>'72','jt_cycle_time'=>'29','jt_panel_opacity'=>'61','jt_navbar_opacity'=>'24','jt_solo_scrim_opacity'=>'73']);
foreach (['data-jt-mode="flow"','data-jt-speed="72"','data-jt-cycle="29"','--panel-bg:rgba(255,255,255,0.61)','--jt-navbar-bg:rgba(255,255,255,0.24)','--jt-solo-scrim:rgba(0,0,0,0.73)','class="jt-panel"'] as $value) special_check(str_contains($jive, $value), "JIVE TURKEY control missing: {$value}");
$jiveTreatment = special_render('jive-turkey', ['jt_treatment_mode'=>'color','jt_treatment_color'=>'#123456','jt_treatment_overlay'=>'35','jt_navbar_opacity_inner'=>'79','jt_navline_shadow_size'=>'2','jt_navline_shadow_opacity'=>'75'], 'photo');
foreach (['class="jt-treatment-bg"','--jt-treatment-color:#123456','--jt-treatment-overlay:rgba(255,255,255,0.35)','--jt-navbar-bg-inner:rgba(255,255,255,0.79)','--jt-navline-shadow:0 2px 2px -2px'] as $value) special_check(str_contains($jiveTreatment, $value), "JIVE TURKEY treatment/nav control missing: {$value}");
special_check(snapsmack_declared_media_url(['x'=>'../config.php'], 'x') === '', 'Treatment media path traversal was accepted.');
$heuristicItems = snapsmack_heuristic_items([['post_id'=>7,'img_slug'=>'sample']], 'post:7=HAL|MEMORY BANK|fault|ordinal');
special_check(($heuristicItems[0]['heuristic'] ?? []) === ['code'=>'HAL','label'=>'MEMORY BANK','colour'=>'fault','value'=>'1','post'=>7], 'HEURISTIC CMS infomatic enrichment failed.');

$heuristic = special_render('heuristic', ['he_system_mode'=>'quiet','he_first_delay'=>'31','he_hold_seconds'=>'11','he_rest_seconds'=>'22','he_pulse_count'=>'2','he_memory_red'=>'#123456']);
foreach (['data-mode="quiet"','data-first-delay="31"','data-hold="11"','data-rest="22"','data-pulses="2"','--he-memory-red:#123456','data-heuristic-memory'] as $value) special_check(str_contains($heuristic, $value), "HEURISTIC control missing: {$value}");

$game = special_render('game-on', ['go_treatment_overlay'=>'-42','go_border_radius'=>'17']);
foreach (['--go-puzzle-tone:rgba(0,0,0,0.42)','--tile-radius:17px','data-game-on'] as $value) special_check(str_contains($game, $value), "GAME ON control missing: {$value}");
$gameSolo = special_render('game-on', [], 'photo');
foreach (['data-play-as-puzzle','data-focus-x="17"','data-focus-y="83"','data-zoom="240"'] as $value) special_check(str_contains($gameSolo, $value), "GAME ON solo puzzle action missing: {$value}");
$boundedFocus = snapsmack_game_on_focus_item(['img_focus_x'=>-5,'img_focus_y'=>150,'img_zoom'=>999]);
special_check($boundedFocus['img_focus_x'] === 0 && $boundedFocus['img_focus_y'] === 100 && $boundedFocus['img_zoom'] === 500, 'GAME ON focus/zoom bounds failed.');
$defaultFocus = snapsmack_game_on_focus_item([]);
special_check($defaultFocus['img_focus_x'] === 50 && $defaultFocus['img_focus_y'] === 50 && $defaultFocus['img_zoom'] === 100, 'GAME ON CMS focus/zoom defaults failed.');
special_check(!str_contains((string)file_get_contents(dirname(__DIR__).'/skins/game-on/layout.php'), "['img_focus_x'] ?? 50"), 'GAME ON skin duplicated the CMS focus control default.');
$perImageFrame = snapsmack_grid_frame_items([['img_size_pct'=>82,'img_border_px'=>6,'img_border_color'=>'#123456','img_bg_color'=>'#abcdef','img_shadow'=>'2']], ['go_customize_level'=>'per_image'], 'game-on');
foreach (['--tile-img-size:82%','--tile-border-w:6px','--tile-border-c:#123456','--tile-bg:#abcdef','--tile-shadow:6px 6px 18px'] as $value) special_check(str_contains($perImageFrame[0]['frame_style'], $value), "Per-image frame override missing: {$value}");
special_check($perImageFrame[0]['is_framed'] === true, 'Per-image framed state missing.');
$perCarouselFrame = snapsmack_grid_frame_items([['post_img_size_pct'=>91,'post_border_px'=>3,'post_border_color'=>'#654321','post_bg_color'=>'#fedcba','post_shadow'=>'1']], ['ic_customize_level'=>'per_carousel'], 'instant-camera');
foreach (['--tile-img-size:91%','--tile-border-w:3px','--tile-border-c:#654321','--tile-bg:#fedcba','--tile-shadow:3px 3px 8px'] as $value) special_check(str_contains($perCarouselFrame[0]['frame_style'], $value), "Per-carousel frame override missing: {$value}");

$parade = special_render('parade', ['pa_border_style'=>'sweep','pa_border_dir'=>'rtl','pa_border_rhythm'=>'constant','pa_wave_speed'=>'91','pa_border_opacity'=>'44']);
foreach (['data-pa-border-style="sweep"','data-pa-border-dir="rtl"','data-pa-border-rhythm="constant"','data-pa-border-cycle="91"','data-pa-border-minl="44"'] as $value) special_check(str_contains($parade, $value), "PARADE control missing: {$value}");

$instant = special_render('instant-camera', ['ic_bg_mode'=>'rainfall','ic_rf_density'=>'66','ic_rf_speed'=>'77','ic_rf_angle'=>'-23','ic_rf_thickness'=>'4','ic_rf_color'=>'#123456','ic_rf_opacity'=>'81']);
foreach (['data-rainfall','data-density="66"','data-speed="77"','data-angle="-23"','data-thickness="4"','data-color="#123456"','data-opacity="81"'] as $value) special_check(str_contains($instant, $value), "INSTANT CAMERA control missing: {$value}");

$chaplin = snapsmack_skin_presentation(['chap_line_count'=>'3','chap_line_1_width'=>'2','chap_line_2_width'=>'3','chap_line_3_width'=>'4','chap_line_gap'=>'5','chap_grain_intensity'=>'7'], 'chaplin');
foreach (['--chap-frame-total:19px','--chap-grain-opacity:0.07','--chap-frame-stripes:linear-gradient'] as $value) special_check(str_contains((string)$chaplin['style'], $value), "CHAPLIN control missing: {$value}");
$chaplinHtml = special_render('chaplin', ['chap_line_count'=>'3','chap_ornament_style'=>'D','chap_corner_ornaments'=>'1','chap_title_position'=>'below_photo','chap_card_style'=>'minimal','single_show_description'=>'0'], 'photo');
foreach (['data-chap-card="minimal"','class="p-name photo-title-footer"','class="chap-frame-deco"','/skins/chaplin/assets/svg/D-corner.svg','class="chap-frame-lines"'] as $value) special_check(str_contains($chaplinHtml, $value), "CHAPLIN presentation missing: {$value}");
special_check(!str_contains($chaplinHtml, 'id="infobox"'), 'CHAPLIN description visibility control was ignored.');

$slidersMin = snapsmack_skin_presentation(['sl_opacity'=>'0','sl_layer_color'=>'#123456','sl_wall_opacity'=>'15','sl_flow_axis'=>'horizontal','sl_travel'=>'0.2'], 'sliders');
$slidersMax = snapsmack_skin_presentation(['sl_opacity'=>'95','sl_layer_color'=>'#abcdef','sl_wall_opacity'=>'100','sl_flow_axis'=>'vertical','sl_travel'=>'1.5'], 'sliders');
foreach (['--ic-scrim:0.00','--sl-layer-color:#123456','--sl-wall-opacity:0.15'] as $value) special_check(str_contains((string)$slidersMin['style'], $value), "SLIDERS minimum control missing: {$value}");
foreach (['--ic-scrim:0.95','--sl-layer-color:#abcdef','--sl-wall-opacity:1.00'] as $value) special_check(str_contains((string)$slidersMax['style'], $value), "SLIDERS maximum control missing: {$value}");
special_check(($slidersMin['sliders']['flow_axis'] ?? '') === 'horizontal' && ($slidersMin['sliders']['travel'] ?? '') === '0.20', 'SLIDERS minimum movement model failed.');
special_check(($slidersMax['sliders']['flow_axis'] ?? '') === 'vertical' && ($slidersMax['sliders']['travel'] ?? '') === '1.50', 'SLIDERS maximum movement model failed.');

$presentationSource = (string)file_get_contents(dirname(__DIR__).'/core/skin-presentation.php');
foreach (["['au_palette'] ?? 'aurora'","['jt_palette'] ?? 'HARVEST'","['jt_mode'] ?? 'surprise'","['he_system_mode'] ?? 'live'","['chap_card_style'] ?? 'card'","['pa_border_style'] ?? 'circle'","['sl_flow_axis'] ?? 'diagonal_up'"] as $duplicateDefault) {
    special_check(!str_contains($presentationSource, $duplicateDefault), "Renderer duplicated manifest control default: {$duplicateDefault}");
}

echo "Specialized control authority regression passed.\n";
