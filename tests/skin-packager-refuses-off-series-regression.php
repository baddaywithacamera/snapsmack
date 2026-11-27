<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * The Skin Packager must refuse a version at 0.8 or above, and must refuse a
 * re-baselined skin that does not carry its epoch.
 *
 * WHY HERE AND NOT IN THE RULE TEST. tests/version-series-gate-regression.php
 * proves the rules; this proves the PACKAGER APPLIES THEM, by running the real
 * command. A rule nothing calls is the fault class that shipped a sharing
 * setting no control could set, and twice tonight my own tests have exercised
 * a tool's functions rather than the tool.
 *
 * It writes its packages into a temporary directory, and the one skin it has
 * to invent is created under skins/ and removed again with the removal
 * CHECKED, because a test must not leave litter in Sean's checkout.
 */

$root = dirname(__DIR__);
require_once $root . '/core/constants.php';
require_once $root . '/core/version-series.php';

$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) { $fails[] = $what; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

$out = sys_get_temp_dir() . '/skin-packager-test-' . getmypid();
@mkdir($out, 0777, true);
$pack = static function (string $slug) use ($root, $out): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg($root . '/tools/_build/package-skin.php') . ' '
        . escapeshellarg($slug) . ' --output ' . escapeshellarg($out) . ' 2>&1';
    $lines = [];
    $code = 1;
    exec($cmd, $lines, $code);
    return [$code, implode("\n", $lines)];
};

// ---------------------------------------------------------------------------
// A real off-series skin must be refused, by name, with the version to use.
// ---------------------------------------------------------------------------
$manifest = json_decode((string)file_get_contents($root . '/skins/impact-printer/manifest.json'), true);
$impactVersion = (string)($manifest['version'] ?? '');
$check('IMPACT PRINTER is still off-series in the tree (' . $impactVersion . ')',
    snap_version_series_problem($impactVersion) !== '');

[$code, $output] = $pack('impact-printer');
$check('the packager REFUSES an off-series skin', str_contains($output, 'BLOCK impact-printer'));
$check('it packaged nothing', str_contains($output, 'Packaged 0 skin(s)'));
$check('it says the version is at 0.8 or above', str_contains($output, '0.8 or above'));
$check('it names Sean as the only person who changes that',
    stripos($output, 'Sean is the only person') !== false);
$check('it names the exact version to set instead',
    str_contains($output, '"version": "' . snap_version_rebaseline($impactVersion) . '"'));
$check('it names the epoch to set', str_contains($output, '"version_epoch": 1'));
$check('no package was written for it',
    glob($out . '/impact-printer-*.zip') === []);

// ---------------------------------------------------------------------------
// A skin already under 0.8 still packages, exactly as it did before.
// ---------------------------------------------------------------------------
[$code, $output] = $pack('tilez');
$check('an in-series skin still packages', str_contains($output, 'PACK  tilez'));
$check('and its zip is written', glob($out . '/tilez-*.zip') !== []);
$check('an in-series skin with no epoch does not print one',
    !str_contains($output, 'epoch'));

// ---------------------------------------------------------------------------
// The re-baseline rules. These need a manifest that declares rebaselined_from
// and no real skin has one, so TILEZ's manifest is edited in place and PUT
// BACK, with the restoration checked. A skin invented from scratch cannot be
// used: the skin security gate runs first and refuses it for missing
// structure, so the version checks are never reached. Found by trying it.
// ---------------------------------------------------------------------------
$manifestPath = $root . '/skins/tilez/manifest.json';
$originalBytes = (string)file_get_contents($manifestPath);
$original = json_decode($originalBytes, true);
$check('TILEZ manifest read, and it declares no re-baseline today',
    is_array($original) && !isset($original['rebaselined_from']));

$withFields = static function (array $extra) use ($manifestPath, $original): void {
    file_put_contents($manifestPath,
        json_encode(array_merge($original, $extra), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "
");
};

try {
    // Re-baselined, epoch missing: refused, because without the epoch no site
    // holding 2.1.30 would ever take 0.7.31.
    $withFields(['version' => '0.7.31', 'rebaselined_from' => '2.1.30']);
    [$code, $output] = $pack('tilez');
    $check('a re-baselined skin with NO epoch is refused', str_contains($output, 'BLOCK tilez'));
    $check('the refusal explains that no site holding the old number would take it',
        str_contains($output, 'will ever take it'));

    // Re-baselined properly: accepted, epoch printed, epoch in the package.
    $withFields(['version' => '0.7.31', 'rebaselined_from' => '2.1.30', 'version_epoch' => 1]);
    [$code, $output] = $pack('tilez');
    $check('a re-baselined skin WITH its epoch packages', str_contains($output, 'PACK  tilez'));
    $check('the epoch is printed so nobody has to guess', str_contains($output, 'epoch 1'));

    $zips = glob($out . '/tilez-0.7.31.zip') ?: [];
    $check('the re-baselined package was written', $zips !== []);
    if ($zips !== []) {
        $zip = new ZipArchive();
        if ($zip->open((string)$zips[0]) === true) {
            $inside = (string)$zip->getFromName('smackback-manifest.json');
            $zip->close();
            $decoded = json_decode($inside, true);
            $check('THE PACKAGE ITSELF RECORDS THE EPOCH',
                is_array($decoded) && ($decoded['package_epoch'] ?? null) === 1);
            $check('and the version it was built for',
                is_array($decoded) && ($decoded['package_version'] ?? null) === '0.7.31');
        } else {
            $check('the re-baselined package opens', false);
        }
    }

    // Muse's note (a) on f94cbb26: a rebaselined_from this code cannot read,
    // with the epoch present, used to pass — the wrong-number check was
    // skipped because the expected version was null. Fail OPEN, which is the
    // wrong direction for a claim about where a version came from.
    $withFields(['version' => '0.7.31', 'rebaselined_from' => 'nonsense', 'version_epoch' => 1]);
    [$code, $output] = $pack('tilez');
    $check('an unreadable rebaselined_from is REFUSED, not waved through',
        str_contains($output, 'BLOCK tilez'));
    $check('and the refusal quotes the value it could not read',
        str_contains($output, "'nonsense'"));

    // Re-baselined to the wrong number: refused, and told the right one.
    $withFields(['version' => '0.7.99', 'rebaselined_from' => '2.1.30', 'version_epoch' => 1]);
    [$code, $output] = $pack('tilez');
    $check('a re-baselined skin with the WRONG number is refused',
        str_contains($output, 'BLOCK tilez'));
    $check('and it is told the number it should carry', str_contains($output, '0.7.31'));
} finally {
    // Put TILEZ back exactly as it was. An uncaught failure here edits a real
    // skin's manifest in Sean's checkout, which is far worse than a failing
    // test, so it is restored byte for byte and then checked.
    file_put_contents($manifestPath, $originalBytes);
}
$check('TILEZ manifest restored byte for byte',
    (string)file_get_contents($manifestPath) === $originalBytes);

foreach (glob($out . '/*') ?: [] as $leftover) { @unlink($leftover); }
@rmdir($out);
$check('the packages were written to a temporary directory, not the tree',
    glob($root . '/*.zip') === []);

// ---------------------------------------------------------------------------
// WHERE THE PACKAGES GO. PM ruling, room 8472: outside the checkout, never the
// project root, and both spellings of --output must work.
//
// This exists because the old default WAS the project root and --output=DIR was
// SILENTLY IGNORED, so I believed I had sent a zip elsewhere and it landed in
// the checkout instead. Both halves of that are checked here.
// ---------------------------------------------------------------------------
$raw = static function (array $args) use ($root): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg($root . '/tools/_build/package-skin.php');
    foreach ($args as $arg) { $cmd .= ' ' . escapeshellarg($arg); }
    $lines = [];
    $code = 1;
    exec($cmd . ' 2>&1', $lines, $code);
    return [$code, implode("\n", $lines)];
};

$eqDir = $out . '-equals';
@mkdir($eqDir, 0777, true);
[$code, $output] = $raw(['tilez', '--output=' . $eqDir]);
$check('--output=DIR is honoured, not silently ignored',
    $code === 0 && glob($eqDir . '/tilez-*.zip') !== []);
foreach (glob($eqDir . '/*') ?: [] as $leftoverZip) { @unlink($leftoverZip); }
@rmdir($eqDir);

[$code, $output] = $raw(['tilez', '--output=' . $root]);
$check('the CHECKOUT is refused as an output directory', $code !== 0);
$check('and the refusal says packages belong outside the source tree',
    stripos($output, 'outside the source tree') !== false);
$check('and it names the environment variable that sets the default',
    stripos($output, 'SNAPSMACK_BUILD_DIR') !== false);

[$code, $output] = $raw(['tilez', '--output=.']);
$check('the checkout is refused however it is spelled', $code !== 0);

[$code, $output] = $raw(['tilez', '--oputput=' . $out]);
$check('AN UNKNOWN OPTION IS AN ERROR, not silence', $code !== 0);
$check('and it names the option it did not understand', str_contains($output, '--oputput'));

[$code, $output] = $raw(['tilez', '--output']);
$check('--output with no directory is an error', $code !== 0);
[$code, $output] = $raw(['tilez', '--output=']);
$check('--output= with nothing after it is an error', $code !== 0);

// The default must resolve OUTSIDE the repository. Run from a git worktree, the
// first version resolved to .claude/worktrees/snapsmack-builds -- inside the
// repository's own internals, which is the scatter this change exists to stop.
[$code, $output] = $raw(['tilez', '--output=' . $root]);
if (preg_match('~default is (.+?)\.?$~m', $output, $m) === 1) {
    $defaultPath = trim($m[1], " .\t\r\n");
    $normalised = str_replace('\\', '/', $defaultPath);
    $rootNorm = str_replace('\\', '/', (string)realpath($root));
    $check('the default output directory is OUTSIDE the checkout: ' . $defaultPath,
        $normalised !== '' && strncmp($normalised, $rootNorm, strlen($rootNorm)) !== 0);
    $check('and not inside the repository internals either',
        !str_contains($normalised, '/.claude/') && !str_contains($normalised, '/.git/'));
} else {
    $check('the refusal states the default output directory', false);
}


// THE DEFAULT, RUN FOR REAL. The checks above read the default out of the
// refusal message, which is a variable and not a behaviour -- a mutant that
// put the default back to the project root SURVIVED them, and its zip was only
// caught by the cleanliness check on the NEXT run. So this runs the packager
// with NO --output at all and looks at the tree immediately.
$zipsBefore = glob($root . '/*.zip') ?: [];
[$code, $output] = $raw(['tilez']);
$zipsAfter = glob($root . '/*.zip') ?: [];
$newInTree = array_values(array_diff($zipsAfter, $zipsBefore));
$check('RUNNING WITH NO --output PUTS NOTHING IN THE CHECKOUT: '
    . ($newInTree ? implode(', ', array_map('basename', $newInTree)) : 'nothing'),
    $newInTree === []);
$check('and it still packaged the skin somewhere', $code === 0);
// Remove only what this run created, wherever it went, and nothing else: that
// directory is a real build location with other people's artifacts in it.
if (preg_match('~Output directory: (.+)$~m', $output, $m) === 1) {
    $where = trim($m[1]);
    $check('it reports where it wrote', $where !== '');
    $made = $where . DIRECTORY_SEPARATOR . 'tilez-0.2.79.zip';
    if (is_file($made)) { @unlink($made); }
    $check('the package this run created was cleaned up', !is_file($made));
} else {
    $check('it reports where it wrote', false);
}
foreach ($newInTree as $stray) { @unlink($stray); }


echo "\n";
if ($fails) {
    echo "FAILED (" . count($fails) . "):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL PASS\n";

// NOT CHECKED: a real site taking a re-baselined package over the wire. That
// needs two installs. Also worth knowing and not a defect: the packager
// defaults its output to the PROJECT ROOT, so running it without --output
// drops zips into the checkout.

// ===== SNAPSMACK EOF =====
