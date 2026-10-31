<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The F1 help modal and the consent banner must reach every strict skin.
 *
 * The owner found both missing himself, on a live site, on 29 October 2026:
 * "the F1 help modal in SMACKONEOUT does not work, gone" and "the
 * accept-cookies notice is gone". Then: "put them back."
 *
 * Same fault as Thomas the Bear. core/footer.php:194 emitted the help engine
 * and core/footer-scripts.php:28-30 emitted the consent engine, its stylesheet
 * and the banner partial. All four are the LEGACY path, which index.php never
 * reaches for a strict skin, and all 30 renderable skins are strict.
 *
 * SHAPED LIKE tests/thomas-the-bear-reaches-every-skin.php DELIBERATELY: the
 * handles are PARSED OUT OF CORE and fed to the real resolver, so deleting the
 * declaration turns every skin red. A test that supplies its own handle would
 * pass with the repair removed, which is the mistake the Thomas test made in
 * its first version and the meta.php check made for a month.
 *
 * WHAT THIS CANNOT SEE, said rather than discovered later: it renders the
 * component and reads the markup. It does not press F1, it does not click
 * Allow preferences, and it cannot tell whether a browser then stores the
 * choice. Those stay NOT CHECKED until somebody looks at a deployed page.
 */

$root = dirname(__DIR__);
require_once $root . '/core/asset-registry.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/consent-runtime.php';

$pass = 0;
$fail = 0;

function hc_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

// WHY THERE IS NO LONGER A PARSER HERE.
//
// This read core/public-runtime.php as TEXT and pulled quoted handles out of
// the array_merge(...) that assigns required_assets. The declaration moved
// into snapsmack_public_engine_assets(), so the statement now reads
//
//     $engines = snapsmack_public_engine_assets();
//     $response['required_assets']['scripts'] = array_values(array_unique(
//         array_merge((array)($response['required_assets']['scripts'] ?? []),
//                     $engines['scripts'])));
//
// and the parser walked back from ')))' to the nearest bracket pair, which is
// $engines['scripts']. So it returned the single handle 'scripts'. Non-empty,
// so "handles were parsed" PASSED, while every real handle was gone and all
// thirty skins were reported as missing the consent banner. That reads as a
// fleet-wide privacy fault and it was this file being wrong.
//
// It had already been patched once for the same class of problem -- a fixed
// character window that broke when a comment was added inside the array. Two
// repairs to a parser that should not exist is the argument for asking the
// runtime instead. It is a function; call it.

require_once $root . '/core/public-runtime.php';

$engines = snapsmack_public_engine_assets();
$scripts = $engines['scripts'] ?? [];
$styles  = $engines['styles'] ?? [];

echo "The declaration comes from core itself, so this check is coupled to it\n";
hc_test($scripts !== [], 'snapsmack_public_engine_assets() declares no scripts at all');
hc_test($styles !== [], 'snapsmack_public_engine_assets() declares no styles at all');

// A function that exists and is never called declares nothing. The runtime
// source is read with comments stripped, because the prose above explains the
// fault by quoting the code, and a check that can match its own explanation
// proves nothing -- which is exactly how this file went green while broken.
$runtimeCode = '';
foreach (token_get_all((string) file_get_contents($root . '/core/public-runtime.php')) as $t) {
    if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
    $runtimeCode .= is_array($t) ? $t[1] : $t;
}
hc_test((bool) preg_match('/=\\s*snapsmack_public_engine_assets\\s*\\(/', $runtimeCode),
    'nothing in core/public-runtime.php CALLS snapsmack_public_engine_assets(), '
  . 'so the handles it returns never reach a page. Checked as an assignment from '
  . 'the call, because the name on its own also matches the function definition '
  . 'and this check passed on that for its first run.');
hc_test((bool) preg_match('/required_assets[^;]*\$engines\s*\[\s*.scripts/s', $runtimeCode),
    'the engine scripts are no longer merged into required_assets, so whatever '
  . 'snapsmack_public_engine_assets() returns goes nowhere. What is checked: the '
  . 'engines variable is subscripted with scripts INSIDE a required_assets '
  . 'statement, with no semicolon between the two.');
hc_test((bool) preg_match('/required_assets[^;]*\$engines\s*\[\s*.styles/s', $runtimeCode),
    'the engine styles are no longer merged into required_assets, so the consent banner would render unstyled');
hc_test(in_array('asset:public:ss-engine-public-help', $scripts, true),
    'the F1 help engine is declared');
hc_test(in_array('asset:public:ss-engine-consent', $scripts, true),
    'the consent engine is declared');
hc_test(in_array('asset:public:ss-engine-consent', $styles, true),
    'the consent stylesheet is declared, or the banner would be unstyled');
hc_test(in_array('smack-keyboard', $scripts, true),
    'the keyboard engine is declared, so F1 has something listening on it');

echo "\nEvery renderable skin gets all three files\n";
$skins = [];
foreach (glob($root . '/skins/*', GLOB_ONLYDIR) ?: [] as $dir) {
    if (is_file($dir . '/layout.php')) $skins[basename($dir)] = $dir;
}
ksort($skins);
hc_test(count($skins) >= 30,
    'at least 30 renderable skins were found (found ' . count($skins) . ')');

$emit = static function (array $manifest, array $required): string {
    return (string) snap_render_component('registered-assets', [
        'assets' => snapsmack_merge_registered_assets(
            snapsmack_skin_declared_assets($manifest), $required),
    ]);
};

$need = [
    'assets/js/ss-engine-public-help.js' => 'the F1 help modal',
    'assets/js/ss-engine-consent.js'     => 'the consent engine',
    'assets/css/ss-engine-consent.css'   => 'the consent stylesheet',
    // The keyboard engine belongs in this check rather than its own: it is the
    // half of the F1 chain that BINDS the key (ss-engine-comms.js:77) and hands
    // off to window.snapPublicHelp. core/meta.php shipped it to every skin
    // before the conversion; afterwards only the 24 skins declaring
    // smack-keyboard had it, so six had no F1 at all -- not even the fallback
    // modal the comms engine falls back to. A help modal nothing can open is
    // the same defect as a help modal that is absent.
    'assets/js/ss-engine-comms.js'       => 'the keyboard engine that binds F1',
    'assets/css/ss-engine-comms.css'     => 'the keyboard engine stylesheet',
];
$missing = [];
$manifests = [];
foreach ($skins as $slug => $dir) {
    $manifest = is_file($dir . '/manifest.json')
        ? json_decode((string)file_get_contents($dir . '/manifest.json'), true) : [];
    if (!is_array($manifest)) $manifest = [];
    $manifests[$slug] = $manifest;
    $html = $emit($manifest, ['scripts' => $scripts, 'styles' => $styles]);
    foreach ($need as $file => $what) {
        if (!str_contains($html, $file)) $missing[$what][] = $slug;
    }
}
foreach ($need as $file => $what) {
    hc_test(empty($missing[$what]),
        $what . ' reaches every skin'
        . (empty($missing[$what]) ? '' : ' -- missing from: ' . implode(', ', $missing[$what])));
}

echo "\nMUTATION PROOF: remove the declaration and the skins go red\n";
$withoutHelp = 0;
$withoutConsent = 0;
$withoutKeyboard = 0;
foreach ($skins as $slug => $dir) {
    $html = $emit($manifests[$slug], []);
    if (!str_contains($html, 'assets/js/ss-engine-public-help.js')) $withoutHelp++;
    if (!str_contains($html, 'assets/js/ss-engine-consent.js')) $withoutConsent++;
    if (!str_contains($html, 'assets/js/ss-engine-comms.js')) $withoutKeyboard++;
}
// EXACTLY all of them, not "at least all but one".
//
// While the parser above was broken these two assertions passed for the wrong
// reason: the engines were missing from 30 of 30 WITH the declaration as well
// as without it, so the mutation changed nothing and the control proved
// nothing. A control that cannot fail is decoration. Measured with the
// declaration restored: 0 of 30 missing with it, 30 of 30 without.
hc_test($withoutHelp === count($skins),
    'without the declaration the help engine should be absent from all '
    . count($skins) . ' skins and was absent from ' . $withoutHelp
    . '. If that is not all of them, something other than the core declaration '
    . 'is putting it on a page and this check is not measuring core.');
hc_test($withoutConsent === count($skins),
    'without the declaration the consent engine should be absent from all '
    . count($skins) . ' skins and was absent from ' . $withoutConsent . '.');
// The keyboard engine is the one case where the manifests carry real cover:
// 24 of 30 declare it themselves, so removing the core declaration should
// leave exactly the six that lost it. That number is the fault Sean hit.
hc_test($withoutKeyboard === 6,
    'without the declaration the keyboard engine is absent from exactly 6 skins '
    . '(got ' . $withoutKeyboard . ') -- the six whose manifests never declared it');

echo "\nThe banner markup is the one the engine can actually drive\n";
// ss-engine-consent.js binds these two by id. Renaming either silently breaks
// Allow preferences, which looks like a working banner that does nothing.
$banner = snapsmack_consent_banner_html(['show' => true, 'privacy_url' => '']);
hc_test(str_contains($banner, 'id="snap-consent-banner"'), 'the banner element is present');
hc_test(str_contains($banner, 'id="snap-consent-accept"'),
    'the Allow preferences button keeps the id ss-engine-consent.js binds');
hc_test(str_contains($banner, 'id="snap-consent-decline"'),
    'the Continue without button keeps its id');
hc_test(str_contains($banner, 'role="dialog"'), 'it is still announced as a dialog');
hc_test(!str_contains($banner, 'Privacy policy'),
    'no privacy-policy link when the site has no policy page');

$linked = snapsmack_consent_banner_html(['show' => true, 'privacy_url' => '/page/privacy']);
hc_test(str_contains($linked, 'href="/page/privacy"'),
    'and the link appears when there is a policy to point at');

echo "\nA visitor who has already decided is not asked again\n";
hc_test(snapsmack_consent_banner_html(['show' => false, 'privacy_url' => '']) === '',
    'nothing is emitted once the choice is recorded');
hc_test(snapsmack_consent_banner_html([]) === '',
    'and nothing is emitted when the runtime prepared nothing at all');

echo "\nThe consumers still FAIL CLOSED, which is what made this safe\n";
// Every consumer tests window.snapConsent before touching storage, so while
// the engine was missing nothing was written rather than everything. If a
// future edit drops that guard, a missing engine becomes a privacy fault
// instead of a dormant feature. Source-read, and said to be.
foreach (['assets/js/ss-engine-comms.js', 'assets/js/ss-engine-photogram.js'] as $consumer) {
    $src = (string) @file_get_contents($root . '/' . $consumer);
    $guards = preg_match_all('/window\.snapConsent\s*&&/', $src);
    $bare = preg_match_all('/(?<!window\.snapConsent && )window\.snapConsent\.(ok|pending)\(/', $src);
    hc_test($guards > 0 && $bare === 0,
        basename($consumer) . ' only reads window.snapConsent behind an existence check'
        . ($bare === 0 ? '' : " ({$bare} unguarded)"));
}

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "F1 help and consent banner: FAIL ({$fail}).\n";
    exit(1);
}
echo "F1 help and consent banner: PASS ({$pass} assertions across "
    . count($skins) . " skins).\n";
echo "  NOT CHECKED: pressing F1 on a deployed page, clicking Allow preferences,\n";
echo "  and whether the choice is then stored. This renders markup; it drives no browser.\n";

// ===== SNAPSMACK EOF =====
