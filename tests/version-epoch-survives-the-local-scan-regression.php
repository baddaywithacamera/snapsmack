<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * An INSTALLED skin's epoch must survive the read off disk.
 *
 * WHY THIS EXISTS AND WHY IT IS NOT THE SIBLING TEST.
 * tests/version-epoch-reaches-the-fleet-regression.php proves that
 * skin_registry_compare() reads an epoch. It proves it by handing the function
 * a local array built by hand, WITH version_epoch supplied. Production never
 * builds that array by hand: it comes from skin_registry_local(), which scans
 * the skins directory and loads each manifest. On 623ea0ef the epoch did not
 * survive that scan, so every check in the sibling test passed while the
 * product re-offered an update a site had already installed.
 *
 * THE FAULT, measured on 623ea0ef before the fix:
 *   - snapsmack_normalize_skin_manifest() held an allowlist of known manifest
 *     keys and version_epoch was not in it, so the loader discarded the field
 *     with only an error_log -- the same fault, in the same allowlist, that
 *     the min_images comment there already describes;
 *   - skin_registry_local() then built its own allowlisted array, which also
 *     omitted the field.
 * Either one alone is enough to lose it. With it lost, an install holding the
 * re-baselined IMPACT PRINTER 0.7.31 compared as plain 0.7.31 against the
 * registry's 1:0.7.31, so the gallery offered UPDATE TO v0.7.31 to a site
 * already on v0.7.31, after every registry fetch, for ever.
 *
 * SO THIS TEST ENTERS THROUGH THE SCAN. It writes two fixture skins to disk,
 * calls skin_registry_local(), and compares what that returns. It does not
 * construct a local entry anywhere.
 *
 * WHY THE FIXTURES GO IN skins/ AND NOT A TEMP DIRECTORY. core/skin-manifest.php
 * resolves a manifest from the repository root, not from SKINS_DIR, so a skin
 * outside skins/ has no manifest to load and the scan would read an empty one.
 * The directories are therefore created in the tree and removed again, and the
 * removal is CHECKED rather than assumed, because a failure here would leave
 * two fake skins in the owner's checkout.
 *
 * Run: php tests/version-epoch-survives-the-local-scan-regression.php
 */

$root = dirname(__DIR__);
require_once $root . '/core/constants.php';
require_once $root . '/core/skin-manifest.php';
if (!defined('SKINS_DIR')) { define('SKINS_DIR', $root . '/skins'); }
require_once $root . '/core/skin-registry.php';

$fails = [];
$checks = 0;
$check = static function (string $what, bool $ok) use (&$fails, &$checks): void {
    $checks++;
    if (!$ok) { $fails[] = $what; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

// A skin already on the re-baselined package, and one still on the old
// numbering. Written as manifests because that is what an install has.
$rebaselined = 'zz-epoch-rebaselined-fixture';
$legacy      = 'zz-epoch-legacy-fixture';

$fixtures = [
    $rebaselined => [
        'schema_version' => 2,
        'name'           => 'EPOCH FIXTURE REBASELINED',
        'version'        => '0.7.31',
        'version_epoch'  => 1,
        'status'         => 'stable',
    ],
    $legacy => [
        'schema_version' => 2,
        'name'           => 'EPOCH FIXTURE LEGACY',
        'version'        => '2.1.30',
        'status'         => 'stable',
    ],
];

$made = [];
try {
    foreach ($fixtures as $slug => $manifest) {
        $dir = SKINS_DIR . '/' . $slug;
        if (file_exists($dir)) {
            // Never write over something that is already there, whatever it is.
            $fails[] = "refusing to run: {$dir} already exists";
            echo "FAIL refusing to run: {$dir} already exists\n";
            $checks++;
            break;
        }
        if (!mkdir($dir, 0775, true)) {
            $fails[] = "could not create the fixture directory {$dir}";
            echo "FAIL could not create the fixture directory {$dir}\n";
            $checks++;
            break;
        }
        $made[] = $dir;
        file_put_contents(
            $dir . '/manifest.json',
            (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    if (count($made) === count($fixtures)) {
        // ---------------------------------------------------------------
        // The scan itself. Nothing below constructs a local entry.
        // ---------------------------------------------------------------
        $local = skin_registry_local();

        $check('the scan found the re-baselined fixture at all',
            isset($local[$rebaselined]));
        $check('the scan found the legacy fixture at all',
            isset($local[$legacy]));

        $check('THE SCAN KEEPS THE INSTALLED EPOCH (the fault: it was dropped)',
            ($local[$rebaselined]['version_epoch'] ?? null) === 1);
        $check('a skin with no declared epoch scans as epoch 0, not missing',
            ($local[$legacy]['version_epoch'] ?? null) === 0);

        // The comparable string is what every caller actually compares. Both
        // readers of this array -- skin_registry_compare() below and the skin
        // notifications in core/updater.php -- call this same helper on it.
        $check('the scanned entry compares as 1:0.7.31',
            snap_version_with_epoch($local[$rebaselined]) === '1:0.7.31');
        $check('the legacy entry still compares as a bare 2.1.30',
            snap_version_with_epoch($local[$legacy]) === '2.1.30');

        // ---------------------------------------------------------------
        // The behaviour Sean would see, driven off the scanned array.
        // ---------------------------------------------------------------
        $same = ['skins' => [
            $rebaselined => ['name' => 'EPOCH FIXTURE REBASELINED', 'version' => '0.7.31',
                             'version_epoch' => 1, 'status' => 'stable'],
            $legacy      => ['name' => 'EPOCH FIXTURE LEGACY', 'version' => '0.7.31',
                             'version_epoch' => 1, 'status' => 'stable'],
        ]];
        $compared = skin_registry_compare($same, $local);

        $check('AN INSTALLED RE-BASELINED SKIN IS NOT OFFERED TO ITSELF (the blocker)',
            ($compared[$rebaselined]['update_available'] ?? null) === false);
        $check('a site still on 2.1.30 is still offered the re-baselined 0.7.31',
            ($compared[$legacy]['update_available'] ?? null) === true);

        // And the next ordinary bump after the re-baseline must still arrive,
        // or the fix above would trade one silent stall for another.
        $next = ['skins' => [
            $rebaselined => ['version' => '0.7.32', 'version_epoch' => 1, 'status' => 'stable'],
        ]];
        $check('the next bump after the re-baseline is still offered',
            (skin_registry_compare($next, $local)[$rebaselined]['update_available'] ?? null) === true);

        // A LOWER epoch must never win, which is the only thing that makes the
        // epoch a numbering rather than a flag.
        $older = ['skins' => [
            $rebaselined => ['version' => '9.9.9', 'status' => 'stable'],
        ]];
        $check('a bare 9.9.9 does not beat an installed 1:0.7.31',
            (skin_registry_compare($older, $local)[$rebaselined]['update_available'] ?? null) === false);

        // ---------------------------------------------------------------
        // The loader, driven directly, because the scan delegates to it and a
        // failure there is the one that reaches every other manifest reader.
        // ---------------------------------------------------------------
        $loaded = snapsmack_load_manifest(SKINS_DIR . '/' . $rebaselined . '/manifest.json');
        $check('the manifest loader keeps version_epoch',
            ($loaded['version_epoch'] ?? null) === 1);
        $check('the manifest loader still keeps the version beside it',
            ($loaded['version'] ?? null) === '0.7.31');

        // An epoch that is not an integer is a manifest mistake. Dropping it
        // keeps the pre-epoch behaviour; inventing one would decide a
        // numbering the author did not write.
        $bad = snapsmack_normalize_skin_manifest([
            'schema_version' => 2, 'version' => '0.7.31', 'version_epoch' => '1',
        ], 'bad-epoch');
        $check('a string epoch is refused rather than guessed at',
            !array_key_exists('version_epoch', $bad));
        $check('a negative epoch is refused',
            !array_key_exists('version_epoch', snapsmack_normalize_skin_manifest([
                'schema_version' => 2, 'version' => '0.7.31', 'version_epoch' => -1,
            ], 'bad-epoch')));
    }
} finally {
    $removed = [];
    foreach ($made as $dir) {
        @unlink($dir . '/manifest.json');
        @rmdir($dir);
        $removed[$dir] = !file_exists($dir);
    }
    foreach ($removed as $dir => $gone) {
        $checks++;
        if (!$gone) { $fails[] = "the fixture skin {$dir} was left in the tree"; }
        echo ($gone ? 'PASS ' : 'FAIL ') . "fixture removed: {$dir}\n";
    }
}

echo "\n";
if ($fails) {
    echo 'FAILED (' . count($fails) . " of {$checks}):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL PASS: an installed skin's epoch survives the real local scan ({$checks} checks)\n";

// NOT CHECKED HERE: core/updater.php's skin notification path, which reads the
// same skin_registry_local() array through the same snap_version_with_epoch()
// and is therefore fixed by the same two lines, but needs a registry fetch to
// drive end to end; and the hub's own fleet comparison in smack-multisite.php,
// which compares plain version strings and has no epoch to read because the
// heartbeat sends {slug: version}. That last one is a separate finding and is
// NOT fixed by this commit.

// ===== SNAPSMACK EOF =====
