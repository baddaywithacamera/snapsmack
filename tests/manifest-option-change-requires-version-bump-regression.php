<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A manifest option change that alters the generated CSS requires a version bump.
 * (SS-986D, PM 19:00.)
 *
 * A site installs a skin BY VERSION. manifest.json is left out of the file
 * digest (a screenshot or admin label cannot change what a visitor sees), but a
 * manifest OPTION's selector/property/default/choices generates the public CSS,
 * so changing one DOES change what an installed site renders. SS-1214 repointed
 * NEW HORIZON's header_font_family from .logo-area a to .site-title-text with no
 * version bump; the file digest could not see it, so the version gate stayed
 * green and sites installed by version never got the fix.
 *
 * snapsmack_skin_manifest_css_fingerprint() folds the rendering-affecting option
 * fields into the skin-version baseline (schema 2), so the version gate now
 * treats such a change as content. This proves the fingerprint: a
 * selector/property/default/choices change moves it (forcing a bump), while a
 * label/section/help-only edit does not (no false bump, keeping the deliberate
 * manifest-metadata exemption).
 */

$root = dirname(__DIR__);
require_once $root . '/tools/_build/generate-skin-version-baseline.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    echo ($ok ? "  ok    " : "  FAIL  ") . $what . "\n";
    $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
}

$base = ['options' => ['header_font_family' => [
    'selector' => '.logo-area a', 'property' => 'font-family',
    'label' => 'Header Font', 'section' => 'HEADER & NAV',
]]];
$fp = fn(array $m): string => snapsmack_skin_manifest_css_fingerprint($m);
$f0 = $fp($base);

cx($f0 !== '', 'the fingerprint of a manifest with options is non-empty');
cx($fp($base) === $f0, 'the same manifest fingerprints identically (stable)');

echo "\nA RENDERING-AFFECTING CHANGE MOVES THE FINGERPRINT (forces a version bump)\n";
$selector = $base; $selector['options']['header_font_family']['selector'] = '.site-title-text';
cx($fp($selector) !== $f0, 'repointing the selector (the SS-1214 bug) moves the fingerprint');
$property = $base; $property['options']['header_font_family']['property'] = 'font-size';
cx($fp($property) !== $f0, 'changing the property moves the fingerprint');
$def = $base; $def['options']['header_font_family']['default'] = 'Playfair Display';
cx($fp($def) !== $f0, 'changing the default moves the fingerprint');
$choices = $base; $choices['options']['header_font_family']['choices'] = ['serif' => 'Georgia, serif'];
cx($fp($choices) !== $f0, 'changing the choices (value map) moves the fingerprint');
$added = $base; $added['options']['new_control'] = ['selector' => '.x', 'property' => 'color'];
cx($fp($added) !== $f0, 'adding an option moves the fingerprint');
$removed = ['options' => []];
cx($fp($removed) !== $f0, 'removing an option moves the fingerprint');

echo "\nAN ADMIN-ONLY (COSMETIC) EDIT DOES NOT MOVE IT (no false bump)\n";
$label = $base; $label['options']['header_font_family']['label'] = 'MASTHEAD FONT';
cx($fp($label) === $f0, 'renaming the control label does not move the fingerprint');
$section = $base; $section['options']['header_font_family']['section'] = 'TYPOGRAPHY';
cx($fp($section) === $f0, 'moving the control to another admin section does not move it');
$help = $base; $help['options']['header_font_family']['help'] = 'Pick a font.';
cx($fp($help) === $f0, 'adding admin help text does not move it');

echo "\nNO OPTIONS -> EMPTY (a skin with no controls is exempt as before)\n";
cx($fp(['version' => '1.0.0']) === '', 'a manifest with no options fingerprints to empty');

echo "\nTHE BASELINE AND GATE CARRY THE FIELD (schema 2)\n";
$baseline = json_decode((string)file_get_contents($root . '/tests/fixtures/skin-version-content-baseline.json'), true);
cx(($baseline['schema_version'] ?? null) === 2, 'the baseline is schema 2');
$aRow = $baseline['skins']['new-horizon'] ?? [];
cx(array_key_exists('manifest_css_sha256', $aRow), 'each skin row records manifest_css_sha256');
$gate = (string)file_get_contents($root . '/tests/skin-version-content-regression.php');
cx(str_contains($gate, 'manifest_css_sha256') && str_contains($gate, 'generates the public CSS'),
   'the version gate compares the manifest-css fingerprint and explains why');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
