<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * THE OWNER'S GUTTER SETTING ACTUALLY CHANGES THE GAPS.
 *
 * smack-appearance-archive.php has had a GUTTER slider since it was written
 * and nothing read it. The only thing in the product that ever mapped a
 * setting to --grid-gap is core/meta.php:35, which reads a different key
 * (browse_gap) on the LEGACY render path -- index.php sends every strict skin
 * to snapsmack_public_runtime() and exits before meta.php is reached, and all
 * 30 renderable skins are strict. So the slider moved and nothing happened,
 * on every site.
 *
 * Sean set GUTTER to 4px and reported gaps of roughly 50px. That 50px is the
 * fallback in assets/css/page-archive.css -- `gap: var(--grid-gap, 50px)` at
 * lines 27 and 53. He was measuring the CSS default because no value was
 * being emitted at all.
 *
 * THE RULING THIS IMPLEMENTS, from the coordinator, four parts:
 *   1. Wire it: the owner's value wins.
 *   2. Emit ONLY when the key is present in settings, so no untouched site
 *      changes spacing.
 *   3. The emission must land after the skin's presentation styles.
 *   4. Test both orders plus the not-saved case.
 * All four are checked below, and the order check is the one that decides
 * whether "owner wins" is true rather than asserted.
 *
 * WHAT THIS CANNOT SEE. It reads emitted CSS. It does not open a browser and
 * it does not measure a rendered gap, so it cannot prove a tile moved. The
 * headed runner does that and it is named in the NOT CHECKED line at the end.
 */

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-presentation.php';

$pass = 0; $fail = 0;
function og(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

echo "AN UNTOUCHED SITE IS LEFT ALONE -- THE GUARD IN THE RULING\n";
// null means "not set", which is why this returns ?int rather than an int
// with a default. A default would emit on every site and move spacing
// everywhere, which is the opposite of what the ruling asked for.
og(snapsmack_owner_gutter_px([]) === null,
   'no key in settings yields null, so nothing is emitted');
og(snapsmack_owner_gutter_px(['archive_cols' => '4']) === null,
   'a settings array holding other archive keys still yields null');
og(snapsmack_owner_gutter_px(['archive_gutter' => '']) === null,
   'a present-but-empty value yields null, rather than collapsing every gap to 0');
og(snapsmack_owner_gutter_px(['archive_gutter' => 'wide']) === null,
   'a non-numeric value yields null instead of 0');
og(snapsmack_owner_gutter_px(['archive_gutter' => null]) === null,
   'a null value yields null');
og(snapsmack_owner_gutter_px(['archive_gutter' => true]) === null,
   'a boolean yields null rather than 1px');

echo "\nA SAVED VALUE IS CARRIED THROUGH, AND IT IS THE VALUE THE OWNER SAVED\n";
og(snapsmack_owner_gutter_px(['archive_gutter' => '4']) === 4, '"4" becomes 4');
og(snapsmack_owner_gutter_px(['archive_gutter' => 4]) === 4, 'and so does the integer 4');
og(snapsmack_owner_gutter_px(['archive_gutter' => 0]) === 0,
   'zero is a legitimate saved value and is not confused with unsaved');
og(snapsmack_owner_gutter_px(['archive_gutter' => '40']) === 40,
   'the slider maximum of 40 survives');
og(snapsmack_owner_gutter_px(['archive_gutter' => '9999']) === 40,
   'a value edited past the slider maximum is clamped to 40, not passed raw');
og(snapsmack_owner_gutter_px(['archive_gutter' => '-20']) === 0,
   'a negative value is clamped to 0');
// Stricter than a cast: "4; }html{display:none" is not numeric, so it is
// REJECTED rather than truncated to 4. Both would be safe -- the value is cast
// to an int before it reaches any css -- but refusing it means a settings row
// somebody has tampered with produces the skin's own spacing rather than a
// number nobody chose.
og(snapsmack_owner_gutter_px(['archive_gutter' => '4; }html{display:none']) === null,
   'a value carrying css is refused outright, not truncated to its leading digits');
og(snapsmack_owner_gutter_px(['archive_gutter' => '4px']) === null,
   'and so is "4px" -- the setting stores a number, and the unit is added here');

echo "\nTHE EMISSION LANDS LAST, WHICH IS WHAT MAKES THE OWNER WIN\n";
// This is the ruling's third part and the only one that can be got wrong
// silently. Built with a REAL skin whose option emits --grid-gap already, so
// both declarations are present and the order between them is observable.
$withGutter = snapsmack_skin_presentation(
    ['aurora__au_gap' => '20', 'archive_gutter' => '4'], 'aurora');
$css = (string)($withGutter['style'] ?? '');
$ownerAt = strpos($css, 'snapsmack-owner-gutter');
og($ownerAt !== false, 'the owner block is in AURORA\'s presentation css');
$optionAt = strrpos($css, 'snapsmack-grid-common-presentation');
og($optionAt !== false, 'and so is the skin option block that also sets --grid-gap');
og($ownerAt !== false && $optionAt !== false && $ownerAt > $optionAt,
   'the owner block comes AFTER the skin option block, so at equal specificity '
   . 'the owner\'s value wins');
$declaredAt = strrpos($css, 'snapsmack-declared-skin-style');
og($declaredAt === false || ($ownerAt !== false && $ownerAt > $declaredAt),
   'it also comes after the declared-skin-style block, which emits --grid-gap too');
// The last --grid-gap declaration in the document is the one that applies.
preg_match_all('/--grid-gap:\s*([0-9]+)px/', $css, $all);
og(($all[1] ?? []) !== [] && end($all[1]) === '4',
   'the LAST --grid-gap in the emitted css is the owner\'s 4, not the skin\'s 20 '
   . '(found: ' . implode(', ', $all[1] ?? []) . ')');

echo "\nTHE OTHER ORDER: SKIN KEEPS ITS OWN LOOK UNTIL THE OWNER TOUCHES THE CONTROL\n";
$skinOnly = snapsmack_skin_presentation(['aurora__au_gap' => '20'], 'aurora');
$cssSkin = (string)($skinOnly['style'] ?? '');
og(!str_contains($cssSkin, 'snapsmack-owner-gutter'),
   'with no saved gutter the owner block is absent entirely');
preg_match_all('/--grid-gap:\s*([0-9]+)px/', $cssSkin, $skinAll);
og(($skinAll[1] ?? []) !== [] && end($skinAll[1]) === '20',
   'so AURORA\'s own 20px is still the last word (found: '
   . implode(', ', $skinAll[1] ?? []) . ')');

echo "\nMUTATION PROOF: THE ORDER CHECK MUST BE ABLE TO FAIL\n";
// If the owner block were appended BEFORE the option block, the skin would
// win and the control would be dead again -- the exact state this repairs.
// Reproduce that order here and show the last-declaration test catches it.
$inverted = str_replace(
    '<style id="snapsmack-owner-gutter">:root{--grid-gap:4px;}</style>', '', $css
);
$inverted = '<style id="snapsmack-owner-gutter">:root{--grid-gap:4px;}</style>' . $inverted;
preg_match_all('/--grid-gap:\s*([0-9]+)px/', $inverted, $invAll);
og(($invAll[1] ?? []) !== [] && end($invAll[1]) === '20',
   'with the blocks the other way round the skin wins, so the order check above '
   . 'is measuring order and not merely presence');

echo "\nIT IS CALLED FROM THE PRESENTATION BUILDER, NOT JUST DEFINED\n";
// A helper nothing calls is the fault being repaired. This is the one source
// check in the file and it is bounded on the function, not on a byte count.
$src = (string)@file_get_contents($root . '/core/skin-presentation.php');
$builder = (static function (string $s): string {
    $at = strpos($s, 'function snapsmack_skin_presentation(');
    if ($at === false) return '';
    $end = strpos($s, 'function snapsmack_owner_gutter_px(', $at);
    return $end === false ? '' : substr($s, $at, $end - $at);
})($src);
og($builder !== '', 'the presentation builder was found');
og(str_contains($builder, 'snapsmack_owner_gutter_px($settings)'),
   'and it calls the emitter with the site settings');

echo "\nWHO ACTUALLY READS --grid-gap -- MEASURED, NOT ASSUMED\n";
// The control cannot change a skin that never reads the variable. This counts
// the readers so the number in the commit message is a measurement and so a
// future skin losing its reader shows up here.
$readers = [];
foreach (glob($root . '/skins/*/style.css') ?: [] as $path) {
    $slug = basename(dirname($path));
    if (str_contains((string)file_get_contents($path), 'var(--grid-gap')) $readers[] = $slug;
}
sort($readers);
og(count($readers) >= 12,
   count($readers) . ' skins read var(--grid-gap) in their own stylesheet: '
   . implode(', ', $readers));
$archiveCss = (string)@file_get_contents($root . '/assets/css/page-archive.css');
og(str_contains($archiveCss, 'var(--grid-gap'),
   'page-archive.css reads it too, which is the file Sean measured 50px from');
og(substr_count($archiveCss, 'var(--grid-gap, 50px)') >= 2,
   'and its fallback is still the 50px he reported, so that reading is explained');

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Owner gutter: FAIL ({$fail}).\n";
    exit(1);
}
echo "Owner gutter: PASS ({$pass} assertions).\n";
echo "  NOT CHECKED: a measured gap on a rendered page. This reads emitted CSS;\n";
echo "  it drives no browser. The headed matrix in\n";
echo "  tools/_build/measure-geometry-matrix.cjs is what would prove a tile moved,\n";
echo "  and the four skins that hardcode their gaps (stanley, telegram, tilez,\n";
echo "  writing-with-impact) read no variable at all, so this control cannot\n";
echo "  reach them and that is a skin-side question, not a core one.\n";

// ===== SNAPSMACK EOF =====
