<?php
/**
 * SNAPSMACK - Chaplin skin: Art Deco frame — ornament layer
 *
 * Renders ornament divs only. Included BEFORE <img> in layout.php so that
 * DOM order places ornaments behind the image — no z-index needed.
 * Line connector divs are in frame-lines.php, included AFTER <img>.
 *
 * Geometry (per chaplin-frame-geometry-fix.md):
 *   - Lines connect BETWEEN corner ornament outer edges; they do NOT run under corners.
 *   - Corner ornaments define the frame rectangle; their outer edges ARE the corners.
 *   - Centre ornaments straddle the line midpoints.
 *
 * SVG source files: skins/chaplin/assets/svg/{style}-{shape}.svg
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$orn_style    = $settings['chap_ornament_style']    ?? 'A';
$show_corners = ($settings['chap_corner_ornaments'] ?? '1') === '1';
$show_mid_tb  = ($settings['chap_mid_top_bot']      ?? '0') === '1';
$show_mid_lr  = ($settings['chap_mid_left_right']   ?? '0') === '1';
$lcolor       = '#f0f0f0';

$orn_dir      = __DIR__ . '/assets/svg/';
$orn_corner   = $orn_dir . $orn_style . '-corner.svg';
$orn_top      = $orn_dir . $orn_style . '-top.svg';
$orn_side     = $orn_dir . $orn_style . '-side.svg';

/**
 * Inline an SVG ornament file, normalising ALL colours to $color.
 * $classes — full CSS class string for the wrapper div.
 *
 * Replaces every fill/stroke in the SVG — presentation attributes AND inline CSS —
 * with the target colour, preserving only fill="none" / stroke="none" (transparency).
 * This catches any warm/gold/vintage colours the original SVG art may contain.
 */
if (!function_exists('chap_orn_element')) {
    function chap_orn_element(string $classes, string $svg_path, string $color, string $constraint = 'both'): string {
        if (!file_exists($svg_path)) return '';
        $raw = @file_get_contents($svg_path);
        if (!$raw) return '';

        $c   = htmlspecialchars($color);
        $sty = $constraint === 'width'  ? 'width:100%;height:auto;display:block;'
             : ($constraint === 'height' ? 'height:100%;width:auto;display:block;'
             : 'width:100%;height:100%;display:block;');

        // Strip XML declaration and width/height from SVG root.
        $raw = preg_replace('/<\?xml[^>]*\?>\s*/i', '', $raw);
        $raw = preg_replace('/(<svg\b[^>]*)\s+width="[^"]*"/i',  '$1', $raw);
        $raw = preg_replace('/(<svg\b[^>]*)\s+height="[^"]*"/i', '$1', $raw);

        // Normalise ALL fill presentation attributes — preserve "none", replace everything else.
        $raw = preg_replace_callback('/\bfill="([^"]*)"/i', function ($m) use ($c) {
            return (strtolower(trim($m[1])) === 'none') ? 'fill="none"' : 'fill="' . $c . '"';
        }, $raw);

        // Normalise ALL stroke presentation attributes — preserve "none", replace everything else.
        $raw = preg_replace_callback('/\bstroke="([^"]*)"/i', function ($m) use ($c) {
            return (strtolower(trim($m[1])) === 'none') ? 'stroke="none"' : 'stroke="' . $c . '"';
        }, $raw);

        // Inject display sizing + colour defaults on SVG root via CSS style attribute.
        // CSS beats presentation attributes for inherited fill/stroke on child elements.
        $raw = preg_replace('/(<svg\b[^>]*)>/i',
            '$1 style="' . $sty . 'fill:' . $c . ';stroke:' . $c . ';">', $raw, 1);

        return '<div class="' . $classes . '">' . trim($raw) . '</div>' . "\n";
    }
}

// ── Ornament divs (conditional) ───────────────────────────────────────────────
$orn_html = '';

if ($orn_style !== 'none') {

    if ($show_corners) {
        $orn_html .= chap_orn_element('chap-orn chap-orn-masked chap-orn-tl', $orn_corner, $lcolor, 'both');
        $orn_html .= chap_orn_element('chap-orn chap-orn-masked chap-orn-tr', $orn_corner, $lcolor, 'both');
        $orn_html .= chap_orn_element('chap-orn chap-orn-masked chap-orn-bl', $orn_corner, $lcolor, 'both');
        $orn_html .= chap_orn_element('chap-orn chap-orn-masked chap-orn-br', $orn_corner, $lcolor, 'both');
    }

    if ($show_mid_tb) {
        $orn_html .= chap_orn_element('chap-orn chap-orn-masked chap-orn-mid-top', $orn_top,  $lcolor, 'width');
        $orn_html .= chap_orn_element('chap-orn chap-orn-masked chap-orn-mid-bot', $orn_top,  $lcolor, 'width');
    }

    if ($show_mid_lr) {
        $orn_html .= chap_orn_element('chap-orn chap-orn-masked chap-orn-mid-lft', $orn_side, $lcolor, 'height');
        $orn_html .= chap_orn_element('chap-orn chap-orn-masked chap-orn-mid-rgt', $orn_side, $lcolor, 'height');
    }

}

if (!$orn_html) return; // nothing to render
?>
<div class="chap-frame-deco" aria-hidden="true">
<?php echo $orn_html; ?>
</div>
<?php // ===== SNAPSMACK EOF =====
