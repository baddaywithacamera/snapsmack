<?php
// EOF marker required (SNAPSMACK_EOF_HEADER): file must end with `// ===== SNAPSMACK EOF =====`.
declare(strict_types=1);

// SS-1335. A SITE'S PROTECTION RULES WERE FROZEN THE DAY IT WAS CREATED.
//
// protected_paths.json lists itself as protected, so the updater never
// overwrites it, so no later decision about what should or should not be
// protected could ever reach an existing install. core/constants.php was
// protected on 2026-05-20 (0.7.159) and unprotected on 2026-08-15 (0.7.530);
// sites running in that window still pin it today. Their constants.php has
// been frozen ever since while updater_set_version() rewrote its version
// lines, so they REPORT the new version and RUN the old code. 0.7.994D added
// snap_reading_age() to that file, smack-multisite.php called it, and the
// fleet board died on its first spoke row. Confirmed on the live hub.
//
// The updater now resolves:
//     effective = (install's protected UNION shipped protected) MINUS retired
//
// THESE TESTS BUILD A REAL STALE INSTALL AND A REAL ZIP. The whole fault was
// that reasoning about this looked right and the live behaviour was not, so
// nothing here asserts on source text: each case writes a manifest, writes a
// zip containing a shipped manifest, and asks the real functions.

$root = dirname(__DIR__);
$fails = 0;
function check(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) { $fails++; }
}

// The updater defines constants against the real install root, so it can only
// be loaded once and UPDATER_PROTECTED_PATHS_FILE points at the repo's own
// manifest. The functions under test are pure apart from that one constant and
// the zip, so the install side is exercised by pointing the constant at a
// temporary file via a child process below, and the shipped side directly.
//
// LOADING core/updater.php WRITES TO DISK. Line 122 calls
// updater_reconcile_user_ini() at require time, so merely including the file
// creates .user.ini in the install root. That is not this test's doing and it
// is not this test's to fix, but a test must not leave a file behind in the
// tree, so whether it existed first is recorded and the state restored at the
// end. It caught me out the first time this ran; it will catch out anything
// else that loads the updater to read one function.
$user_ini      = $root . '/.user.ini';
$user_ini_kept = is_file($user_ini);
require_once $root . '/core/updater.php';
register_shutdown_function(static function () use ($user_ini, $user_ini_kept): void {
    if (!$user_ini_kept && is_file($user_ini)) { @unlink($user_ini); }
});

$work = sys_get_temp_dir() . '/ss1335-' . bin2hex(random_bytes(6));
mkdir($work, 0777, true);

function make_zip(string $path, ?array $manifest, string $entry = 'protected_paths.json'): string {
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($manifest !== null) {
        $zip->addFromString($entry, json_encode($manifest, JSON_PRETTY_PRINT));
    }
    $zip->addFromString('core/constants.php', "<?php // shipped\n");
    $zip->close();
    return $path;
}

// ── the shipped manifest reader ─────────────────────────────────────────────

$good = make_zip($work . '/good.zip', ['protected' => ['core/db.php'], 'retired' => ['core/constants.php']]);
$m = updater_shipped_manifest($good);
check('the shipped manifest is read straight out of the zip',
      is_array($m) && $m['retired'] === ['core/constants.php']);

check('no zip path means no shipped manifest', updater_shipped_manifest('') === null);
check('a zip that does not exist is not a manifest',
      updater_shipped_manifest($work . '/missing.zip') === null);

$noentry = make_zip($work . '/noentry.zip', null);
check('a zip without the manifest entry returns null', updater_shipped_manifest($noentry) === null);

$broken = $work . '/broken.zip';
file_put_contents($broken, 'this is not a zip archive at all');
check('a corrupt archive returns null rather than throwing',
      updater_shipped_manifest($broken) === null);

$badjson = $work . '/badjson.zip';
$z = new ZipArchive(); $z->open($badjson, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('protected_paths.json', '{ not json');
$z->close();
check('unparseable JSON returns null', updater_shipped_manifest($badjson) === null);

$nested = make_zip($work . '/nested.zip',
                   ['protected' => [], 'retired' => ['core/constants.php']],
                   'snapsmack/protected_paths.json');
check('a package that nests everything under snapsmack/ is still read',
      is_array(updater_shipped_manifest($nested)));

// ── the resolution itself, through a child process with a stale install ─────
// UPDATER_PROTECTED_PATHS_FILE is a define, so each case runs in its own PHP
// process with the constant pointed at a fabricated manifest. This is the
// actual question: given THIS install file and THIS package, what is protected?

function resolve(string $work, ?array $installed, ?array $shipped): array {
    $dir = $work . '/case-' . bin2hex(random_bytes(4));
    mkdir($dir . '/core', 0777, true);
    if ($installed !== null) {
        file_put_contents($dir . '/protected_paths.json',
                          json_encode($installed, JSON_PRETTY_PRINT));
    }
    $zip = $shipped === null ? '' : make_zip($dir . '/u.zip', $shipped);
    $updater = dirname(__DIR__) . '/core/updater.php';
    $script = $dir . '/run.php';
    // The answer is written to a FILE, not echoed. Loading the updater prints
    // housekeeping notices (".user.ini PHP limits self-healed"), and decoding
    // stdout picked those up and reported thirteen failures that were not
    // failures. A test that reads a stream somebody else writes to is a test
    // that lies about which thing broke.
    $answer = $dir . '/answer.json';
    file_put_contents($script, '<?php' . "\n"
        . 'define("UPDATER_PROTECTED_PATHS_FILE", ' . var_export($dir . '/protected_paths.json', true) . ');' . "\n"
        . 'require ' . var_export($updater, true) . ';' . "\n"
        . 'file_put_contents(' . var_export($answer, true)
        . ', json_encode(updater_load_protected_paths(' . var_export($zip, true) . ')));' . "\n");
    $GLOBALS['last_child_output'] = (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    $decoded = is_file($answer)
        ? json_decode((string)file_get_contents($answer), true) : null;
    return is_array($decoded) ? $decoded : ['__RAW__' => 'the child wrote no answer'];
}

$STALE = ['protected' => ['core/db.php', 'protected_paths.json', 'core/constants.php',
                          'skins/', 'img_uploads/']];
$SHIPPED = ['protected' => ['core/db.php', 'protected_paths.json', 'skins/',
                            'img_uploads/', 'robots.txt'],
            'retired'   => ['core/constants.php']];

// THE FAULT, AND WHETHER IT IS FIXED.
$r = resolve($work, $STALE, $SHIPPED);
check('THE FAULT: a stale install no longer protects core/constants.php',
      !in_array('core/constants.php', $r, true));
check('  and core/db.php is still protected', in_array('core/db.php', $r, true));
check('  and protected_paths.json is still protected',
      in_array('protected_paths.json', $r, true));

// WITHOUT THE FIX IT WOULD STILL BE PROTECTED -- prove the premise.
$r = resolve($work, $STALE, null);
check('with no package the old behaviour is untouched: still protected',
      in_array('core/constants.php', $r, true));

// THE OWNER'S OWN ADDITIONS SURVIVE. This is why the install file stays
// protected and why the union exists.
$custom = $STALE;
$custom['protected'][] = 'wp-content/';
$custom['protected'][] = 'core/my-local-hack.php';
$r = resolve($work, $custom, $SHIPPED);
check('an owner-added directory is still protected', in_array('wp-content/', $r, true));
check('an owner-added file is still protected',
      in_array('core/my-local-hack.php', $r, true));
check('  while the retired path is still released',
      !in_array('core/constants.php', $r, true));

// A PATH THE PROJECT PROTECTS REACHES AN INSTALL THAT NEVER HAD IT.
check('robots.txt, protected by the package and absent from the stale list, is protected',
      in_array('robots.txt', resolve($work, $STALE, $SHIPPED), true));

// THINGS THAT MUST NEVER BE RETIRABLE, whatever a manifest claims.
$hostile = ['protected' => [], 'retired' => ['core/db.php', 'protected_paths.json',
                                             'core/constants.php']];
$r = resolve($work, $STALE, $hostile);
check('a package cannot retire core/db.php', in_array('core/db.php', $r, true));
check('a package cannot retire protected_paths.json',
      in_array('protected_paths.json', $r, true));
check('  but a legitimate retirement in the same list still applies',
      !in_array('core/constants.php', $r, true));

// FAIL SAFE MEANS KEEPING PROTECTION.
foreach ([['no retired key', ['protected' => ['core/db.php']]],
          ['an empty retired list', ['protected' => [], 'retired' => []]],
          ['a retired list of the wrong type', ['protected' => [], 'retired' => 'everything']],
          ['non-string entries', ['protected' => [], 'retired' => [['nested'], 42, null]]]] as [$label, $shipped]) {
    $r = resolve($work, $STALE, $shipped);
    check("a package with {$label} unprotects nothing",
          in_array('core/constants.php', $r, true));
}

// A HOSTILE MANIFEST MUST NOT MAKE THE UPDATER NOISY EITHER. Dropping the
// is_string filter does not change which paths come back -- array_diff
// stringifies -- so a result-only assertion cannot see it. What it DOES do is
// make PHP emit "Array to string conversion" from inside a live update. A
// mutation run found this gap; the warning is the harm, so the warning is what
// is asserted.
resolve($work, $STALE, ['protected' => [],
                        'retired'   => [['nested'], 42, null, true]]);
$noise = (string)($GLOBALS['last_child_output'] ?? '');
check('a manifest full of junk produces no PHP warning from the updater',
      stripos($noise, 'Array to string conversion') === false
      && stripos($noise, 'Fatal error') === false
      && stripos($noise, 'Uncaught') === false);

// The install's own file being absent or unreadable must not unprotect the
// fleet either -- it falls back to the shipped defaults.
$r = resolve($work, null, $SHIPPED);
check('a missing install manifest still protects core/db.php',
      in_array('core/db.php', $r, true));

// skins/ is protected unconditionally in updater_is_protected, and must stay so
// even if a package tried to retire it.
$r = resolve($work, $STALE, ['protected' => [], 'retired' => ['skins/']]);
check('skins stay protected by updater_is_protected regardless of any list',
      updater_is_protected('skins/glide/style.css', $r));

// ── the repository's own manifest is the one that ships ─────────────────────
$shipped_here = json_decode((string)file_get_contents($root . '/protected_paths.json'), true);
check('the repo manifest declares a retired list', isset($shipped_here['retired']));
check('  and it retires core/constants.php',
      in_array('core/constants.php', (array)$shipped_here['retired'], true));
check('  and retires NOTHING else, so the widened surface is one path',
      count((array)$shipped_here['retired']) === 1);
check('  while core/db.php remains protected',
      in_array('core/db.php', (array)$shipped_here['protected'], true));
check('  and protected_paths.json remains protected, so owner additions survive',
      in_array('protected_paths.json', (array)$shipped_here['protected'], true));

// ── both extraction routes must agree ───────────────────────────────────────
// If only one of them reconciles, which route an install happens to take
// decides whether a retired path is honoured.
$updater_src = (string) file_get_contents($root . '/core/updater.php');
check('the chunked extractor passes the package to the resolver',
      substr_count($updater_src, 'updater_load_protected_paths($zip_path)') === 2);

if ($fails > 0) {
    echo "\n{$fails} FAILURE(S)\n";
    exit(1);
}
echo "updater manifest reconciliation: ok\n";

// ===== SNAPSMACK EOF =====
