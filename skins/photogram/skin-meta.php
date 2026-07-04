<?php
/**
 * SNAPSMACK - Photogram Skin Meta
 * Alpha v0.7.9
 *
 * Delegates to core meta.php for <head> content, viewport, and font loading.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */



include(dirname(__DIR__, 2) . '/core/meta.php');

// ── Tile aspect: mirror the site's measured print format ────────────────────
// PHOTOGRAM is the mobile companion to INSTANT CAMERA, so it shows the same
// uncropped print ratio. The admin "measure my prints" tool stores the format
// under the shared instant-camera__ keys (ic_format / ic_custom_ratio). We read
// them here and emit --pg-tile-aspect AFTER core/meta.php has emitted the skin
// stylesheet, so this overrides the square default in style.css. Nothing set →
// emit nothing and the skin's own default holds.
$pg_meta_settings = $settings ?? [];
$pg_meta_fmt = (string)($pg_meta_settings['instant-camera__ic_format'] ?? '');
$pg_meta_ratios = [
    'polaroid' => '79 / 97', 'sx70' => '1 / 1', 'go' => '47 / 60',
    'instax_mini' => '62 / 46', 'instax_wide' => '99 / 62', 'instax_square' => '1 / 1',
];
$pg_meta_aspect = '';
if ($pg_meta_fmt === 'custom') {
    $pg_meta_raw = trim((string)($pg_meta_settings['instant-camera__ic_custom_ratio'] ?? ''));
    if (preg_match('/^\s*(\d{1,4})\s*[:\/xX]\s*(\d{1,4})\s*$/', $pg_meta_raw, $pg_meta_m)
        && (int)$pg_meta_m[1] > 0 && (int)$pg_meta_m[2] > 0) {
        $pg_meta_aspect = (int)$pg_meta_m[1] . ' / ' . (int)$pg_meta_m[2];
    }
} elseif ($pg_meta_fmt !== '') {
    $pg_meta_aspect = $pg_meta_ratios[$pg_meta_fmt] ?? '';
}
if ($pg_meta_aspect !== '') {
    echo '<style id="pg-print-aspect">:root{--pg-tile-aspect:' . $pg_meta_aspect . ';}</style>';
}
?>
<?php
// ===== SNAPSMACK EOF =====
