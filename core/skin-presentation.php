<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
declare(strict_types=1);

require_once __DIR__ . '/trusted-html.php';

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

/** CMS-owned, presentation-only interpretation of INSTANT CAMERA settings. */
function snapsmack_instant_camera_presentation(array $settings): array
{
    $ratios = ['polaroid'=>'823 / 1000','sx70'=>'1 / 1','go'=>'47 / 60','instax_mini'=>'62 / 46','instax_wide'=>'99 / 62','instax_square'=>'1 / 1'];
    $format = (string)($settings['ic_format'] ?? 'instax_square');
    $aspect = $ratios[$format] ?? '1 / 1';
    if ($format === 'custom' && preg_match('/^\s*(\d{1,4})\s*[:\/xX]\s*(\d{1,4})\s*$/', (string)($settings['ic_custom_ratio'] ?? ''), $m) && (int)$m[1] > 0 && (int)$m[2] > 0) {
        $aspect = (int)$m[1] . ' / ' . (int)$m[2];
    }
    $shadowMap = ['0'=>'none','1'=>'3px 3px 8px rgba(0,0,0,.20)','2'=>'6px 6px 18px rgba(0,0,0,.40)','3'=>'12px 12px 32px rgba(0,0,0,.60)'];
    $panelOpacity = snapsmack_skin_int($settings, 'ic_panel_opacity', 50, 0, 100);
    $navOpacity = snapsmack_skin_int($settings, 'ic_nav_opacity', 50, 0, 100);
    $soloOpacity = snapsmack_skin_int($settings, 'ic_solo_bg_opacity', 100, 0, 100);
    $lineShadowSize = snapsmack_skin_int($settings, 'ic_navline_shadow_size', 0, 0, 3);
    $lineShadowOpacity = snapsmack_skin_int($settings, 'ic_navline_shadow_opacity', 40, 0, 100);
    $lineShadow = 'none';
    if ($lineShadowSize > 0 && $lineShadowOpacity > 0) {
        $hex = ltrim(snapsmack_skin_hex($settings, 'ic_navline_shadow_color', '#000000'), '#');
        [$r,$g,$b] = [hexdec(substr($hex,0,2)),hexdec(substr($hex,2,2)),hexdec(substr($hex,4,2))];
        $n = $lineShadowSize;
        $alpha = number_format($lineShadowOpacity / 100, 2, '.', '');
        $lineShadow = "0 {$n}px {$n}px -{$n}px rgba({$r},{$g},{$b},{$alpha}),"
            . "inset 0 {$n}px {$n}px -{$n}px rgba({$r},{$g},{$b},{$alpha})";
    }
    $vars = [
        '--ic-tile-aspect'=>$aspect,
        '--ic-tile-shadow'=>$shadowMap[(string)($settings['ic_frame_shadow'] ?? '0')] ?? 'none',
        '--ic-scrim'=>number_format(snapsmack_skin_int($settings, 'ic_scrim', 60, 10, 90) / 100, 2, '.', ''),
        '--tile-radius'=>'0px',
        '--profile-text-glow'=>snapsmack_skin_glow($settings, 'ic_glow'),
        '--bio-text-glow'=>snapsmack_skin_glow($settings, 'ic_bio_glow'),
        '--nav-text-glow'=>snapsmack_skin_glow($settings, 'ic_nav_glow', 45),
        '--panel-bg'=>$panelOpacity ? snapsmack_skin_rgba(snapsmack_skin_hex($settings, 'ic_panel_color', '#ffffff'), $panelOpacity) : 'transparent',
        '--panel-extend'=>snapsmack_skin_int($settings, 'ic_panel_extend', 0, 0, 100) . 'px',
        '--ic-nav-bg'=>$navOpacity ? snapsmack_skin_rgba(snapsmack_skin_hex($settings, 'ic_nav_color', '#ffffff'), $navOpacity) : 'transparent',
        '--posts-color'=>snapsmack_skin_hex($settings, 'ic_posts_color', '#777777'),
        '--posts-glow'=>snapsmack_skin_glow($settings, 'ic_posts_glow'),
        '--ic-navline-color'=>snapsmack_skin_hex($settings, 'ic_navline_color', '#e0e0e0'),
        '--ic-navline-opacity'=>(string)snapsmack_skin_int($settings, 'ic_navline_opacity', 100, 0, 100),
        '--ic-navline-shadow'=>$lineShadow,
        '--post-bg'=>snapsmack_skin_rgba(snapsmack_skin_hex($settings, 'ic_solo_bg_color', '#000000'), $soloOpacity),
    ];
    $css = ':root{';
    foreach ($vars as $name => $value) $css .= $name . ':' . $value . ';';
    $css .= '--nav-dropdown-bg:' . snapsmack_skin_hex($settings, 'nav_dropdown_bg', '#000000') . ';'
        . '--nav-dropdown-text:' . snapsmack_skin_hex($settings, 'nav_dropdown_text', '#ffffff') . ';}'
        . '.nav-has-children{position:relative}.nav-submenu{display:none;position:absolute;z-index:1000;top:100%;left:0;min-width:180px;margin:0;padding:8px 0;list-style:none;background:color-mix(in srgb,var(--nav-dropdown-bg) '
        . snapsmack_skin_int($settings, 'nav_dropdown_opacity', 88, 0, 100) . '%,transparent)}'
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
