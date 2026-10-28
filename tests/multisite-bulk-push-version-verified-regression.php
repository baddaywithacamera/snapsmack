<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * PUSH ALL SKINS cannot report success while the sites stay on the old skin.
 *
 * The owner pushed the fleet, was told it worked, and then had to install
 * skins site by site -- the thing he asked about directly: "why do I have to
 * go through every site one at a time?" SS-1023.
 *
 * Two faults, and the second is what made the first invisible:
 *
 *   1. The hub picked each package out of a registry that skin_registry_fetch
 *      caches in the session for ten minutes. Publishing a package warms that
 *      cache, and publish-then-push is the owner's actual sequence, so the
 *      push handed every spoke the PREVIOUS download URL and signature.
 *
 *   2. Success was a COUNT. The spoke answered installed=N, failed=0, and the
 *      hub compared N against how many packages it sent. A spoke that
 *      reinstalled last week's package returns exactly that -- byte for byte
 *      the same answer as one that took the package published a minute ago.
 *      Nothing anywhere compared the version asked for with the version the
 *      site ended up running.
 *
 * So the hub now re-fetches the registry for a push, says which version each
 * package is for, and the spoke reports the version actually on disk after the
 * install. A site that cannot answer is reported NOT VERIFIED, never as done.
 */

$root = dirname(__DIR__);
$hub      = (string)file_get_contents($root . '/smack-multisite.php');
$spoke    = (string)file_get_contents($root . '/core/multisite-api.php');
$registry = (string)file_get_contents($root . '/core/skin-registry.php');

$fail = 0;
function bp_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

// ---------------------------------------------------------------------------
// 1. BEHAVIOURAL: a warm cache does not survive a forced fetch.
//    This is the fault itself, exercised rather than asserted. The cache is
//    primed with a registry that names an OLD package; a forced fetch must
//    come back with what is actually published now.
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.7');
require_once $root . '/core/skin-registry.php';

$published = sys_get_temp_dir() . '/snapsmack-registry-published-' . getmypid() . '.json';
file_put_contents($published, json_encode([
    'skins' => ['true-grit' => ['version' => '1.3.21', 'download_url' => 'https://example.test/true-grit-1.3.21.zip']],
]));

$_SESSION['skin_registry_cache'] = [
    'fetched' => time(),   // warm, as it is right after publishing
    'data'    => ['skins' => ['true-grit' => ['version' => '1.3.20', 'download_url' => 'https://example.test/true-grit-1.3.20.zip']]],
];

$cached = skin_registry_fetch($published);
bp_test(($cached['skins']['true-grit']['version'] ?? '') === '1.3.20',
    'the session cache is still used for an ordinary read, so nothing else slows down');

$fresh = skin_registry_fetch($published, true);
bp_test(($fresh['skins']['true-grit']['version'] ?? '') === '1.3.21',
    'a forced fetch ignores a warm cache and returns the package actually published');

$after = skin_registry_fetch($published);
bp_test(($after['skins']['true-grit']['version'] ?? '') === '1.3.21',
    'the forced fetch replaces the stale cache rather than leaving it to poison the next read');

@unlink($published);

// ---------------------------------------------------------------------------
// 2. The bulk push is one of the callers that must not read a cache.
// ---------------------------------------------------------------------------
$push_block = '';
if (preg_match('/push_all_skins_all_spokes.*?PUSH SKIN REINSTALL TO SPOKE/s', $hub, $m)) {
    $push_block = $m[0];
}
bp_test($push_block !== '', 'the bulk push block was found, so the checks below mean something');
bp_test(preg_match('/skin_registry_fetch\(\$registry_url,\s*true\)/', $push_block) === 1,
    'PUSH ALL SKINS re-fetches the registry instead of pushing whatever the session had');

// ---------------------------------------------------------------------------
// 3. The push says which version it is for, and the spoke reports what it got.
// ---------------------------------------------------------------------------
bp_test(str_contains($push_block, "'version'      => (string)(\$rskin['version'] ?? '')"),
    'each pushed package carries the version the hub is asking for');
bp_test(str_contains($spoke, "'version_installed' => \$ms_skin_version_on_disk(\$slug)"),
    'the spoke reports the version actually on disk after installing, not the one it was asked for');
bp_test(str_contains($spoke, "'version_requested' => \$version_want"),
    'the spoke echoes the requested version so the two can be compared');

// ---------------------------------------------------------------------------
// 4. Success is no longer a count.
// ---------------------------------------------------------------------------
bp_test(preg_match('/\$result\[\'ok\'\]\s*=.*?empty\(\$stale\)/s', $push_block) === 1,
    'a site running an older version than the one pushed is not reported as a success');
bp_test(preg_match('/\$result\[\'ok\'\]\s*=.*?empty\(\$unverified\)/s', $push_block) === 1,
    'a site that cannot say which version it ended up on is not reported as a success');
bp_test(str_contains($push_block, 'STILL ON THE OLD PACKAGE'),
    'the owner is told which skin stayed on the old package, by name and version');
bp_test(str_contains($push_block, 'NOT VERIFIED'),
    'the owner is told when a site could not be verified rather than being told it is done');

// ---------------------------------------------------------------------------
// 5. The comparison must be of REQUESTED against INSTALLED. A check that
//    compares a value with itself would pass everything above and prove
//    nothing, which is the shape of mistake that put this ticket here.
// ---------------------------------------------------------------------------
bp_test(preg_match('/\$want\s*!==\s*\$got/', $push_block) === 1,
    'the hub compares the version it asked for against the version that came back');
bp_test(preg_match('/\$want\s*===\s*\'\'\s*\|\|\s*\$got\s*===\s*\'\'/', $push_block) === 1,
    'a missing version on either side counts as not verified, not as a match');

echo "\n";
// ---------------------------------------------------------------------------
// 6. The single-site path is the owner's workaround for the bulk one, so it
//    must not serve a stale package either. The Skins screen renders the
//    download URL and signature that its install buttons post back, which
//    makes it an action, not a display read.
// ---------------------------------------------------------------------------
$skins_page = (string)file_get_contents($root . '/smack-skin.php');
bp_test(preg_match('/\$registry\s*=\s*skin_registry_fetch\(\$registry_url,\s*true\)/', $skins_page) === 1,
    'the Skins screen renders install buttons from a fresh registry, not a cached one');

// ---------------------------------------------------------------------------
// 7. ONLY WHAT IS BEHIND. The bulk push used to package every skin a spoke
//    had installed and reinstall all of them whatever version they were on,
//    so a fleet of 25 sites re-downloaded dozens of unchanged skins to
//    deliver one changed file. The owner: no time for manual site updates.
//    The hub already knows each spoke's installed version from the heartbeat.
// ---------------------------------------------------------------------------
bp_test(preg_match('/snap_version_compare\(\$registryVersion,\s*\$spokeVersion/', $push_block) === 1,
    'the fleet push compares the registry version against the version each spoke reports');
bp_test(str_contains($push_block, 'if (!$bulk_force) {')
     && preg_match("/!snap_version_compare.{0,200}continue;/s", $push_block) === 1,
    'a skin already on the current version is not packaged at all');
bp_test(str_contains($push_block, '$bulk_force'),
    'a full reinstall is still possible, as its own explicit action');
bp_test(str_contains($hub, "push_all_skins_force"),
    'the force path is a separate POST field, not the default');

// A version that cannot be compared must be SENT, never silently skipped,
// or "skipped" would stop meaning "checked and already current".
bp_test(str_contains($push_block, '$unknown[] = $slug;'),
    'a skin whose version cannot be compared is sent rather than skipped');
bp_test(str_contains($push_block, 'Already current:'),
    'each site reports which skins were already current');
bp_test(str_contains($push_block, 'could not be compared'),
    'each site reports anything whose version could not be compared');

// The button must not promise a full reinstall any more.
bp_test(!str_contains($hub, 'Reinstall EVERY registry skin already present on EVERY active spoke?'),
    'the primary fleet button no longer reinstalls every skin on every spoke');
bp_test(str_contains($hub, 'UPDATE EVERY SPOKE THAT IS BEHIND'),
    'the primary fleet action says what it does');

if ($fail > 0) {
    echo "Multisite bulk push version verification: FAIL ({$fail}).\n";
    exit(1);
}
echo "Multisite bulk push version verification: PASS.\n";

// ===== SNAPSMACK EOF =====
