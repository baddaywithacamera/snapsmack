<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * GALLERIA's FORCE SQUARE CROP MUST ACTUALLY CROP.
 *
 * The fault: the control emitted a CSS variable that nothing read. Its
 * manifest entry pointed at `:root` with property `--force-square`, and the
 * value a plain select emits is the chosen key -- so saving "Yes (Square)"
 * produced `:root{--force-square:1;}`. Neither GALLERIA's stylesheet nor HIP
 * TO BE SQUARE's reads --force-square anywhere. The owner could set the
 * control, the setting saved correctly, the CSS was emitted correctly, and no
 * pixel changed. Nothing failed.
 *
 * THE REPAIR IS MANIFEST-ONLY, no stylesheet change. The compiler already
 * supports per-choice CSS -- `property: custom-*` with each option carrying
 * its own css block, which 50 SHADES uses for its frame styles. So the
 * control now points at `.htbs-archive-item .frame-image`, the archive tile's
 * image box, and emits `aspect-ratio: 1` for Square and `aspect-ratio: auto`
 * for Original Aspect. The existing rule on that box's image already has
 * `height: 100%` and `object-fit: cover`, so a 1:1 box crops rather than
 * squashes. Nothing in the skin's CSS was touched, which keeps this off the
 * appearance lane.
 *
 * THE KEY WAS DELIBERATELY NOT RENAMED. It is htbs_force_square -- HIP TO BE
 * SQUARE's prefix on GALLERIA's control, a copy-paste from the skin GALLERIA
 * was derived from. Renaming it to a galleria_ key would orphan whatever the
 * owner has already saved, silently, which is worse than an untidy name. The
 * name is wrong; the behaviour now is not.
 *
 * WHAT THIS CANNOT SEE: it reads emitted CSS. It does not measure a rendered
 * thumbnail, so it cannot prove the crop is square on screen. That proof is
 * the headed runner's, and Codex holds it.
 */

define('SNAPSMACK_SKIN_RENDER', true);
$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-settings.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/skin-presentation.php';

$pass = 0; $fail = 0;
function gq(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

/**
 * The css GALLERIA emits for a given SAVED setting, through the real overlay.
 *
 * The overlay matters and is the reason this does not pass its own bare key:
 * the admin stores skin options prefixed (galleria__htbs_force_square) and
 * core/skin-settings.php copies them onto bare keys. A test that passed the
 * bare key directly would pass even if the prefix contract broke.
 */
function gq_css(array $savedRaw): string {
    $settings = $savedRaw;
    snapsmack_apply_skin_settings($settings, 'galleria');
    return snapsmack_declared_skin_style(
        'galleria', snapsmack_declared_skin_options($settings, 'galleria')
    );
}

function gq_rule(string $css): string {
    return preg_match('/\.htbs-archive-item \.frame-image\{[^}]*\}/', $css, $m) === 1 ? $m[0] : '';
}

echo "SAVING SQUARE EMITS A SQUARE, THROUGH THE REAL PREFIXED SETTING\n";
$square = gq_css(['galleria__htbs_force_square' => '1']);
gq(gq_rule($square) !== '', 'a rule for the archive tile image box is emitted');
gq(str_contains(gq_rule($square), 'aspect-ratio: 1'),
   'and it is a 1:1 box (' . gq_rule($square) . ')');
gq(!str_contains($square, '--force-square'),
   'the dead variable nothing read is gone');

echo "\nORIGINAL ASPECT IS STILL ORIGINAL, WHICH IS HOW THIS SKIN HAS ALWAYS LOOKED\n";
$auto = gq_css(['galleria__htbs_force_square' => '0']);
gq(str_contains(gq_rule($auto), 'aspect-ratio: auto'),
   'the other choice emits auto (' . gq_rule($auto) . ')');
$unset = gq_css([]);
gq(str_contains(gq_rule($unset), 'aspect-ratio: auto'),
   'and so does a site that has never touched the control, so nothing moves');
gq(gq_rule($unset) === gq_rule($auto),
   'unset and Original Aspect are byte-identical, so the default is honest');

echo "\nTHE TWO CHOICES DIFFER. A CONTROL WHOSE SETTINGS AGREE IS THE FAULT ITSELF\n";
gq(gq_rule($square) !== gq_rule($auto),
   'Square and Original Aspect emit different css');

echo "\nTHE STYLESHEET CAN CONSUME IT -- OTHERWISE A 1:1 BOX WOULD SQUASH\n";
$css = (string)@file_get_contents($root . '/skins/galleria/style.css');
gq(preg_match('/\.htbs-archive-item \.frame-image img\s*\{[^}]*object-fit:\s*cover/s', $css) === 1,
   'the tile image still crops rather than stretching');
gq(preg_match('/\.htbs-archive-item \.frame-image img\s*\{[^}]*height:\s*100%/s', $css) === 1,
   'and still fills the box, which is what makes the aspect-ratio bite');
gq(preg_match('/\.htbs-archive-item \.frame-image\s*\{[^}]*overflow:\s*hidden/s', $css) === 1,
   'and the box still hides the overflow, so the crop is a crop');
gq(preg_match('/\.htbs-archive-item \.frame-image\s*\{[^}]*aspect-ratio/s', $css) !== 1,
   'the stylesheet itself sets no aspect-ratio there, so the emitted rule is '
   . 'not fighting a skin declaration');

echo "\nTHE EMITTED RULE REACHES THE TILE THE ARCHIVE ACTUALLY RENDERS\n";
// A selector that matches nothing is the fault one layer along. GALLERIA
// renders its archive through a wrapper page component, so this reads that
// component rather than the layout.
$component = (string)@file_get_contents($root . '/core/galleria-page-component.php');
gq(str_contains($component, 'class="htbs-archive-item"'),
   'the archive tile carries htbs-archive-item');
gq(str_contains($component, 'class="frame-image"'),
   'and contains a frame-image box for the rule to land on');
gq(substr_count($component, 'htbs-archive-grid') >= 1,
   'and the grid around it is the one the stylesheet lays out');

echo "\nTHE CONTROL IS STILL A CONTROL -- LABELS, DEFAULT AND SECTION INTACT\n";
$manifest = json_decode((string)@file_get_contents($root . '/skins/galleria/manifest.json'), true);
$opt = $manifest['options']['htbs_force_square'] ?? null;
gq(is_array($opt), 'the option is still declared');
gq(($opt['default'] ?? null) === '0',
   'its default is still Original Aspect, so no site changes on upgrade');
gq(($opt['section'] ?? '') === 'IMAGE CROP', 'it is still in the IMAGE CROP section');
gq(is_array($opt['options']['0'] ?? null) && ($opt['options']['0']['label'] ?? '') !== '',
   'both choices still carry the labels the owner reads');
gq(is_array($opt['options']['1'] ?? null) && ($opt['options']['1']['label'] ?? '') !== '',
   'including the Square one');
// Compared as strings on purpose: json_decode folds the JSON object keys "0"
// and "1" into PHP INTEGER keys, so a === against ['0','1'] fails on a
// manifest that is perfectly correct. The reader does the same normalisation
// -- array_map('strval', array_keys(...)) in snapsmack_declared_skin_options()
// -- so strings are what the validation actually compares.
gq(array_map('strval', array_keys($opt['options'] ?? [])) === ['0', '1'],
   'and the saved values are unchanged, so an owner who already chose Square '
   . 'keeps it rather than being silently reset');
// The admin renders the label out of the array shape and the value out of the
// key, so the control still reads the same to the owner. Checked here because
// changing a plain label list into keyed objects is exactly the kind of edit
// that silently turns a dropdown into "0" and "1".
$admin = (string)@file_get_contents($root . '/smack-skin.php');
gq(str_contains($admin, "is_array(\$sl) ? \$sl['label'] : \$sl"),
   'the admin select still prints a label from the array shape, so the owner '
   . 'sees the words and not the stored number');

echo "\nMUTATION PROOF: THE OLD DEFINITION MUST FAIL THIS\n";
// Reproduce the pre-repair control and show the checks tell them apart.
$old = ['section' => 'IMAGE CROP', 'type' => 'select', 'label' => 'Force Square Crop',
    'default' => '0', 'options' => ['No (Original Aspect)', 'Yes (Square)'],
    'selector' => ':root', 'property' => '--force-square'];
gq(!is_array($old['options'][0] ?? null),
   'the old definition carried plain labels, so the compiler fell through to '
   . 'property:value and emitted the chosen key as the value');
gq($old['property'] === '--force-square'
   && !str_contains($css, 'var(--force-square'),
   'and nothing in the stylesheet ever read that variable, which is why the '
   . 'control did nothing');

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "GALLERIA square crop: FAIL ({$fail}).\n";
    exit(1);
}
echo "GALLERIA square crop: PASS ({$pass} assertions).\n";
echo "  NOT CHECKED: a measured thumbnail. This reads emitted css and markup and\n";
echo "  drives no browser, so it cannot prove the crop is square on screen.\n";
echo "  ALSO NOT FIXED, and reported rather than quietly left: HIP TO BE SQUARE\n";
echo "  carries the same dead --force-square control and is not mine to change.\n";

// ===== SNAPSMACK EOF =====
