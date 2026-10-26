<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Every font size slider Skin Admin draws must reach the page.
 *
 * Skin Admin draws a size slider beneath each font control, named <key>_size,
 * and saves it like any other control. snapsmack_declared_skin_style() has
 * always looked for that key -- but only inside the options array, and that
 * array only ever carried keys a manifest declares. Forty-five sliders across
 * thirteen skins use a key no manifest declares, so the number was written on
 * every save and read by nothing: the font changed and its size never did.
 *
 * Measured on photowalk.ing (50 SHADES, 0.7.952D): the body font size slider
 * read 1.35rem in Skin Admin and the page carried no font-size rule for
 * `.static-content, .description` at all.
 */

require_once dirname(__DIR__) . '/core/skin-settings.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';

$failures = 0;
function fss_check(bool $ok, string $what): void
{
    global $failures;
    if ($ok) return;
    $failures++;
    fwrite(STDERR, "FAIL: {$what}\n");
}

$checked = 0;
$sliders = 0;
foreach (glob(dirname(__DIR__) . '/skins/*/manifest.json') ?: [] as $path) {
    $slug = basename(dirname($path));
    $manifest = json_decode((string)file_get_contents($path), true);
    if (!is_array($manifest) || ($manifest['schema_version'] ?? 0) !== 2) continue;
    $options = is_array($manifest['options'] ?? null) ? $manifest['options'] : [];

    foreach ($options as $key => $meta) {
        if (!is_array($meta)) continue;
        $isFont = (($meta['property'] ?? '') === 'font-family') || !empty($meta['is_font']);
        if (!$isFont || !empty($meta['no_size_slider'])) continue;
        $selector = trim((string)($meta['selector'] ?? ''));
        if ($selector === '') continue;
        $sliders++;

        $sizeKey = (string)($meta['sz_key_override'] ?? ($key . '_size'));
        $range = is_array($meta['size'] ?? null) ? $meta['size'] : [];
        // A value inside the slider's own range and different from any default,
        // so a rule carrying it cannot have come from somewhere else.
        $min = is_numeric($range['min'] ?? null) ? (float)$range['min'] : 0.7;
        $max = is_numeric($range['max'] ?? null) ? (float)$range['max'] : 2.0;
        $value = round($min + (($max - $min) * 0.37), 2);

        $settings = [$slug . '__' . $sizeKey => (string)$value];
        snapsmack_apply_skin_settings($settings, $slug);
        $resolved = snapsmack_declared_skin_options($settings, $slug);
        fss_check(array_key_exists($sizeKey, $resolved),
            "{$slug}: the {$sizeKey} slider saves but never reaches the page");

        // A skin whose font is a CSS variable reads the number through its
        // sibling variable; everyone else gets a font-size rule in rem.
        $property = trim((string)($meta['property'] ?? ''));
        $expected = (str_starts_with($property, '--') && str_ends_with($property, '-font'))
            ? substr($property, 0, -5) . '-size:' . $value . ';'
            : 'font-size:' . $value . 'rem';
        $style = snapsmack_declared_skin_style($slug, $resolved);
        fss_check(str_contains($style, $expected),
            "{$slug}: {$sizeKey} set to {$value} produced no '{$expected}'");
        $checked++;
    }
}

fss_check($sliders >= 45, "only {$sliders} font size sliders found; this skin family has at least 45");
fss_check($checked >= 45, "only {$checked} sliders were exercised");

if ($failures > 0) {
    fwrite(STDERR, "\nFont size slider regression FAILED with {$failures} problem(s).\n");
    exit(1);
}
printf("Font size sliders reach the page: %d sliders across the schema-2 skins.\n", $checked);
exit(0);
// ===== SNAPSMACK EOF =====
