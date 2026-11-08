<?php
declare(strict_types=1);

/**
 * A STRICT SKIN MUST REPAIR ITS OWN STALE OPTION CSS.
 *
 * The compiled `custom_css_public` blob is a cache. It goes stale whenever the
 * CMS version or the skin version moves, and four skins (STANLEY, TELEGRAM,
 * TILEZ, WRITING WITH IMPACT) print ONLY that blob -- so while it is stale,
 * every declared CSS control on them does nothing. Sean's words: "I smell
 * disconnected controls."
 *
 * The self-heal existed, in core/meta.php. core/meta.php is the LEGACY head,
 * and core/public-runtime.php:21 says in core's own words that "Strict public
 * skins do not include core/meta.php" -- index.php's strict branches exit at
 * 326 and 535, and the first skin-meta include is line 601. So on the whole
 * strict fleet the repair could never run.
 *
 * AND THE TEST THAT GUARDED IT ONLY READ THE FILE.
 * tests/public-skin-css-self-heal-regression.php asserts
 *     str_contains($meta, 'snapsmack_recompile_public_skin_css')
 * which passes because the function NAME appears in a file strict rendering
 * never loads. It would keep passing if meta.php left the render path
 * entirely, which is effectively what happened.
 *
 * So this file CALLS the repair instead of looking for it.
 */

$root = dirname(__DIR__);
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.7.976D');
if (!defined('BASE_URL')) define('BASE_URL', '/');

require_once $root . '/core/skin-css-recompile.php';

$pass = 0;
$fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

/** A database with just enough in it for the compiler to run. */
function fresh_pdo(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)");
    return $pdo;
}

// A real installed skin with declared options, so the stamp is non-empty.
$skin = 'impact-printer';
$target = snapsmack_public_css_target_stamp($skin);
cx($target !== '', "the target stamp for {$skin} is computable: '{$target}'");

// 986D: THE STAMP MOVES ON A SAME-VERSION MANIFEST OPTION CHANGE.
// SS-1214 repointed NH's header_font_family selector with no version bump
// (the skin digest excludes manifest.json), and the stamp used to key on the
// version alone, so the compiled option CSS kept the old selector. A hash of
// the options is now folded in, so any selector/property change forces a
// recompile at the same version.
$m0 = ['version' => '1.3.30', 'options' => ['f' => ['selector' => '.logo-area a', 'property' => 'font-family']]];
$m1 = ['version' => '1.3.30', 'options' => ['f' => ['selector' => '.site-title-text', 'property' => 'font-family']]];
$s0 = snapsmack_public_css_target_stamp('new-horizon', $m0);
$s1 = snapsmack_public_css_target_stamp('new-horizon', $m1);
cx($s0 !== '' && $s1 !== '' && $s0 !== $s1,
   "a same-version selector change moves the stamp ('{$s0}' -> '{$s1}')");
cx(str_contains($s0, '+opt@'), 'the stamp carries a manifest-options hash (+opt@)');
cx(snapsmack_public_css_target_stamp('new-horizon', $m0) === $s0,
   'the same manifest hashes to the same stamp (stable, so it does not recompile forever)');

echo "\nTHE FUNCTION THE STRICT PATH NEEDS\n";
$exists = function_exists('snapsmack_public_css_refresh_if_stale');
cx($exists, 'snapsmack_public_css_refresh_if_stale() exists in core');

if (!$exists) {
    echo "\n  Nothing further can be measured: the strict path has no repair to call.\n";
    echo "\n{$pass} checks, {$fail} failed\n";
    exit(1);
}

echo "\nA STALE BLOB IS REPAIRED, AND THE CALLER SEES THE REPAIR\n";
$pdo = fresh_pdo();
$settings = [
    'active_skin' => $skin,
    'custom_css_public' => "/* SKIN_ID someone-else@0.0.1+cms@0.0.0 */\n.old { color: red }",
    'custom_css_public_stamp' => 'someone-else@0.0.1+cms@0.0.0',
];
$did = snapsmack_public_css_refresh_if_stale($pdo, $skin, $settings);
cx($did === true, 'it reports that it recompiled');
cx(str_contains((string)$settings['custom_css_public'], '/* SKIN_ID ' . $target . ' */'),
   'the blob handed back to the caller now carries THIS skin and version');
cx(!str_contains((string)$settings['custom_css_public'], 'someone-else'),
   "and no longer carries the other skin's identity");
cx((string)$settings['custom_css_public_stamp'] === $target,
   'the stamp handed back matches the target');

echo "\nA BLOB FROM ANOTHER SKIN IS NOT TRUSTED JUST BECAUSE THE STAMP MATCHES\n";
// The skin-switch case, and the reason the identity test exists at all. A
// mutant that dropped the identity check and kept only the stamp comparison
// survived until this was here, because the case above has BOTH wrong.
$pdoSwitch = fresh_pdo();
$settingsSwitch = [
    'custom_css_public' => "/* SKIN_ID photogram@9.9.9+cms@0.0.1 */\n.pg { color: lime }",
    'custom_css_public_stamp' => $target,          // right stamp, wrong contents
];
$didSwitch = snapsmack_public_css_refresh_if_stale($pdoSwitch, $skin, $settingsSwitch);
cx($didSwitch === true,
   'a correct stamp over another skin\'s CSS is still stale and gets recompiled');
cx(!str_contains((string)$settingsSwitch['custom_css_public'], 'photogram@9.9.9'),
   "and the other skin's identity is gone afterwards");

echo "\nA FRESH BLOB IS LEFT ALONE\n";
$pdo2 = fresh_pdo();
snapsmack_recompile_public_skin_css($pdo2, $skin);
$rows = $pdo2->query("SELECT setting_key, setting_val FROM snap_settings
                      WHERE setting_key IN ('custom_css_public','custom_css_public_stamp')")
             ->fetchAll(PDO::FETCH_KEY_PAIR);
$settings2 = [
    'custom_css_public' => $rows['custom_css_public'] ?? '',
    'custom_css_public_stamp' => $rows['custom_css_public_stamp'] ?? '',
];
$before = $settings2['custom_css_public'];
$did2 = snapsmack_public_css_refresh_if_stale($pdo2, $skin, $settings2);
cx($did2 === false, 'it reports that there was nothing to do');
cx($settings2['custom_css_public'] === $before, 'and does not touch the blob');

echo "\nRENDERING MUST NOT FAIL OVER A CACHE\n";
$broken = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// No snap_settings table at all: every write the compiler attempts will throw.
$settings3 = ['custom_css_public' => '', 'custom_css_public_stamp' => 'stale'];
$threw = false;
try { snapsmack_public_css_refresh_if_stale($broken, $skin, $settings3); }
catch (Throwable $e) { $threw = true; }
cx($threw === false, 'a database that cannot be written swallows the failure instead of 500ing the page');

echo "\nAN UNKNOWN SKIN IS NOT GUESSED AT\n";
$pdo4 = fresh_pdo();
$settings4 = ['custom_css_public' => 'x', 'custom_css_public_stamp' => ''];
$did4 = snapsmack_public_css_refresh_if_stale($pdo4, 'no-such-skin-here', $settings4);
cx($did4 === false, 'a skin with no manifest is left completely alone');
cx($settings4['custom_css_public'] === 'x', 'and its blob is untouched');

// --------------------------------------------------------------------------
// WIRING. This part IS a source check and is labelled as one: whether
// index.php calls the repair is structural, and short of booting a request
// there is no way to observe it from here. It is deliberately precise -- it
// requires the call to appear in the same array literal that builds the view,
// above the line that prints the blob -- so moving either one apart from the
// other fails.
// --------------------------------------------------------------------------
echo "\nWIRED INTO BOTH STRICT VIEW BUILDS (source check, not behaviour)\n";
$index = (string)file_get_contents($root . '/index.php');
$sites = substr_count($index, "'skin_custom_style' => snapsmack_skin_custom_style(\$settings)");
cx($sites === 2, "index.php still builds the strict view in exactly 2 places (found {$sites})");

$refreshCalls = substr_count($index, 'snapsmack_public_css_refresh_if_stale(');
cx($refreshCalls >= 2, "the repair is called at least once per strict view build (found {$refreshCalls})");

// Each strict view build must have a repair call BEFORE it, within 40 lines.
$lines = preg_split('/\R/', $index) ?: [];
$ok = 0;
foreach ($lines as $n => $line) {
    if (!str_contains($line, "'skin_custom_style' => snapsmack_skin_custom_style(\$settings)")) continue;
    for ($back = max(0, $n - 40); $back < $n; $back++) {
        if (str_contains($lines[$back], 'snapsmack_public_css_refresh_if_stale(')) { $ok++; break; }
    }
}
cx($ok === 2, "both view builds are preceded by a repair call (found {$ok} of 2)");

echo "\nAND meta.php USES THE SAME FUNCTION, NOT ITS OWN COPY\n";
$meta = (string)file_get_contents($root . '/core/meta.php');
// THE WHOLE CALL, NOT THE BARE NAME. A mutant that deleted the call survived
// the name check, because the comment I had written directly above it also
// mentions the function -- a comment satisfying a name count is precisely the
// fault I flagged in somebody else's test an hour earlier.
cx(str_contains($meta, 'snapsmack_public_css_refresh_if_stale($pdo, (string)$active_skin, $settings);'),
   'the legacy head actually calls the shared repair, arguments and all');
cx(!str_contains($meta, "WHERE setting_key IN ('custom_css_public', 'custom_css_public_stamp')"),
   'and no longer carries its own inline duplicate of it');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
