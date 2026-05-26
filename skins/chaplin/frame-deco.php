<?php
/**
 * SNAPSMACK - Chaplin skin: Art Deco ornament overlay
 *
 * Renders positioned ornament divs around the photobox.
 * Lines/borders are handled via CSS (outline + box-shadow on .chap-photo
 * emitted by skin-header.php). This file handles ornament SVG placement only.
 *
 * SVG source files: skins/chaplin/assets/svg/{style}-{shape}.svg
 *
 * Include inside #rg-photobox, before .rg-photo-wrap.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$orn_style    = $settings['chap_ornament_style']   ?? 'A';
$show_corners = ($settings['chap_corner_ornaments'] ?? '1') === '1';
$show_mid_tb  = ($settings['chap_mid_top_bot']      ?? '0') === '1';
$show_mid_lr  = ($settings['chap_mid_left_right']   ?? '0') === '1';
$lcolor       = '#ece6d4';

if ($orn_style === 'none' || (!$show_corners && !$show_mid_tb && !$show_mid_lr)) {
    return;
}

$orn_dir = __DIR__ . '/assets/svg/';

/**
 * Inline an SVG ornament file, stripping width/height attrs and injecting
 * stroke/fill colour so it renders as $color on whatever background.
 */
function chap_orn_element(string $class, string $svg_path, string $color, string $constraint = 'both'): string {
    if (!file_exists($svg_path)) return '';
    $raw = @file_get_contents($svg_path);
    if (!$raw) return '';
    $raw = preg_replace('/<\?xml[^>]*\?>\s*/i', '', $raw);
    $raw = preg_replace('/(<svg\b[^>]*)\s+width="[^"]*"/i',  '$1', $raw);
    $raw = preg_replace('/(<svg\b[^>]*)\s+height="[^"]*"/i', '$1', $raw);
    $c   = htmlspecialchars($color);
    $sty = $constraint === 'width'  ? 'width:100%;height:auto;display:block;'
         : ($constraint === 'height' ? 'height:100%;width:auto;display:block;'
         : 'width:100%;height:100%;display:block;');
    $raw = preg_replace('/(<svg\b[^>]*)>/i',
        '$1 style="' . $sty . '" stroke="' . $c . '">', $raw, 1);
    $raw = str_ireplace('fill="#ece6d4"', 'fill="' . $c . '"', $raw);
    $raw = str_ireplace('fill="#0a0a0a"', 'fill="none"',        $raw);
    return '<div class="chap-orn ' . $class . '">' . trim($raw) . '</div>' . "\n";
}

$orn_corner = $orn_dir . $orn_style . '-corner.svg';
$orn_top    = $orn_dir . $orn_style . '-top.svg';
$orn_side   = $orn_dir . $orn_style . '-side.svg';

$orn_html = '';

if ($show_corners) {
    $orn_html .= chap_orn_element('chap-orn-tl', $orn_corner, $lcolor, 'both');
    $orn_html .= chap_orn_element('chap-orn-tr', $orn_corner, $lcolor, 'both');
    $orn_html .= chap_orn_element('chap-orn-bl', $orn_corner, $lcolor, 'both');
    $orn_html .= chap_orn_element('chap-orn-br', $orn_corner, $lcolor, 'both');
}

if ($show_mid_tb) {
    $orn_html .= chap_orn_element('chap-orn-mid-top', $orn_top,  $lcolor, 'width');
    $orn_html .= chap_orn_element('chap-orn-mid-bot', $orn_top,  $lcolor, 'width');
}

if ($show_mid_lr) {
    $orn_html .= chap_orn_element('chap-orn-mid-lft', $orn_side, $lcolor, 'height');
    $orn_html .= chap_orn_element('chap-orn-mid-rgt', $orn_side, $lcolor, 'height');
}

if ($orn_html):
?>
<div class="chap-frame-deco" aria-hidden="true">
<?php echo $orn_html; ?>
</div>
<?php endif; ?>
<?php // ===== SNAPSMACK EOF =====
