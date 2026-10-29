<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * CHAPLIN's frame is separated rules and eight ornaments, not a rectangle.
 *
 * Sean's own reference capture, projects/snapsmack-ca/img/chaplin-landing.png,
 * is the target: large crosshatch corner ornaments, four rules that STOP at
 * those corners with a clear gap, and a diamond centred on the top rule and
 * on the bottom rule. Codex measured the live page at 1920 on 28 October and
 * got the opposite: "continuous rectangle, tiny corner ornaments, no centre
 * ornaments, blank bottom photo nav".
 *
 * HOW IT WAS LOST. b61fa6aa converted CHAPLIN to a strict skin and deleted
 * skins/chaplin/frame-deco.php, frame-lines.php and skin-header.php. The rule
 * for a strict skin is that the logic moves to core and the skin prints the
 * result. The files went; the logic did not move. Three things vanished with
 * them and none was noticed because style.css still carried every rule:
 *
 *   1. skin-header.php emitted --chap-orn-corner-size, --chap-orn-top-w/h,
 *      --chap-orn-side-w/h, --chap-line-h-inset and --chap-line-v-inset.
 *      Nothing emitted them afterwards. style.css insets the four rules with
 *      var(--chap-line-h-inset, 0px), so they fell back to ZERO and ran
 *      corner to corner, straight under the corner ornaments. That is the
 *      continuous rectangle, exactly.
 *   2. frame-deco.php gave every ornament the class chap-orn-masked. The
 *      rebuilt markup omitted it. .chap-orn-masked paints a canvas-coloured
 *      plate with a box-shadow spread, and that plate IS the visible gap
 *      around each ornament.
 *   3. frame-deco.php emitted eight ornaments; the rebuild emitted four. The
 *      centre ornaments were never written, although mid_tb and mid_lr have
 *      been prepared in snapsmack_chaplin_presentation() the whole time. The
 *      owner's two switches were wired to no markup at all.
 *
 * This calls the real presentation function rather than reading the file, so
 * a value that stops being emitted fails here instead of being argued about.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function cf_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

require_once $root . '/core/skin-presentation.php';

/** The emitted <style> block, as text. It is a SnapTrustedHtml object. */
function cf_style(array $result): string {
    return isset($result['style']) ? (string)$result['style'] : '';
}

$base = [
    'chap_ornament_style' => 'A',
    'chap_corner_ornaments' => '1',
    'chap_mid_top_bot' => '1',
    'chap_mid_left_right' => '1',
    'chap_line_count' => 1,
];

$on    = snapsmack_chaplin_presentation($base);
$style = cf_style($on);

echo "The variables style.css has been asking for are emitted\n";
foreach (['--chap-orn-corner-size','--chap-orn-top-w','--chap-orn-top-h','--chap-orn-side-w','--chap-orn-side-h','--chap-line-h-inset','--chap-line-v-inset'] as $var) {
    cf_test(str_contains($style, $var), "{$var} is emitted");
}

echo "\nThe rules stop at the corners instead of running under them\n";
preg_match('/--chap-line-h-inset\s*:\s*([^;}]+)/', $style, $mh);
preg_match('/--chap-orn-corner-size\s*:\s*([^;}]+)/', $style, $mc);
cf_test(isset($mh[1]) && trim($mh[1]) === '56px', 'style A insets the rules by 56px, its corner ornament size');
cf_test(isset($mc[1]) && trim($mc[1]) === '56px', 'style A sizes the corner ornament at its designed 56px');

$off      = snapsmack_chaplin_presentation(['chap_ornament_style' => 'A', 'chap_corner_ornaments' => '0']);
$styleOff = cf_style($off);
preg_match('/--chap-line-h-inset\s*:\s*([^;}]+)/', $styleOff, $mo);
cf_test(isset($mo[1]) && trim($mo[1]) === '0px', 'with corner ornaments OFF the inset is 0px, so the rules close the rectangle');

echo "\nEach ornament style keeps its own designed dimensions\n";
$expected = ['A' => '56px', 'B' => '48px', 'C' => '64px', 'D' => '48px'];
foreach ($expected as $styleKey => $size) {
    $r  = snapsmack_chaplin_presentation(['chap_ornament_style' => $styleKey, 'chap_corner_ornaments' => '1']);
    $sv = cf_style($r);
    preg_match('/--chap-orn-corner-size\s*:\s*([^;}]+)/', $sv, $m);
    cf_test(isset($m[1]) && trim($m[1]) === $size, "style {$styleKey} corner is {$size}, not rounded to a common value");
}

echo "\nThe owner's switches reach markup\n";
cf_test(!empty($on['chaplin']['mid_tb']), 'mid_tb is prepared when the owner turns it on');
cf_test(!empty($on['chaplin']['mid_lr']), 'mid_lr is prepared when the owner turns it on');

$component = (string) file_get_contents($root . '/core/chaplin-page-component.php');
cf_test(str_contains($component, 'chap-orn-mid-top') && str_contains($component, 'chap-orn-mid-bot'),
    'the component emits the top and bottom centre ornaments mid_tb controls');
cf_test(str_contains($component, 'chap-orn-mid-lft') && str_contains($component, 'chap-orn-mid-rgt'),
    'the component emits the left and right centre ornaments mid_lr controls');
cf_test(str_contains($component, "\$chapOrn['mid_tb']") && str_contains($component, "\$chapOrn['mid_lr']"),
    'and gates them on the prepared values rather than always drawing them');
cf_test(str_contains($component, 'chap-orn chap-orn-masked'),
    'every ornament carries chap-orn-masked, the plate that makes the gap around it');
cf_test(substr_count($component, 'class="chap-orn chap-orn-masked') >= 1
        && !preg_match('/class="chap-orn chap-orn-(tl|tr|bl|br)"/', $component),
    'no ornament is emitted without the masking class, as the rebuilt markup did');

echo "\nCorner ornaments default ON, as the deleted partial and the manifest both have it\n";
$bare = snapsmack_chaplin_presentation(['chap_ornament_style' => 'A']);
cf_test(!empty($bare['chaplin']['corners']),
    'a site whose options omit chap_corner_ornaments still gets its corners');
$manifest = json_decode((string) file_get_contents($root . '/skins/chaplin/manifest.json'), true);
cf_test(is_array($manifest), 'the manifest parses, so the default below means something');

echo "\nThe artwork the markup points at is actually there\n";
foreach (['A','B','C','D'] as $styleKey) {
    foreach (['corner','top','side'] as $piece) {
        $f = $root . '/skins/chaplin/assets/svg/' . $styleKey . '-' . $piece . '.svg';
        cf_test(is_file($f), "skins/chaplin/assets/svg/{$styleKey}-{$piece}.svg exists");
    }
}

echo "\n";
echo "  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "CHAPLIN frame ornaments: FAIL ({$fail}).\n";
    exit(1);
}
echo "CHAPLIN frame ornaments: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
