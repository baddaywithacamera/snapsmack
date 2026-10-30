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

/** Handles out of a required_assets declaration in the runtime, by group. */
function hc_declared(string $runtime, string $group): array {
    $marker = "['required_assets']['" . $group . "'] = array_values(array_unique(array_merge(";
    $fnAt = strpos($runtime, 'function snapsmack_public_runtime(');
    $at = $fnAt === false ? false : strrpos($runtime, $marker);
    if ($at === false || $at < $fnAt) return [];
    $stmt = substr($runtime, $at, 900);
    $semi = strpos($stmt, ';');
    if ($semi !== false) $stmt = substr($stmt, 0, $semi);
    // THE ARRAY IMMEDIATELY BEFORE THE THREE CLOSING PARENS, not the first
    // bracketed thing in the statement -- which is ['required_assets'] and
    // which is exactly what the earlier version of this parse captured.
    if (!preg_match("/\[\s*('[^\[\]]*)\]\s*\)\s*\)\s*\)/s", $stmt, $m)) return [];
    preg_match_all("/'([A-Za-z0-9:_-]+)'/", $m[1], $hits);
    return $hits[1];
}

$runtime = (string) file_get_contents($root . '/core/public-runtime.php');
$scripts = hc_declared($runtime, 'scripts');
$styles  = hc_declared($runtime, 'styles');

echo "The declaration is read out of core, so this check is coupled to it\n";
hc_test($scripts !== [], 'script handles were parsed from snapsmack_public_runtime()');
hc_test($styles !== [], 'style handles were parsed from snapsmack_public_runtime()');
hc_test(in_array('asset:public:ss-engine-public-help', $scripts, true),
    'the F1 help engine is declared');
hc_test(in_array('asset:public:ss-engine-consent', $scripts, true),
    'the consent engine is declared');
hc_test(in_array('asset:public:ss-engine-consent', $styles, true),
    'the consent stylesheet is declared, or the banner would be unstyled');

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
foreach ($skins as $slug => $dir) {
    $html = $emit($manifests[$slug], []);
    if (!str_contains($html, 'assets/js/ss-engine-public-help.js')) $withoutHelp++;
    if (!str_contains($html, 'assets/js/ss-engine-consent.js')) $withoutConsent++;
}
hc_test($withoutHelp >= count($skins) - 1,
    'without the declaration the help engine is absent from ' . $withoutHelp
    . ' of ' . count($skins) . ' skins, so the check is measuring core');
hc_test($withoutConsent >= count($skins) - 1,
    'without the declaration the consent engine is absent from ' . $withoutConsent
    . ' of ' . count($skins) . ' skins');

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
