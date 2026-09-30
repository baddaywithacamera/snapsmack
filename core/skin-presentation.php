<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
declare(strict_types=1);

require_once __DIR__ . '/trusted-html.php';
require_once __DIR__ . '/menu-presentation.php';

function snapsmack_skin_int(array $settings, string $key, int $default, int $min, int $max): int
{
    $value = filter_var($settings[$key] ?? $default, FILTER_VALIDATE_INT);
    return max($min, min($max, $value === false ? $default : $value));
}

function snapsmack_skin_hex(array $settings, string $key, string $default): string
{
    $value = trim((string)($settings[$key] ?? $default));
    return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : $default;
}

function snapsmack_skin_rgba(string $hex, int $opacity): string
{
    $hex = ltrim($hex, '#');
    return sprintf('rgba(%d,%d,%d,%.2F)', hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $opacity / 100);
}

function snapsmack_skin_glow(array $settings, string $prefix, int $defaultOpacity = 0): string
{
    $size = snapsmack_skin_int($settings, $prefix . '_size', 0, 0, 40);
    $opacity = snapsmack_skin_int($settings, $prefix . '_opacity', $defaultOpacity, 0, 100);
    if ($size === 0 || $opacity === 0) return 'none';
    $hex = ltrim(snapsmack_skin_hex($settings, $prefix . '_color', '#000000'), '#');
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    return sprintf('0 0 %dpx rgba(%d,%d,%d,%.2F),0 0 %dpx rgba(%d,%d,%d,%.2F)',
        $size, $r, $g, $b, $opacity / 100, $size * 2, $r, $g, $b, $opacity / 200);
}

function snapsmack_declared_option_int(array $options, string $key, int $min, int $max): int
{
    $value = $options[$key] ?? null;
    return is_numeric($value) ? max($min, min($max, (int)$value)) : $min;
}

function snapsmack_declared_option_hex(array $options, string $key): string
{
    $value = (string)($options[$key] ?? '');
    return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : '#000000';
}

function snapsmack_declared_option_glow(array $options, string $prefix): string
{
    $size = snapsmack_declared_option_int($options, $prefix . '_size', 0, 40);
    $opacity = snapsmack_declared_option_int($options, $prefix . '_opacity', 0, 100);
    if ($size === 0 || $opacity === 0) return 'none';
    $hex = ltrim(snapsmack_declared_option_hex($options, $prefix . '_color'), '#');
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    return sprintf('0 0 %dpx rgba(%d,%d,%d,%.2F),0 0 %dpx rgba(%d,%d,%d,%.2F)',
        $size, $r, $g, $b, $opacity / 100, $size * 2, $r, $g, $b, $opacity / 200);
}

/**
 * Validate the inert option vocabulary declared by a schema-v2 skin.
 *
 * The resulting values are display data, not authority: unknown settings are
 * discarded, strings are bounded, colours are validated, numbers are clamped,
 * and selects cannot escape their manifest choices.
 */
function snapsmack_declared_skin_options(array $settings, string $skinSlug): array
{
    $skinSlug = preg_replace('/[^a-z0-9-]/', '', strtolower($skinSlug));
    if ($skinSlug === '') return [];
    $path = dirname(__DIR__) . '/skins/' . $skinSlug . '/manifest.json';
    if (!is_file($path)) return [];
    try {
        $manifest = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return [];
    }
    if (($manifest['schema_version'] ?? 0) !== 2 || !is_array($manifest['options'] ?? null)) return [];

    $out = [];
    foreach ($manifest['options'] as $key => $definition) {
        if (!is_string($key) || !preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $key) || !is_array($definition)) continue;
        $value = $settings[$key] ?? ($definition['default'] ?? '');
        $type = strtolower((string)($definition['type'] ?? 'text'));
        if (in_array($type, ['checkbox', 'toggle', 'boolean'], true)) {
            $out[$key] = in_array((string)$value, ['1', 'true', 'on', 'yes'], true);
            continue;
        }
        if (in_array($type, ['range', 'range_numeric', 'number'], true)) {
            $min = is_numeric($definition['min'] ?? null) ? (float)$definition['min'] : -100000;
            $max = is_numeric($definition['max'] ?? null) ? (float)$definition['max'] : 100000;
            $number = is_numeric($value) ? (float)$value : (is_numeric($definition['default'] ?? null) ? (float)$definition['default'] : 0.0);
            $out[$key] = max($min, min($max, $number));
            continue;
        }
        if ($type === 'select' && is_array($definition['options'] ?? null)) {
            $allowed = array_map('strval', array_keys($definition['options']));
            $candidate = (string)$value;
            $fallback = (string)($definition['default'] ?? ($allowed[0] ?? ''));
            $out[$key] = in_array($candidate, $allowed, true) ? $candidate : $fallback;
            continue;
        }
        if (in_array($type, ['color', 'colour'], true)) {
            $candidate = trim((string)$value);
            $fallback = trim((string)($definition['default'] ?? '#000000'));
            $out[$key] = preg_match('/^#[0-9a-f]{6}$/i', $candidate) ? strtolower($candidate)
                : (preg_match('/^#[0-9a-f]{6}$/i', $fallback) ? strtolower($fallback) : '#000000');
            continue;
        }
        $out[$key] = substr(trim((string)$value), 0, 500);
    }
    return $out;
}

/** CMS-owned presentation model shared by every strict skin. */
function snapsmack_skin_presentation(array $settings, string $skinSlug): array
{
    $presentation = ['options' => snapsmack_declared_skin_options($settings, $skinSlug)];
    if ($skinSlug === 'instant-camera' || $skinSlug === 'sliders') {
        $presentation += snapsmack_instant_camera_presentation($presentation['options'], $settings);
    }
    if ($skinSlug === 'instant-camera') {
        $presentation['background'] = [
            'mode' => (string)($presentation['options']['ic_bg_mode'] ?? ''),
            'cycle' => snapsmack_declared_option_int($presentation['options'], 'ic_cycle_secs', 3, 120),
            'racetrack_speed' => snapsmack_declared_option_int($presentation['options'], 'ic_rt_speed', 1, 100),
            'racetrack_count' => snapsmack_declared_option_int($presentation['options'], 'ic_rt_count', 1, 200),
            'racetrack_size' => snapsmack_declared_option_int($presentation['options'], 'ic_rt_size', 40, 500),
            'racetrack_opacity' => snapsmack_declared_option_int($presentation['options'], 'ic_rt_opacity', 0, 100),
            'rainfall_density' => snapsmack_declared_option_int($presentation['options'], 'ic_rf_density', 1, 100),
            'rainfall_speed' => snapsmack_declared_option_int($presentation['options'], 'ic_rf_speed', 1, 100),
            'rainfall_angle' => snapsmack_declared_option_int($presentation['options'], 'ic_rf_angle', -90, 90),
            'rainfall_thickness' => snapsmack_declared_option_int($presentation['options'], 'ic_rf_thickness', 1, 20),
            'rainfall_color' => snapsmack_declared_option_hex($presentation['options'], 'ic_rf_color'),
            'rainfall_opacity' => snapsmack_declared_option_int($presentation['options'], 'ic_rf_opacity', 0, 100),
            'initial_count' => $presentation['mayhem_initial_count'],
            'max_width' => $presentation['mayhem_max_width'],
            'overlap_max' => $presentation['mayhem_overlap_max'],
            'drift' => $presentation['mayhem_drift'] ? '1' : '0',
            'warp' => $presentation['mayhem_warp'] ? '1' : '0',
        ];
    }
    if ($skinSlug === 'sliders') {
        $presentation += snapsmack_sliders_presentation($presentation['options']);
        $opacity = snapsmack_declared_option_int($presentation['options'], 'sl_opacity', 0, 95);
        $wallOpacity = snapsmack_declared_option_int($presentation['options'], 'sl_wall_opacity', 15, 100);
        $existing = isset($presentation['style']) ? (string)$presentation['style'] : '';
        $css = ':root{--ic-scrim:' . number_format($opacity / 100, 2, '.', '')
            . ';--sl-layer-color:' . snapsmack_declared_option_hex($presentation['options'], 'sl_layer_color')
            . ';--sl-wall-opacity:' . number_format($wallOpacity / 100, 2, '.', '') . ';}';
        $presentation['style'] = SnapTrustedHtml::__snapsmackCmsOnly($existing . '<style id="snapsmack-sliders-presentation">' . $css . '</style>');
    }
    if ($skinSlug === 'parade') {
        $presentation += snapsmack_parade_presentation($presentation['options']);
    }
    if ($skinSlug === 'aurora') {
        $presentation += snapsmack_aurora_presentation($presentation['options']);
    }
    if ($skinSlug === 'jive-turkey') {
        $presentation += snapsmack_jive_turkey_presentation($presentation['options']);
    }
    if ($skinSlug === 'heuristic') {
        $presentation += snapsmack_heuristic_presentation($presentation['options']);
    }
    if ($skinSlug === 'game-on') {
        $presentation += snapsmack_game_on_presentation($presentation['options']);
    }
    if ($skinSlug === 'chaplin') {
        $presentation += snapsmack_chaplin_presentation($presentation['options']);
    }
    if ($skinSlug === '52-card-pickup') {
        $presentation['mayhem'] = [
            'api_url' => '?ajax=mayhem',
            'initial_count' => snapsmack_skin_int($settings, 'mayhem_initial_count', 120, 40, 400),
            'max_width' => snapsmack_skin_int($settings, 'mayhem_max_width', 300, 120, 500),
            'overlap_max' => number_format(snapsmack_skin_int($settings, 'mayhem_overlap_max', 85, 40, 95) / 100, 2, '.', ''),
            'drift' => (($settings['mayhem_drift'] ?? '1') === '1') ? '1' : '0',
            'warp' => (($settings['mayhem_warp'] ?? '1') === '1') ? '1' : '0',
        ];
    }
    $framePrefixes = ['the-grid'=>'tg','sudden-impact'=>'tg','instant-camera'=>'ic','sliders'=>'ic',
        'aurora'=>'au','parade'=>'pa','jive-turkey'=>'jt','heuristic'=>'he','game-on'=>'go'];
    if (isset($framePrefixes[$skinSlug])) {
        $frameStyle = snapsmack_grid_frame_presentation($presentation['options'], $framePrefixes[$skinSlug]);
        if ($frameStyle !== '') {
            $existing = isset($presentation['style']) ? (string)$presentation['style'] : '';
            $presentation['style'] = SnapTrustedHtml::__snapsmackCmsOnly($existing . $frameStyle);
        }
    }
    return $presentation;
}

function snapsmack_sliders_presentation(array $options): array
{
    return ['sliders'=>[
        'flow_axis'=>(string)($options['sl_flow_axis'] ?? 'diagonal_up'),
        'travel'=>number_format((float)($options['sl_travel'] ?? 0.55), 2, '.', ''),
    ]];
}

/** Append a bounded, CMS-created custom-property block to a strict presentation. */
function snapsmack_presentation_style(array &$presentation, string $id, array $vars): void
{
    $css = ':root{';
    foreach ($vars as $name => $value) $css .= $name . ':' . $value . ';';
    $css .= '}';
    $existing = isset($presentation['style']) ? (string)$presentation['style'] : '';
    $presentation['style'] = SnapTrustedHtml::__snapsmackCmsOnly(
        $existing . '<style id="' . $id . '">' . str_replace('<', '\\3C ', $css) . '</style>'
    );
}

function snapsmack_aurora_presentation(array $options): array
{
    $palettes = [
        'aurora'=>['#56e86a','#7df06a','#56e86a','#2fe6a0','#56e86a','#39b6f0','#56e86a','#9bf25a','#56e86a','#2f7fe0','#56e86a','#f2d24a','#56e86a','#ff5566','#56e86a','#46c0c0'],
        'borealis-ice'=>['#7cffcb','#00cec9','#4899f0','#9bd7ff','#7cffcb'],
        'solar'=>['#61e96e','#d6f15a','#f0b429','#f0653e','#d6336c','#61e96e'],
    ];
    $key = (string)($options['au_palette'] ?? 'aurora');
    $panelOpacity = snapsmack_declared_option_int($options, 'au_panel_opacity', 0, 100);
    $navOpacity = snapsmack_declared_option_int($options, 'au_navbar_opacity', 0, 100);
    $borderStyle = (string)($options['au_border_style'] ?? 'circle');
    $radius = ['square'=>'0px','rounded'=>'8px','circle'=>'50%','auto'=>'0px'][$borderStyle] ?? '50%';
    $result = ['aurora'=>[
        'palette'=>json_encode($palettes[$key] ?? $palettes['aurora'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'cycle'=>snapsmack_declared_option_int($options, 'au_cycle_time', 15, 240),
        'opacity'=>number_format(snapsmack_declared_option_int($options, 'au_l1_opacity', 5, 100) / 100, 2, '.', ''),
        'sky'=>(string)($options['au_sky'] ?? '#000000'),
        'border_style'=>$borderStyle,
        'border_dir'=>(string)($options['au_wave_direction'] ?? 'ltr'),
        'border_rhythm'=>(string)($options['au_wave_rhythm'] ?? 'breath'),
        'border_cycle'=>snapsmack_declared_option_int($options, 'au_wave_speed', 20, 600),
    ]];
    snapsmack_presentation_style($result, 'snapsmack-aurora-presentation', [
        '--au-sky'=>snapsmack_declared_option_hex($options, 'au_sky'),
        '--tile-bw'=>snapsmack_declared_option_int($options, 'au_border_width', 0, 40).'px',
        '--tile-radius'=>$radius,
        '--ring-op'=>number_format(snapsmack_declared_option_int($options, 'au_border_opacity', 0, 100)/100, 2, '.', ''),
        '--profile-text-glow'=>snapsmack_declared_option_glow($options, 'au_glow'),
        '--nav-text-glow'=>snapsmack_declared_option_glow($options, 'au_nav_glow'),
        '--posts-glow'=>snapsmack_declared_option_glow($options, 'au_posts_glow'),
        '--post-count-color'=>snapsmack_declared_option_hex($options, 'au_posts_color'),
        '--panel-bg'=>$panelOpacity ? snapsmack_skin_rgba(snapsmack_declared_option_hex($options, 'au_panel_color'), $panelOpacity) : 'transparent',
        '--panel-extend'=>snapsmack_declared_option_int($options, 'au_panel_extend', 0, 100).'px',
        '--au-navbar-bg'=>$navOpacity ? snapsmack_skin_rgba(snapsmack_declared_option_hex($options, 'au_navbar_color'), $navOpacity) : 'transparent',
        '--nav-line-opacity'=>number_format(snapsmack_declared_option_int($options, 'au_nav_line_opacity', 0, 100)/100, 2, '.', ''),
        '--nav-line-underline-display'=>(string)($options['au_nav_underline'] ?? '1') === '1' ? 'block' : 'none',
    ]);
    return $result;
}

function snapsmack_jive_turkey_presentation(array $options): array
{
    $palettes = [
        'HARVEST'=>['#d99a2b','#bd4e1f','#6b3f24','#e7be59'],
        'PEACOCK'=>['#087e8b','#0b3954','#bfd7ea','#ff5a5f'],
        'DISCO'=>['#ff2e93','#7b2cff','#00d4ff','#f9f871'],
    ];
    $key = strtoupper((string)($options['jt_palette'] ?? 'HARVEST'));
    $colors = $palettes[$key] ?? $palettes['HARVEST'];
    $panelOpacity = snapsmack_declared_option_int($options, 'jt_panel_opacity', 0, 100);
    $navOpacity = snapsmack_declared_option_int($options, 'jt_navbar_opacity', 0, 100);
    $footerOpacity = snapsmack_declared_option_int($options, 'jt_footer_opacity', 0, 100);
    $result = ['jive'=>[
        'mode'=>(string)($options['jt_mode'] ?? 'surprise'), 'scrolls_axis'=>(string)($options['jt_scrolls_axis'] ?? 'down'),
        'scrolls_fade'=>(string)($options['jt_scrolls_colour'] ?? 'fade'), 'colourway'=>$key,
        'palette'=>json_encode($colors, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'field'=>'#f2e2c0',
        'speed'=>snapsmack_declared_option_int($options, 'jt_speed', 1, 100), 'cycle'=>snapsmack_declared_option_int($options, 'jt_cycle_time', 6, 120),
        'random'=>(string)($options['jt_random_colour'] ?? '1') === '1' ? '1' : '0', 'border'=>(string)($options['jt_border_on'] ?? '1') === '1' ? '1' : '0',
        'border_width'=>snapsmack_declared_option_int($options, 'jt_border_width', 0, 40), 'border_speed'=>snapsmack_declared_option_int($options, 'jt_border_speed', 1, 100),
        'border_wave'=>snapsmack_declared_option_int($options, 'jt_border_wave', 0, 100), 'border_trans'=>snapsmack_declared_option_int($options, 'jt_border_trans', 0, 100),
        'border_dir'=>(string)($options['jt_border_dir'] ?? 'dtlbr'),
    ]];
    snapsmack_presentation_style($result, 'snapsmack-jive-presentation', [
        '--panel-bg'=>$panelOpacity ? snapsmack_skin_rgba(snapsmack_declared_option_hex($options, 'jt_panel_color'), $panelOpacity) : 'transparent',
        '--panel-extend'=>snapsmack_declared_option_int($options, 'jt_panel_extend', 0, 100).'px',
        '--jt-navbar-bg'=>$navOpacity ? snapsmack_skin_rgba(snapsmack_declared_option_hex($options, 'jt_navbar_color'), $navOpacity) : 'transparent',
        '--footer-gap'=>snapsmack_declared_option_int($options, 'jt_footer_gap', 0, 200).'px',
        '--jt-footer-bg'=>$footerOpacity ? snapsmack_skin_rgba(snapsmack_declared_option_hex($options, 'jt_footer_color'), $footerOpacity) : 'transparent',
        '--profile-text-glow'=>snapsmack_declared_option_glow($options, 'jt_glow'), '--nav-text-glow'=>snapsmack_declared_option_glow($options, 'jt_nav_glow'),
        '--posts-glow'=>snapsmack_declared_option_glow($options, 'jt_posts_glow'), '--post-count-color'=>snapsmack_declared_option_hex($options, 'jt_posts_color'),
        '--jt-solo-scrim'=>snapsmack_skin_rgba(snapsmack_declared_option_hex($options, 'jt_solo_scrim_color'), snapsmack_declared_option_int($options, 'jt_solo_scrim_opacity', 0, 100)),
        '--jt-solo-pad'=>snapsmack_declared_option_int($options, 'jt_solo_pad', 0, 200).'px',
    ]);
    return $result;
}

function snapsmack_heuristic_presentation(array $options): array
{
    $result = ['heuristic'=>[
        'mode'=>(string)($options['he_system_mode'] ?? 'live'), 'memory'=>($options['he_memory_activity'] ?? '1') ? '1' : '0',
        'infomatics'=>($options['he_infomatics'] ?? '1') ? '1' : '0', 'classic_fallback'=>($options['he_classic_fallback'] ?? '1') ? '1' : '0',
        'first_delay'=>snapsmack_declared_option_int($options, 'he_first_delay', 0, 120), 'hold'=>snapsmack_declared_option_int($options, 'he_hold_seconds', 1, 120),
        'rest'=>snapsmack_declared_option_int($options, 'he_rest_seconds', 1, 120), 'pulses'=>snapsmack_declared_option_int($options, 'he_pulse_count', 0, 3),
    ]];
    snapsmack_presentation_style($result, 'snapsmack-heuristic-presentation', [
        '--he-memory-red'=>snapsmack_declared_option_hex($options, 'he_memory_red'), '--he-calm'=>snapsmack_declared_option_hex($options, 'he_calm_color'),
        '--he-fault'=>snapsmack_declared_option_hex($options, 'he_fault_color'),
    ]);
    return $result;
}

function snapsmack_game_on_presentation(array $options): array
{
    $overlay = snapsmack_declared_option_int($options, 'go_treatment_overlay', -100, 100);
    $tone = $overlay < 0 ? snapsmack_skin_rgba('#000000', abs($overlay)) : ($overlay > 0 ? snapsmack_skin_rgba('#ffffff', $overlay) : 'transparent');
    $result = [];
    snapsmack_presentation_style($result, 'snapsmack-game-on-presentation', [
        '--go-puzzle-tone'=>$tone, '--tile-radius'=>snapsmack_declared_option_int($options, 'go_border_radius', 0, 100).'px',
    ]);
    return $result;
}

function snapsmack_chaplin_presentation(array $options): array
{
    $count = snapsmack_declared_option_int($options, 'chap_line_count', 0, 3);
    $widths = [snapsmack_declared_option_int($options, 'chap_line_1_width', 1, 20), snapsmack_declared_option_int($options, 'chap_line_2_width', 1, 20), snapsmack_declared_option_int($options, 'chap_line_3_width', 1, 20)];
    $gap = snapsmack_declared_option_int($options, 'chap_line_gap', 0, 40);
    $total = $count > 0 ? array_sum(array_slice($widths, 0, $count)) + $gap * max(0, $count - 1) : 0;
    $stops = []; $at = 0;
    for ($i = 0; $i < $count; $i++) {
        if ($i > 0) { $stops[] = 'transparent '.$at.'px'; $at += $gap; $stops[] = 'transparent '.$at.'px'; }
        $stops[] = '#f0f0f0 '.$at.'px'; $at += $widths[$i]; $stops[] = '#f0f0f0 '.$at.'px';
    }
    $stripe = $stops ? implode(',', $stops) : 'transparent 0';
    $result = ['chaplin'=>[
        'line_count'=>$count,
        'ornament_style'=>(string)($options['chap_ornament_style'] ?? 'A'),
        'corners'=>(string)($options['chap_corner_ornaments'] ?? '1') === '1',
        'mid_tb'=>(string)($options['chap_mid_top_bot'] ?? '0') === '1',
        'mid_lr'=>(string)($options['chap_mid_left_right'] ?? '0') === '1',
        'title_position'=>(string)($options['chap_title_position'] ?? 'below_photo'),
        'card_style'=>(string)($options['chap_card_style'] ?? 'card'),
        'show_description'=>(string)($options['single_show_description'] ?? '1') === '1',
    ]];
    snapsmack_presentation_style($result, 'snapsmack-chaplin-presentation', [
        '--chap-grain-opacity'=>number_format(snapsmack_declared_option_int($options, 'chap_grain_intensity', 0, 20)/100, 2, '.', ''),
        '--chap-frame-gap'=>snapsmack_declared_option_int($options, 'chap_frame_gap', 0, 80).'px', '--chap-orn-gap'=>snapsmack_declared_option_int($options, 'chap_ornament_gap', 0, 80).'px',
        '--chap-photo-pad-v'=>snapsmack_declared_option_int($options, 'chap_photo_pad_v', 0, 200).'px',
        '--chap-frame-total'=>$total.'px', '--chap-frame-stripes'=>'linear-gradient(to bottom,'.$stripe.')',
    ]);
    return $result;
}

/** Map manifest-owned grid frame controls to the variables consumed by skin CSS. */
function snapsmack_grid_frame_presentation(array $options, string $prefix): string
{
    $sizeKey = $prefix . '_frame_size_pct';
    if (!array_key_exists($sizeKey, $options)) return '';
    $borderKey = $prefix . '_frame_border_px';
    $colorKey = $prefix . '_frame_border_color';
    $backgroundKey = $prefix . '_frame_bg_color';
    $shadowKey = $prefix . '_frame_shadow';
    $shadowMap = ['0'=>'none','1'=>'3px 3px 8px rgba(0,0,0,.20)','2'=>'6px 6px 18px rgba(0,0,0,.40)','3'=>'12px 12px 32px rgba(0,0,0,.60)'];
    $size = snapsmack_declared_option_int($options, $sizeKey, 1, 100) . '%';
    $border = snapsmack_declared_option_int($options, $borderKey, 0, 100) . 'px';
    $color = snapsmack_declared_option_hex($options, $colorKey);
    $background = snapsmack_declared_option_hex($options, $backgroundKey);
    $shadow = $shadowMap[(string)($options[$shadowKey] ?? '')] ?? 'none';
    $css = ':root{--tile-img-size:' . $size . ';--slide-img-size:' . $size
        . ';--tile-border-w:' . $border . ';--slide-border-w:' . $border
        . ';--tile-border-c:' . $color . ';--slide-border-c:' . $color
        . ';--tile-bg:' . $background . ';--slide-bg:' . $background
        . ';--tile-shadow:' . $shadow . ';--slide-shadow:' . $shadow . ';}';
    return '<style id="snapsmack-grid-frame-presentation">' . $css . '</style>';
}

/** CMS-owned interpretation of PARADE controls and inert flag geometry. */
function snapsmack_parade_presentation(array $options): array
{
    $palettes = [
        'rainbow'=>['#e40303','#ff8c00','#ffed00','#008026','#004dff','#750787'],
        'trans'=>['#5bcffb','#f5abb9','#ffffff','#f5abb9','#5bcffb'],
        'bi'=>['#d60270','#d60270','#9b4f96','#0038a8','#0038a8'],
        'nonbinary'=>['#fff430','#ffffff','#9c59d1','#000000'],
        'pan'=>['#ff218c','#ffd800','#21b1ff'],
        'lesbian'=>['#d52d00','#ef7627','#ff9a56','#ffffff','#d162a4','#b55690','#a30262'],
        'asexual'=>['#000000','#a3a3a3','#ffffff','#800080'],
        'aromantic'=>['#3da542','#a7d379','#ffffff','#a9a9a9','#000000'],
        'genderfluid'=>['#ff75a2','#ffffff','#be18d6','#000000','#333ebd'],
        'genderqueer'=>['#b57edc','#ffffff','#4a8123'],
        'progress'=>['#e40303','#ff8c00','#ffed00','#008026','#004dff','#750787'],
        'two-spirit'=>['#e40303','#ff8c00','#ffed00','#008026','#004dff','#750787'],
    ];
    $paletteKey = (string)($options['pa_palette'] ?? '');
    $colors = $palettes[$paletteKey] ?? [];
    $panelOpacity = snapsmack_declared_option_int($options, 'pa_panel_opacity', 0, 100);
    $navOpacity = snapsmack_declared_option_int($options, 'pa_navbar_opacity', 0, 100);
    $vars = [
        '--panel-bg' => $panelOpacity > 0 ? snapsmack_skin_rgba(snapsmack_declared_option_hex($options, 'pa_panel_color'), $panelOpacity) : 'transparent',
        '--panel-extend' => snapsmack_declared_option_int($options, 'pa_panel_extend', 0, 100) . 'px',
        '--pa-navbar-bg' => $navOpacity > 0 ? snapsmack_skin_rgba(snapsmack_declared_option_hex($options, 'pa_navbar_color'), $navOpacity) : 'transparent',
        '--post-count-color' => snapsmack_declared_option_hex($options, 'pa_posts_color'),
        '--pa-nav-line' => snapsmack_declared_option_hex($options, 'pa_nav_line_color'),
        '--nav-line-opacity' => number_format(snapsmack_declared_option_int($options, 'pa_nav_line_opacity', 0, 100) / 100, 2, '.', ''),
    ];
    $css = ':root{';
    foreach ($vars as $name => $value) $css .= $name . ':' . $value . ';';
    $css .= '}';
    return [
        'style' => SnapTrustedHtml::__snapsmackCmsOnly('<style id="snapsmack-parade-presentation">' . $css . '</style>'),
        'flag' => [
            'stripes' => json_encode($colors, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'orientation' => 'horizontal',
            'speed' => snapsmack_declared_option_int($options, 'pa_flag_speed', 1, 100),
            'amplitude' => snapsmack_declared_option_int($options, 'pa_flag_amplitude', 1, 100),
            'opacity' => snapsmack_declared_option_int($options, 'pa_flag_opacity', 0, 100),
            'border_style' => (string)($options['pa_border_style'] ?? 'circle'),
            'border_dir' => (string)($options['pa_border_dir'] ?? 'dtlbr'),
            'border_rhythm' => (string)($options['pa_border_rhythm'] ?? 'breath'),
            'border_cycle' => snapsmack_declared_option_int($options, 'pa_wave_speed', 20, 600),
            'border_minl' => snapsmack_declared_option_int($options, 'pa_border_opacity', 0, 100),
        ],
    ];
}

/** CMS-owned, presentation-only interpretation of INSTANT CAMERA settings. */
function snapsmack_instant_camera_presentation(array $options, array $settings = []): array
{
    $ratios = ['polaroid'=>'823 / 1000','sx70'=>'1 / 1','go'=>'47 / 60','instax_mini'=>'62 / 46','instax_wide'=>'99 / 62','instax_square'=>'1 / 1'];
    $format = (string)($options['ic_format'] ?? '');
    $aspect = $ratios[$format] ?? '1 / 1';
    if ($format === 'custom' && preg_match('/^\s*(\d{1,4})\s*[:\/xX]\s*(\d{1,4})\s*$/', (string)($options['ic_custom_ratio'] ?? ''), $m) && (int)$m[1] > 0 && (int)$m[2] > 0) {
        $aspect = (int)$m[1] . ' / ' . (int)$m[2];
    }
    $shadowMap = ['0'=>'none','1'=>'3px 3px 8px rgba(0,0,0,.20)','2'=>'6px 6px 18px rgba(0,0,0,.40)','3'=>'12px 12px 32px rgba(0,0,0,.60)'];
    $panelOpacity = snapsmack_declared_option_int($options, 'ic_panel_opacity', 0, 100);
    $navOpacity = snapsmack_declared_option_int($options, 'ic_nav_opacity', 0, 100);
    $soloOpacity = snapsmack_declared_option_int($options, 'ic_solo_bg_opacity', 0, 100);
    $lineShadowSize = snapsmack_declared_option_int($options, 'ic_navline_shadow_size', 0, 3);
    $lineShadowOpacity = snapsmack_declared_option_int($options, 'ic_navline_shadow_opacity', 0, 100);
    $lineShadow = 'none';
    if ($lineShadowSize > 0 && $lineShadowOpacity > 0) {
        $hex = ltrim(snapsmack_declared_option_hex($options, 'ic_navline_shadow_color'), '#');
        [$r,$g,$b] = [hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
        $n = $lineShadowSize;
        $alpha = number_format($lineShadowOpacity / 100, 2, '.', '');
        $lineShadow = "0 {$n}px {$n}px -{$n}px rgba({$r},{$g},{$b},{$alpha}),"
            . "inset 0 {$n}px {$n}px -{$n}px rgba({$r},{$g},{$b},{$alpha})";
    }
    $vars = [
        '--ic-tile-aspect'=>$aspect,
        '--ic-tile-shadow'=>$shadowMap[(string)($options['ic_frame_shadow'] ?? '')] ?? 'none',
        '--ic-scrim'=>number_format(snapsmack_declared_option_int($options, 'ic_scrim', 10, 90) / 100, 2, '.', ''),
        '--tile-radius'=>'0px',
        '--profile-text-glow'=>snapsmack_declared_option_glow($options, 'ic_glow'),
        '--bio-text-glow'=>snapsmack_declared_option_glow($options, 'ic_bio_glow'),
        '--nav-text-glow'=>snapsmack_declared_option_glow($options, 'ic_nav_glow'),
        '--panel-bg'=>$panelOpacity ? snapsmack_skin_rgba(snapsmack_declared_option_hex($options, 'ic_panel_color'), $panelOpacity) : 'transparent',
        '--panel-extend'=>snapsmack_declared_option_int($options, 'ic_panel_extend', 0, 100) . 'px',
        '--ic-nav-bg'=>$navOpacity ? snapsmack_skin_rgba(snapsmack_declared_option_hex($options, 'ic_nav_color'), $navOpacity) : 'transparent',
        '--posts-color'=>snapsmack_declared_option_hex($options, 'ic_posts_color'),
        '--posts-glow'=>snapsmack_declared_option_glow($options, 'ic_posts_glow'),
        '--ic-navline-color'=>snapsmack_declared_option_hex($options, 'ic_navline_color'),
        '--ic-navline-opacity'=>(string)snapsmack_declared_option_int($options, 'ic_navline_opacity', 0, 100),
        '--ic-navline-shadow'=>$lineShadow,
        '--post-bg'=>snapsmack_skin_rgba(snapsmack_declared_option_hex($options, 'ic_solo_bg_color'), $soloOpacity),
    ];
    $css = ':root{';
    foreach ($vars as $name => $value) $css .= $name . ':' . $value . ';';
    $menuAppearance = snapsmack_menu_appearance($settings);
    $css .= '--nav-dropdown-bg:' . $menuAppearance['nav_dropdown_bg'] . ';'
        . '--nav-dropdown-text:' . $menuAppearance['nav_dropdown_text'] . ';}'
        . '.nav-has-children{position:relative}.nav-submenu{display:none;position:absolute;z-index:1000;top:100%;left:0;min-width:180px;margin:0;padding:8px 0;list-style:none;background:color-mix(in srgb,var(--nav-dropdown-bg) '
        . $menuAppearance['nav_dropdown_opacity'] . '%,transparent)}'
        . '.nav-has-children:hover>.nav-submenu,.nav-has-children.open>.nav-submenu{display:block}.nav-submenu li{display:block}.nav-submenu a,.nav-submenu span{display:block;padding:8px 14px;white-space:nowrap;color:var(--nav-dropdown-text)}'
        . '.nav-submenu .nav-submenu{top:0;left:100%}';
    return [
        'style' => SnapTrustedHtml::__snapsmackCmsOnly('<style id="snapsmack-skin-presentation">' . str_replace('<', '\\3C ', $css) . '</style>'),
        'mayhem_initial_count' => snapsmack_skin_int($settings, 'mayhem_initial_count', 120, 40, 400),
        'mayhem_max_width' => snapsmack_skin_int($settings, 'mayhem_max_width', 300, 120, 500),
        'mayhem_overlap_max' => number_format(snapsmack_skin_int($settings, 'mayhem_overlap_max', 85, 40, 95) / 100, 2, '.', ''),
        'mayhem_drift' => (($settings['mayhem_drift'] ?? '1') === '1'),
        'mayhem_warp' => (($settings['mayhem_warp'] ?? '1') === '1'),
    ];
}

/** CMS-owned, bounded presentation data for the shared floating search dock. */
function snapsmack_gram_search_dock_presentation(array $settings): array
{
    if (($settings['search_enabled'] ?? '0') !== '1') return ['enabled' => false];
    return [
        'enabled' => true,
        'placeholder' => substr(trim((string)($settings['search_placeholder'] ?? 'Search or #tag…')), 0, 120),
        'disc_color' => snapsmack_skin_hex($settings, 'gsd_disc_color', '#ffffff'),
        'disc_opacity' => snapsmack_skin_int($settings, 'gsd_disc_opacity', 100, 0, 100),
        'glass_color' => snapsmack_skin_hex($settings, 'gsd_glass_color', '#262626'),
    ];
}
// ===== SNAPSMACK EOF =====
