<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * A re-baselined release must actually reach a site that holds the old number.
 *
 * WHY THIS IS SEPARATE from the rule test. tests/version-series-gate-
 * regression.php proves the RULES are right. This proves the UPDATE CHECKS
 * CALL THEM. A rule nothing calls is the fault class that put a sharing
 * setting in the product with no control able to set it, and the one I hit
 * again last night when my tests exercised a tool's functions instead of the
 * tool. So this drives the real comparison functions with the real shapes.
 *
 * THE FAULT IT GUARDS: IMPACT PRINTER goes 2.1.30 -> 0.7.31. As plain semver
 * that is a DOWNGRADE, so every site holding 2.1.30 would decide it is already
 * up to date, for ever, silently. The epoch is the only thing that makes the
 * new release newer, and it is worthless if the comparison does not read it.
 */

$root = dirname(__DIR__);
require_once $root . '/core/constants.php';
require_once $root . '/core/version-series.php';

$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) { $fails[] = $what; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

// ---------------------------------------------------------------------------
// skin_registry_compare(): the Skin Updates screen.
// ---------------------------------------------------------------------------
if (!defined('SKINS_DIR')) { define('SKINS_DIR', $root . '/skins'); }
require_once $root . '/core/skin-registry.php';

$local = ['impact-printer' => ['name' => 'IMPACT PRINTER', 'version' => '2.1.30', 'status' => 'stable']];
$registry = ['skins' => ['impact-printer' => [
    'name' => 'IMPACT PRINTER', 'version' => '0.7.31', 'version_epoch' => 1, 'status' => 'stable',
]]];
$compared = skin_registry_compare($registry, $local);
$check('A RE-BASELINED SKIN IS OFFERED to a site holding 2.1.30',
    ($compared['impact-printer']['update_available'] ?? null) === true);

// Without the epoch the same pair must NOT offer an update: that is the
// failure this guards, stated as a test rather than as a comment.
$noEpoch = ['skins' => ['impact-printer' => [
    'name' => 'IMPACT PRINTER', 'version' => '0.7.31', 'status' => 'stable',
]]];
$check('without the epoch the same release reads as a downgrade (the fault)',
    (skin_registry_compare($noEpoch, $local)['impact-printer']['update_available'] ?? null) === false);

// An ordinary in-series bump must still work, epoch or not.
$tilez = ['tilez' => ['name' => 'TILEZ', 'version' => '0.2.79', 'status' => 'stable']];
$check('an ordinary bump 0.2.79 -> 0.2.80 is still offered',
    (skin_registry_compare(['skins' => ['tilez' => ['version' => '0.2.80']]], $tilez)['tilez']['update_available'] ?? null) === true);
$check('the same version is not offered as an update',
    (skin_registry_compare(['skins' => ['tilez' => ['version' => '0.2.79']]], $tilez)['tilez']['update_available'] ?? null) === false);
$check('an older remote version is never offered',
    (skin_registry_compare(['skins' => ['tilez' => ['version' => '0.2.78']]], $tilez)['tilez']['update_available'] ?? null) === false);

// A site that has ALREADY taken the re-baselined skin must not be offered it
// again, or every site loops on the same download.
$rebased = ['impact-printer' => ['name' => 'IMPACT PRINTER', 'version' => '0.7.31', 'version_epoch' => 1]];
$check('a site already on the re-baselined version is left alone',
    (skin_registry_compare($registry, $rebased)['impact-printer']['update_available'] ?? null) === false);
$check('the next bump after the re-baseline is offered',
    (skin_registry_compare(['skins' => ['impact-printer' => [
        'version' => '0.7.32', 'version_epoch' => 1]]], $rebased)['impact-printer']['update_available'] ?? null) === true);
$check('a skin that is not installed is never an update',
    (skin_registry_compare($registry, [])['impact-printer']['update_available'] ?? null) === false);

// ---------------------------------------------------------------------------
// updater_check_status(): the core release check.
// ---------------------------------------------------------------------------
// LOADING THE UPDATER WRITES TO THE INSTALL. core/updater.php self-heals
// .htaccess and .user.ini on include, so the first run of this test created a
// .user.ini in the working tree and I deleted it. A test must not change the
// tree it is testing, so the two files are snapshotted here and put back
// afterwards -- and the restoration is CHECKED, not assumed, because an
// uncaught failure here leaves litter in Sean's checkout.
$guarded = ['.htaccess', '.user.ini'];
$before = [];
foreach ($guarded as $name) {
    $path = $root . '/' . $name;
    $before[$name] = is_file($path) ? (string)file_get_contents($path) : null;
}

require_once $root . '/core/updater.php';

$restored = [];
foreach ($guarded as $name) {
    $path = $root . '/' . $name;
    if ($before[$name] === null) {
        if (is_file($path)) { @unlink($path); }
        $restored[$name] = !is_file($path);
    } else {
        if (!is_file($path) || (string)file_get_contents($path) !== $before[$name]) {
            @file_put_contents($path, $before[$name]);
        }
        $restored[$name] = is_file($path) && (string)file_get_contents($path) === $before[$name];
    }
}
foreach ($restored as $name => $ok) {
    $check('loading the updater left ' . $name . ' exactly as it was', $ok);
}
$check('a re-baselined CORE release is offered to a site holding 2.1.30',
    updater_check_status('2.1.30', ['version' => '0.7.31', 'version_epoch' => 1]) === 'update_available');
$check('without an epoch the core release is not offered (the fault)',
    updater_check_status('2.1.30', ['version' => '0.7.31']) !== 'update_available');
$check('an ordinary core bump still works',
    updater_check_status('0.7.1006', ['version' => '0.7.1007']) === 'update_available');
$check('the same core version is not an update',
    updater_check_status('0.7.1007', ['version' => '0.7.1007']) !== 'update_available');
$check('an error from the server is still reported as an error',
    updater_check_status('0.7.1007', ['error' => 'no answer']) === 'error');

// ---------------------------------------------------------------------------
// The wiring itself: the comparison must not be reachable with a bare version
// where an entry is available. This is a source check and it is the weakest
// thing here, so it is stated as what it is.
// ---------------------------------------------------------------------------
foreach (['core/skin-registry.php', 'core/updater.php'] as $file) {
    $source = (string)file_get_contents($root . '/' . $file);
    $check($file . ' compares through snap_version_with_epoch',
        str_contains($source, 'snap_version_with_epoch('));
}

echo "\n";
if ($fails) {
    echo "FAILED (" . count($fails) . "):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL PASS\n";

// NOT CHECKED: the Skin Packager writing version_epoch into a package, and an
// installed site taking the update over the wire. The first is the next
// commit; the second needs two real installs.

// ===== SNAPSMACK EOF =====
