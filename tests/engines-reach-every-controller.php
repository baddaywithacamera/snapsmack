<?php
declare(strict_types=1);

/**
 * Thomas and the other three engines reach every skin BY THE ROUTE THAT SKIN
 * ACTUALLY USES.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * WHY THIS EXISTS AND WHY IT IS NOT THE SAME AS THE OTHER ENGINE TESTS.
 *
 * On 30 October 2026 the owner asked for Thomas the Bear back on every site,
 * no exceptions. He was told in the 0.7.972D notes that the restore reached
 * "all 30 skins". It did not. It reached all 30 skins ALONG ONE ROUTE.
 *
 * index.php has two public render branches:
 *   cms_controller = smacktalk -> snapsmack_smacktalk_request()
 *   everything else            -> snapsmack_public_runtime()
 *
 * Only the second declared the engines. So on the five skins whose manifest
 * sets cms_controller=smacktalk -- ALFRED, STANLEY, TELEGRAM, TILEZ,
 * WRITING-WITH-IMPACT -- Thomas, the F1 help modal, the cookie notice and the
 * keyboard engine were ALL absent. Confirmed on live sites already running
 * 0.7.972D: baddaywithacamera.ca (TILEZ) served ss-engine-thomas.js zero
 * times, acolourlesslife.ca (CHAPLIN) served it once.
 *
 * tests/thomas-the-bear-reaches-every-skin.php was green throughout, because
 * it renders all 30 skins through the public-runtime asset set. That is a
 * true statement about a component and a false one about the product. This
 * file is the difference: it reads each skin's OWN controller out of its
 * manifest and builds the asset set the way index.php builds it for THAT
 * controller.
 *
 * The rule it enforces: one declaration, every controller.
 *
 * Run: php tests/engines-reach-every-controller.php
 */

$root = dirname(__DIR__);
chdir($root);
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-render-helpers.php';
require_once $root . '/core/skin-view-contract.php';
require_once $root . '/core/asset-registry.php';
require_once $root . '/core/public-runtime.php';
if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');

$checks = 0;
$failures = [];
function e_ok(bool $cond, string $why): void {
    global $checks, $failures;
    $checks++;
    if (!$cond) $failures[] = $why;
}

// ── there is ONE declaration, and index.php uses it in BOTH branches ────────

e_ok(function_exists('snapsmack_public_engine_assets'),
    'snapsmack_public_engine_assets() is gone — the engine list has no single home again');

$engines = snapsmack_public_engine_assets();
foreach (['smack-thomas', 'asset:public:ss-engine-public-help',
          'asset:public:ss-engine-consent', 'smack-keyboard'] as $handle) {
    e_ok(in_array($handle, $engines['scripts'], true),
        "the shared engine list no longer declares {$handle}");
}
e_ok(in_array('asset:public:ss-engine-consent', $engines['styles'], true),
    'the shared engine list no longer declares the consent stylesheet');

// COMMENTS ARE STRIPPED BEFORE ANY OF THIS IS SEARCHED.
//
// The first version of this file searched the raw source, and its own
// mutation proof passed when the repair was deleted -- because the comment
// ABOVE the repair still contained the function's name, so a substring search
// found it and reported the call present. A comment is not evidence. That is
// the house rule and this file broke it within an hour of being written.
$indexCode = '';
foreach (token_get_all((string) file_get_contents($root . '/index.php')) as $token) {
    if (is_array($token)) {
        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) continue;
        $indexCode .= $token[1];
    } else {
        $indexCode .= $token;
    }
}

e_ok(!str_contains($indexCode, 'The list is snapsmack_public_engine_assets() so there is ONE'),
    'comment stripping is not working, so every check below could pass on prose');

// The smacktalk branch must merge the shared list IN CODE. This is the repair;
// if it goes, five skins silently lose all four engines again.
$smacktalkPos = strpos($indexCode, "=== 'smacktalk'");
e_ok($smacktalkPos !== false, 'the smacktalk controller branch is gone from index.php');

$smacktalkMerges = (bool) preg_match(
    '/\$_registered_skin_assets\s*=\s*snapsmack_merge_registered_assets\(\s*'
    . '\$_registered_skin_assets\s*,\s*snapsmack_public_engine_assets\(\)\s*\)/',
    $indexCode);
e_ok($smacktalkMerges,
    'index.php no longer merges snapsmack_public_engine_assets() into the smacktalk branch — '
    . 'ALFRED, STANLEY, TELEGRAM, TILEZ and WRITING-WITH-IMPACT have lost Thomas, the help '
    . 'modal, the cookie notice and the keyboard engine');

if ($smacktalkMerges) {
    $mergePos = (int) strpos($indexCode, 'snapsmack_public_engine_assets()');
    e_ok($smacktalkPos !== false && $mergePos > $smacktalkPos,
        'the engine merge is no longer inside the smacktalk branch');
}

// The consent decision needs the database, which only the controller has.
e_ok(str_contains($indexCode, "\$GLOBALS['snapsmack_consent'] = snapsmack_consent_prepare("),
    'the smacktalk branch no longer prepares the consent decision, so the banner cannot render');

// And nobody may re-inline the list, which is how it drifted the first time.
e_ok(substr_count($indexCode, "'smack-thomas'") === 0,
    'index.php inlines the Thomas handle again — it belongs in the shared list, with one declaration');

// ── render every skin by ITS OWN controller ────────────────────────────────

$frameTokenSkins = ['aurora','game-on','heuristic','instant-camera','jive-turkey',
                    'parade','sliders','sudden-impact','the-grid'];

$missing = [];
$byController = ['public' => 0, 'smacktalk' => 0];

foreach (glob($root . '/skins/*/manifest.json') ?: [] as $manifestPath) {
    $skin = basename(dirname($manifestPath));
    $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
    $controller = (string) ($manifest['cms_controller'] ?? 'public');
    if (!isset($byController[$controller])) $byController[$controller] = 0;
    $byController[$controller]++;

    // Exactly as index.php assembles it, for whichever branch this skin takes.
    $assets = snapsmack_skin_declared_assets($manifest);
    if (in_array($skin, $frameTokenSkins, true)) {
        $assets = snapsmack_merge_registered_assets($assets,
            ['styles' => ['asset:public:ss-frame-tokens']]);
    }
    // THE BRANCH THE SKIN REALLY TAKES. The public controller always merges
    // the engines; the smacktalk branch only does so while the repair above
    // is present. Reading that from the source rather than assuming it is
    // what makes this render half able to FAIL -- the first version merged
    // unconditionally and so could only ever agree with itself.
    if ($controller !== 'smacktalk' || $smacktalkMerges) {
        $assets = snapsmack_merge_registered_assets($assets, snapsmack_public_engine_assets());
    }

    ob_start();
    echo snap_render_html(snap_render_component('registered-assets', ['assets' => $assets]));
    $html = (string) ob_get_clean();

    foreach ([
        'ss-engine-thomas.js'      => 'Thomas the Bear',
        'ss-engine-public-help.js' => 'the F1 help modal',
        'ss-engine-consent.js'     => 'the cookie notice',
        'ss-engine-comms.js'       => 'the keyboard engine',
    ] as $file => $label) {
        $present = str_contains($html, $file);
        if (!$present) $missing[] = "{$skin} ({$controller}) is missing {$label}";
        e_ok($present, "{$skin}, which renders through the {$controller} controller, "
            . "no longer receives {$label}");
    }
}

// The fault was invisible because one controller had only five skins on it.
// Assert BOTH branches are actually represented, or this file proves nothing.
e_ok(($byController['public'] ?? 0) >= 20,
    'fewer than 20 skins on the public controller — the sweep is not covering the fleet');
e_ok(($byController['smacktalk'] ?? 0) >= 5,
    'fewer than 5 skins on the smacktalk controller — the branch that broke is not being exercised, '
    . 'which is exactly the hole this file exists to close');

e_ok($missing === [], 'engines missing: ' . implode('; ', $missing));

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

printf("PASS: all four engines reach every skin by its own controller (%d checks)\n", $checks);
printf("      public: %d skins   smacktalk: %d skins\n",
    $byController['public'] ?? 0, $byController['smacktalk'] ?? 0);
// ===== SNAPSMACK EOF =====
