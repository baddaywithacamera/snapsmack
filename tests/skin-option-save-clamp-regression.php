<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * SKIN-OPTION SAVE CLAMP (989D, Codex save-path repair / SECAUDIT 061).
 *
 * The render path (snapsmack_declared_skin_options) clamps a control's value to
 * its manifest bounds on the way OUT. The save path (smack-skin.php 4a) wrote
 * $_POST['skin_opt'] straight to settings, so a stale or tampered POST could
 * store a number under a control's floor -- a header font size below its
 * minimum -- and only the render path caught it. snapsmack_clamp_skin_option()
 * is the matching guard on the way IN.
 *
 * This proves the helper:
 *   - clamps a declared range/number control to its own min/max;
 *   - clamps a font control's <key>_size companion slider to the font's range;
 *   - leaves every non-numeric / unknown / non-bounded key UNCHANGED, so no
 *     legitimate control (select, colour, text, checkbox, a custom key) is ever
 *     rejected or truncated on save;
 * and asserts smack-skin.php's save loop actually calls it.
 */

$root = dirname(__DIR__);
require_once $root . '/core/skin-presentation.php';

$fails = 0;
function check(string $label, $got, $want): void
{
    global $fails;
    $ok = ((string)$got === (string)$want);
    if (!$ok) { $fails++; fwrite(STDERR, "FAIL: $label -- got " . var_export($got, true) . ", want " . var_export($want, true) . "\n"); }
    else { echo "ok: $label\n"; }
}

// ---- a throwaway skin whose manifest exercises every branch ----
$slug = 'clamptest-tmp';
$dir  = $root . '/skins/' . $slug;
@mkdir($dir, 0777, true);
$manifest = [
    'schema_version' => 2,
    'options' => [
        // a declared numeric control with a hard floor
        'header_font_size' => ['type' => 'range', 'min' => 20, 'max' => 100, 'default' => 50],
        // a font control -> companion slider header_font_size? no: body_font_size,
        // bounded by the font's own size range
        'body_font' => ['type' => 'select', 'property' => 'font-family', 'is_font' => true,
                        'options' => ['serif' => 'Serif', 'sans' => 'Sans'], 'default' => 'serif',
                        'size' => ['min' => 0.5, 'max' => 4.0]],
        // a plain select (non-numeric)
        'wall_style' => ['type' => 'select', 'options' => ['rows' => 'Rows', 'grid' => 'Grid'], 'default' => 'rows'],
        // a colour
        'accent' => ['type' => 'color', 'default' => '#112233'],
        // a text control
        'caption' => ['type' => 'text', 'default' => 'hi'],
    ],
];
file_put_contents($dir . '/manifest.json', json_encode($manifest));

try {
    // declared numeric: below floor, above ceiling, in range
    check('range below floor clamps up',   snapsmack_clamp_skin_option($slug, 'header_font_size', '5'),   '20');
    check('range above ceiling clamps down',snapsmack_clamp_skin_option($slug, 'header_font_size', '500'), '100');
    check('range in band is untouched',    snapsmack_clamp_skin_option($slug, 'header_font_size', '50'),  '50');
    check('range at exact floor holds',    snapsmack_clamp_skin_option($slug, 'header_font_size', '20'),  '20');

    // companion size slider: clamped to the font's size range
    check('companion below min clamps up',  snapsmack_clamp_skin_option($slug, 'body_font_size', '0.1'), '0.5');
    check('companion above max clamps down', snapsmack_clamp_skin_option($slug, 'body_font_size', '9'),   '4');
    check('companion in band, fractional',  snapsmack_clamp_skin_option($slug, 'body_font_size', '2.25'), '2.25');

    // non-numeric / unknown / non-bounded keys pass through UNCHANGED
    check('select value passes through',    snapsmack_clamp_skin_option($slug, 'wall_style', 'grid'),   'grid');
    check('select junk is NOT rejected on save', snapsmack_clamp_skin_option($slug, 'wall_style', 'bogus'), 'bogus');
    check('colour passes through',          snapsmack_clamp_skin_option($slug, 'accent', '#abcdef'),    '#abcdef');
    check('text passes through (not truncated)', snapsmack_clamp_skin_option($slug, 'caption', str_repeat('x', 600)), str_repeat('x', 600));
    check('unknown key passes through',     snapsmack_clamp_skin_option($slug, 'not_a_control', '7'),   '7');
    check('non-numeric for numeric control passes through', snapsmack_clamp_skin_option($slug, 'header_font_size', 'abc'), 'abc');

    // a skin that does not exist: no manifest -> value unchanged
    check('missing skin leaves value',      snapsmack_clamp_skin_option('no-such-skin', 'header_font_size', '5'), '5');
    // a bad key never reaches the filesystem
    check('illegal key is rejected safely', snapsmack_clamp_skin_option($slug, 'bad key!', '5'), '5');
} finally {
    @unlink($dir . '/manifest.json');
    @rmdir($dir);
}

// ---- structural: the save loop actually calls the guard ----
$save = file_get_contents($root . '/smack-skin.php');
check('smack-skin.php requires skin-presentation.php',
      (int)(strpos($save, "core/skin-presentation.php") !== false), '1');
check('smack-skin.php save loop calls the clamp',
      (int)(preg_match('/skin_opt.*?snapsmack_clamp_skin_option\s*\(/s', $save) === 1), '1');

if ($fails) { fwrite(STDERR, "\n$fails assertion(s) FAILED\n"); exit(1); }
echo "\nskin-option save clamp: all checks passed\n";

// ===== SNAPSMACK EOF =====
