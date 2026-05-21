<?php
/**
 * SNAPSMACK - Chaplin skin: Art Deco ornament frame overlay
 *
 * Reads skin settings and outputs a lines SVG + positioned ornament divs.
 * Include inside .chap-presentation, before .chap-frame-area.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$deco_style = $settings['chap_deco_style']      ?? 'corners';
$orn_style  = $settings['chap_ornament_style']   ?? 'A';
$line_count = (int)($settings['chap_line_count'] ?? 1);
$l1w        = (float)($settings['chap_line_1_width'] ?? 2.0);
$l2w        = (float)($settings['chap_line_2_width'] ?? 1.0);
$l3w        = (float)($settings['chap_line_3_width'] ?? 1.0);
$lgap       = (int)($settings['chap_line_gap']   ?? 8);
$lcolor     = $settings['chap_line_color']       ?? '#ece6d4';
$lc         = htmlspecialchars($lcolor);

// ---------- ViewBox geometry (0-1000 coordinate space) --------------------
//   I   = inset from SVG edge where outermost line sits
//   ARM = corner arm length along each edge
//   MH  = half-width of mid-ornament gap zone (500 +/- MH)

$I   = 35;
$ARM = 210;
$MH  = 165;

function chap_deco_paths(string $style, int $I, int $ARM, int $MH): array {
    $W  = 1000;
    $MG = 500 - $MH;
    $paths = [];
    if ($style === 'corners') {
        $paths[] = "M $I," . ($I + $ARM) . " L $I,$I L " . ($I + $ARM) . ",$I";
        $paths[] = "M " . ($W - $I - $ARM) . ",$I L " . ($W - $I) . ",$I L " . ($W - $I) . "," . ($I + $ARM);
        $paths[] = "M $I," . ($W - $I - $ARM) . " L $I," . ($W - $I) . " L " . ($I + $ARM) . "," . ($W - $I);
        $paths[] = "M " . ($W - $I - $ARM) . "," . ($W - $I) . " L " . ($W - $I) . "," . ($W - $I) . " L " . ($W - $I) . "," . ($W - $I - $ARM);
    } elseif ($style === 'mid-breaks') {
        $paths[] = "M $I,$I L $MG,$I";
        $paths[] = "M " . ($W - $MG) . ",$I L " . ($W - $I) . ",$I";
        $paths[] = "M $I," . ($W - $I) . " L $MG," . ($W - $I);
        $paths[] = "M " . ($W - $MG) . "," . ($W - $I) . " L " . ($W - $I) . "," . ($W - $I);
        $paths[] = "M $I,$I L $I,$MG";
        $paths[] = "M $I," . ($W - $MG) . " L $I," . ($W - $I);
        $paths[] = "M " . ($W - $I) . ",$I L " . ($W - $I) . ",$MG";
        $paths[] = "M " . ($W - $I) . "," . ($W - $MG) . " L " . ($W - $I) . "," . ($W - $I);
    } else { // full
        $paths[] = "M $I,$I L " . ($W - $I) . ",$I L " . ($W - $I) . "," . ($W - $I) . " L $I," . ($W - $I) . " Z";
    }
    return $paths;
}

// Build line path elements - each parallel rule steps $lgap units inward
$line_svg = '';
for ($li = 0; $li < $line_count; $li++) {
    $Li    = $I + ($li * $lgap);
    $width = [$l1w, $l2w, $l3w][$li] ?? $l3w;
    foreach (chap_deco_paths($deco_style, $Li, $ARM, $MH) as $d) {
        $line_svg .= '<path d="' . $d . '" fill="none" stroke="' . $lc . '"'
                   . ' stroke-width="' . $width . '" vector-effect="non-scaling-stroke"'
                   . ' stroke-linecap="square"/>' . "\n";
    }
}

// Embed an ornament file as an inline SVG div with colour override.
// $constraint: 'both' | 'width' | 'height' — which dimension the SVG fills.
function chap_orn_element(string $class, string $svg_path, string $color, string $constraint = 'both'): string {
    if (!file_exists($svg_path)) return '';
    $raw = @file_get_contents($svg_path);
    if (!$raw) return '';
    $raw = preg_replace('/<\?xml[^>]*\?>\s*/i', '', $raw);
    $raw = preg_replace('/(<svg\b[^>]*)\s+width="[^"]*"/i',  '$1', $raw);
    $raw = preg_replace('/(<svg\b[^>]*)\s+height="[^"]*"/i', '$1', $raw);
    $c   = htmlspecialchars($color);
    $sty = $constraint === 'width'  ? 'width:100%;height:auto;'
         : ($constraint === 'height' ? 'height:100%;width:auto;'
         : 'width:100%;height:100%;');
    $raw = preg_replace('/(<svg\b[^>]*)>/i',
        '$1 style="' . $sty . 'display:block;" stroke="' . $c . '">', $raw, 1);
    $raw = str_ireplace('fill="#ece6d4"', 'fill="' . $c . '"', $raw);
    $raw = str_ireplace('fill="#0a0a0a"', 'fill="none"',        $raw);
    return '<div class="chap-orn ' . $class . '">' . trim($raw) . '</div>' . "\n";
}

// Ornament divs
$orn_html = '';
if ($orn_style !== 'none') {
    $orn_dir    = __DIR__ . '/reference work from Claude Design - gitignore/ornaments/';
    $orn_corner = $orn_dir . $orn_style . '-corner.svg';
    $orn_top    = $orn_dir . $orn_style . '-top.svg';
    $orn_side   = $orn_dir . $orn_style . '-side.svg';

    // Four corners
    $orn_html .= chap_orn_element('chap-orn-tl', $orn_corner, $lcolor, 'both');
    $orn_html .= chap_orn_element('chap-orn-tr', $orn_corner, $lcolor, 'both');
    $orn_html .= chap_orn_element('chap-orn-bl', $orn_corner, $lcolor, 'both');
    $orn_html .= chap_orn_element('chap-orn-br', $orn_corner, $lcolor, 'both');

    // Mid ornaments (top, bottom, left, right) for non-corners styles
    if ($deco_style !== 'corners') {
        $orn_html .= chap_orn_element('chap-orn-mid-top', $orn_top,  $lcolor, 'width');
        $orn_html .= chap_orn_element('chap-orn-mid-bot', $orn_top,  $lcolor, 'width');
        $orn_html .= chap_orn_element('chap-orn-mid-lft', $orn_side, $lcolor, 'height');
        $orn_html .= chap_orn_element('chap-orn-mid-rgt', $orn_side, $lcolor, 'height');
    }
}
?>
<div class="chap-deco-wrap">
<svg class="chap-deco-lines"
     xmlns="http://www.w3.org/2000/svg"
     viewBox="0 0 1000 1000"
     preserveAspectRatio="none"
     aria-hidden="true">
<?php echo $line_svg; ?>
</svg>
<?php echo $orn_html; ?>
</div>
<?php // ===== SNAPSMACK EOF =====
