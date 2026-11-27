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
 * HOW TO RECONCILE THE NUMBERS, WHICH IS MORE USE THAN THE NUMBERS. (Muse had
 * to do it by hand, twice.) The first version of this paragraph gave three
 * absolute figures -- 70 sites, ledger 71, 66 executed -- and they were out of
 * date within the hour: it is 80, 81 and 75 as I write this, and it will be
 * something else next time somebody adds a check. A comment that states a
 * count is a comment that will lie, and Muse caught this one lying.
 *
 * So the RELATIONSHIPS, which do not go stale:
 *
 *   the strength ledger  =  the assertion sites in this file + 1
 *                           (the one non-zero exit at the end)
 *   a clean run executes =  the assertion sites - the failure-only branches
 *
 * A failure-only branch is an assertion whose condition is the literal false,
 * in an else arm: an unopenable package, and the refusals that did not state
 * their default output directory. They fire only when an earlier step has
 * already gone wrong, so a passing run never reaches them. If either
 * relationship stops holding, something changed that is worth looking at.
 *
 * AND THAT SENTENCE IS WRITTEN OUT IN WORDS ON PURPOSE. The first version
 * quoted the literal text somebody would grep for, which added an occurrence
 * of it to this file and broke the arithmetic it was explaining -- the count
 * went from five to six and the run relationship stopped holding. Seventh time
 * today that something of mine matched the thing it was describing. Prose that
 * explains a pattern must not contain the pattern.
 *
 * AND THIS PARAGRAPH IS WRITTEN WITHOUT THE LITERAL TOKENS ON PURPOSE. The
 * strength ledger reads test files as TEXT and counts assertion idioms, so the
 * first version of this note -- which spelled out the call and the exit
 * verbatim -- was itself counted, and the ledger went to 73. Documenting a
 * count changed the count. Describe the idioms here; never spell them.
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
// The re-baseline rules, on a COPY of a real skin.
//
// The first version of this edited TILEZ's own manifest in place and restored
// it. Muse warned that a kill mid-test would leave it dirty, and that is
// exactly what happened: a mutation run of mine died between the edit and the
// restore, the next run compared against the dirty file, and four checks
// failed for the wrong reason. Then consecutive runs started failing outright
// with "Permission denied" writing that manifest -- a lingering lock from the
// previous run -- so the suite had become FLAKY, which is worse than the gap
// it was covering.
//
// A skin invented from nothing cannot be used: the skin security gate runs
// first and refuses it for missing structure. A COPY of a real skin has the
// structure and passes, so the fixture is a copy with its own slug. Nothing
// tracked is touched.
// ---------------------------------------------------------------------------
$fixtureSlug = 'zz-packager-fixture';
$fixtureDir = $root . '/skins/' . $fixtureSlug;
$check('the fixture skin does not already exist', !is_dir($fixtureDir));

$copyInto = static function (string $from, string $to): void {
    @mkdir($to, 0777, true);
    foreach (glob($from . '/*') ?: [] as $file) {
        if (is_file($file)) { @copy($file, $to . '/' . basename($file)); }
    }
};
$removeFixture = static function (string $dir): void {
    foreach (glob($dir . '/*') ?: [] as $file) { @unlink($file); }
    @rmdir($dir);
};

$copyInto($root . '/skins/tilez', $fixtureDir);
$check('the fixture carries the files a real skin has',
    is_file($fixtureDir . '/manifest.json') && is_file($fixtureDir . '/style.css')
    && is_file($fixtureDir . '/layout.php'));

$fixtureManifest = static function (array $extra) use ($fixtureDir, $fixtureSlug): void {
    $base = json_decode((string)file_get_contents($fixtureDir . '/manifest.json'), true) ?: [];
    $base['name'] = 'Packager fixture';
    $base['status'] = 'development';
    file_put_contents($fixtureDir . '/manifest.json',
        json_encode(array_merge($base, $extra), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
};

try {
    // Re-baselined, epoch missing: refused, because without the epoch no site
    // holding 2.1.30 would ever take 0.7.31.
    $fixtureManifest(['version' => '0.7.31', 'rebaselined_from' => '2.1.30']);
    [$code, $output] = $pack($fixtureSlug);
    $check('a re-baselined skin with NO epoch is refused',
        str_contains($output, 'BLOCK ' . $fixtureSlug));
    $check('the refusal explains that no site holding the old number would take it',
        str_contains($output, 'will ever take it'));

    // Re-baselined properly: accepted, epoch printed, epoch in the package.
    $fixtureManifest(['version' => '0.7.31', 'rebaselined_from' => '2.1.30', 'version_epoch' => 1]);
    [$code, $output] = $pack($fixtureSlug);
    $check('a re-baselined skin WITH its epoch packages',
        str_contains($output, 'PACK  ' . $fixtureSlug));
    $check('the epoch is printed so nobody has to guess', str_contains($output, 'epoch 1'));

    $zips = glob($out . '/' . $fixtureSlug . '-0.7.31.zip') ?: [];
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

    // An unreadable rebaselined_from must fail CLOSED, with the right reason.
    foreach (glob($out . '/' . $fixtureSlug . '-*.zip') ?: [] as $earlier) { @unlink($earlier); }
    $fixtureManifest(['version' => '0.7.31', 'rebaselined_from' => 'nonsense', 'version_epoch' => 1]);
    [$code, $output] = $pack($fixtureSlug);
    $check('an unreadable rebaselined_from is REFUSED, not waved through',
        str_contains($output, 'BLOCK ' . $fixtureSlug));
    $check('and the refusal quotes the value it could not read',
        str_contains($output, "'nonsense'"));
    $check('an unreadable value is reported as unreadable',
        str_contains($output, 'cannot be read as a version'));
    $check('and NOT as already under 0.8', !str_contains($output, 'already under 0.8'));
    $check('it exits non-zero', $code !== 0);
    $check('and writes no package', glob($out . '/' . $fixtureSlug . '-*.zip') === []);

    // An IN-SERIES rebaselined_from reads fine; it simply needed no move. Two
    // different wrongs, two different messages.
    $fixtureManifest(['version' => '0.7.31', 'rebaselined_from' => '0.7.31', 'version_epoch' => 1]);
    [$code, $output] = $pack($fixtureSlug);
    $check('an IN-SERIES rebaselined_from is refused for the right reason',
        str_contains($output, 'already under 0.8'));
    $check('and not reported as unreadable',
        !str_contains($output, 'cannot be read as a version'));

    // Re-baselined to the wrong number: refused, and told the right one.
    $fixtureManifest(['version' => '0.7.99', 'rebaselined_from' => '2.1.30', 'version_epoch' => 1]);
    [$code, $output] = $pack($fixtureSlug);
    $check('a re-baselined skin with the WRONG number is refused',
        str_contains($output, 'BLOCK ' . $fixtureSlug));
    $check('and it is told the number it should carry', str_contains($output, '0.7.31'));
} finally {
    $removeFixture($fixtureDir);
}
$check('the fixture skin was removed from skins/', !is_dir($fixtureDir));
$check('no tracked skin was modified',
    trim((string)@shell_exec('git -C ' . escapeshellarg($root)
        . ' status --porcelain -- skins/ 2>&1')) === '');
$check('the build still has exactly 30 skins',
    count(glob($root . '/skins/*/manifest.json') ?: []) === 30);

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

// A SUBDIRECTORY of the checkout is still the checkout. The first version
// compared for equality only, so --output=./packages wrote into the source
// tree; mutation showed my tests did not cover it even after I fixed the code.
// An EXISTING subdirectory, deliberately: a non-existent one is caught by the
// walk-up to the nearest existing ancestor, which is the checkout root, so it
// would pass even with the containment check removed. Mutation showed that --
// the first version of this case used a directory that does not exist and the
// mutant survived it. tests/ exists and is unambiguously inside the checkout.
[$code, $output] = $raw(['tilez', '--output=' . $root . '/tests']);
$check('an EXISTING subdirectory of the checkout is refused', $code !== 0);
$check('and no package was written into it', glob($root . '/tests/tilez-*.zip') === []);

[$code, $output] = $raw(['tilez', '--output=' . $root . '/packages']);
$check('a non-existent subdirectory of the checkout is refused too', $code !== 0);
$check('and nothing was created there', !is_dir($root . '/packages'));

// A path that does NOT EXIST YET inside the checkout. realpath() returns false
// for it, which skipped the check altogether, so the packager would have
// created the directory and written into it.
[$code, $output] = $raw(['tilez', '--output=' . $root . '/nope/deeper']);
$check('a not-yet-existing path inside the checkout is refused', $code !== 0);
$check('and it was not created on the way to refusing it', !is_dir($root . '/nope'));

// And the other direction: a directory outside the checkout that does not
// exist yet must still be accepted and created.
$fresh = $out . '-fresh/deeper';
[$code, $output] = $raw(['tilez', '--output=' . $fresh]);
$check('a new directory OUTSIDE the checkout is accepted and created',
    $code === 0 && glob($fresh . '/tilez-*.zip') !== []);
foreach (glob($fresh . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($fresh);
@rmdir(dirname($fresh));

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
    // The no-git run below must MATCH this. It said "must NOT match" until
    // Muse read it against the check that uses it: that was written when I
    // expected the two routes to differ, and the fix made them agree -- which
    // is the whole point of the fallback. The comment survived the change it
    // describes and said the opposite of the assertion two hundred lines down.
    $withGitDefault = $normalised;
} else {
    $check('the refusal states the default output directory', false);
    $withGitDefault = '';
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
    // THE NAME IS DERIVED, not typed. Muse's condition: hard-coding
    // tilez-0.2.79.zip means a version bump leaves the real zip behind while
    // this check passes vacuously, because the file it looks for no longer
    // exists. The version comes from the manifest, which is what the packager
    // itself used to name the file.
    $tilezManifest = json_decode(
        (string)file_get_contents($root . '/skins/tilez/manifest.json'), true);
    $tilezVersion = (string)($tilezManifest['version'] ?? '');
    $check('the TILEZ version was read from its manifest', $tilezVersion !== '');
    $made = $where . DIRECTORY_SEPARATOR . 'tilez-' . $tilezVersion . '.zip';
    $check('the package this run created is where the packager said it was',
        is_file($made));
    if (is_file($made)) { @unlink($made); }
    $check('and it was cleaned up', !is_file($made));
} else {
    $check('it reports where it wrote', false);
}
foreach ($newInTree as $stray) { @unlink($stray); }


// ---------------------------------------------------------------------------
// WITHOUT GIT. Muse's remaining condition on 78fbf0f2, and she was exactly
// right: every default-output check above runs inside a checkout where git
// answers, so $main_root is set and the fallback climb NEVER EXECUTES.
// Removing the climb left all 55 checks green, and I had claimed that gap was
// written when it was not.
//
// A machine without git is a real condition -- a site owner who unpacked a zip
// has no repository at all -- so the packager takes the git binary from
// SNAPSMACK_GIT_BIN, and pointing that at nothing exercises the real fallback
// rather than a stub. Measured with the climb removed: the default becomes
// .claude/worktrees/<name>-builds, inside the repository's own internals.
// ---------------------------------------------------------------------------
// THE FIRST VERSION OF THIS ONLY WORKED ON WINDOWS. (Muse, on 6e9133cd.) It
// set the variable with cmd.exe syntax -- set "VAR=..." && php ... -- and on a
// POSIX shell `set "VAR=..."` assigns POSITIONAL PARAMETERS and exports
// nothing, so php ran with git available, $main_root was set, the fallback
// climb never executed and all four checks below passed WITHOUT TESTING
// ANYTHING. A test that proves nothing on the machine CI runs on is worse than
// no test, and it is the same vacuous-pass fault this whole section was
// written to close.
//
// putenv() changes this process's environment and the child inherits it, with
// no shell involved, so it behaves the same on both platforms. And the result
// is checked against the with-git default below rather than taken on trust: if
// the knob ever stops reaching the child, the two defaults become equal and
// that is caught instead of passing.
// ONE MECHANISM, USED BY BOTH THE PROBE AND THE PACKAGER RUNS. The first
// version had the probe and the packager run each set the variable their own
// way, and mutation proof showed why that is useless: breaking the packager
// run's mechanism left the probe passing, so the probe proved only that
// putenv works in general -- not that THIS run used it. Both go through
// $noGitRun now, so a mutation to the mechanism breaks the probe too and the
// probe is a real control rather than a decoration. Same lesson as the scan
// that kept its own copy of an exemption rule.
$noGitRun = static function (string $cmd) use ($root): array {
    $previous = getenv('SNAPSMACK_GIT_BIN');
    putenv('SNAPSMACK_GIT_BIN=' . $root . '/no-such-git-binary');
    try {
        $lines = [];
        $code  = 1;
        exec($cmd . ' 2>&1', $lines, $code);
        return [$code, implode("\n", $lines)];
    } finally {
        // Restore, so nothing after this section runs with a broken git path.
        if ($previous === false) { putenv('SNAPSMACK_GIT_BIN'); }
        else { putenv('SNAPSMACK_GIT_BIN=' . $previous); }
    }
};

$withoutGit = static function (array $args) use ($root, $noGitRun): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg($root . '/tools/_build/package-skin.php');
    foreach ($args as $arg) { $cmd .= ' ' . escapeshellarg($arg); }
    return $noGitRun($cmd);
};

// THE POSITIVE CONTROL, and it is not the one I reached for first. The obvious
// check -- that the no-git default DIFFERS from the with-git one -- is wrong:
// measured, both resolve to the same directory, because the fallback climb
// lands exactly where git would have said. That is the behaviour working, so
// comparing them proves nothing either way.
//
// What failed on POSIX was the KNOB, not the packager, so the knob is what is
// checked: a child process started the same way must actually see the
// variable. The packager's only shell_exec reads it, so if a child sees it the
// no-git path is genuinely taken; if some later change stops it arriving,
// this fails instead of the four checks below passing vacuously.
[, $seenByChild] = $noGitRun(escapeshellarg(PHP_BINARY) . ' -r '
    . escapeshellarg("echo (string)getenv('SNAPSMACK_GIT_BIN');"));
$seenByChild = trim($seenByChild);
// WHAT THIS STILL CANNOT PROVE, said plainly. If $withoutGit were changed to
// bypass $noGitRun and exec directly, the packager would run WITH git and
// every check below would still pass -- because both routes resolve to the
// same directory, which is the behaviour being correct, not a bug. That mutant
// survives and I am not going to pretend otherwise. Closing it would mean
// making the packager announce which route it took, which is output added for
// a test rather than for an operator. The probe holds the mechanism; the
// mechanism being USED by this particular call is held by there being only one
// of it.
$check('THE GIT KNOB REACHES THE CHILD PROCESS: ' . ($seenByChild ?: '(nothing)'),
    str_contains(str_replace(chr(92), '/', $seenByChild), 'no-such-git-binary'));
$check('and it is restored afterwards, so later checks run with real git',
    getenv('SNAPSMACK_GIT_BIN') === false || !str_contains(
        (string)getenv('SNAPSMACK_GIT_BIN'), 'no-such-git-binary'));
$check('the binary it points at really does not exist',
    !file_exists($root . '/no-such-git-binary'));

[$code, $output] = $withoutGit(['tilez', '--output=' . $root]);
$check('the packager still runs with no git available', $output !== '');
if (preg_match('~default is (.+?)\.?$~m', $output, $m) === 1) {
    $noGitDefault = str_replace(chr(92), '/', trim($m[1], " .\t\r\n"));
    $rootNorm = str_replace(chr(92), '/', (string)realpath($root));
    $check('WITHOUT GIT the default is still outside the checkout: ' . $noGitDefault,
        $noGitDefault !== '' && strncmp($noGitDefault, $rootNorm, strlen($rootNorm)) !== 0);
    $check('and carries no .claude segment', !str_contains($noGitDefault, '/.claude'));
    $check('and no worktrees segment', !str_contains($noGitDefault, '/worktrees'));
    $check('and no .git segment', !str_contains($noGitDefault, '/.git'));
} else {
    $check('the no-git run states its default output directory', false);
}

// ---------------------------------------------------------------------------
// THE D-GATE BLOCKER: THE CLIMB HAD NO FLOOR. (SS-1600D, reported by Codex as
// the nested no-git fallback.)
//
// The first fallback walked up while the current or parent directory was
// CALLED .claude, worktrees or .git. Measured against real paths, that breaks
// on a checkout which merely LIVES under a folder of that name: a repository
// at C:/dev/worktrees/snapsmack was climbed straight past, out of the
// checkout, and produced "C:\/dev-builds" -- the wrong directory AND a
// malformed path, because dirname() returns "C:\" at a drive root. C:/worktrees
// became "C:\/C-builds". Nesting was never the fault; the missing floor was.
//
// It no longer guesses from names. What the climb always MEANT is "the
// outermost SnapSmack checkout on this path", which is directly checkable and
// ends when the ancestor chain does.
//
// These drive the SHIPPED resolution by reading it out of the packager and
// running it, rather than re-implementing it here -- a local copy would pass
// while the tool stayed broken, which is the mistake this file has already
// been corrected for once.
$packagerSrc = (string)file_get_contents($root . '/tools/_build/package-skin.php');
$check('the resolution no longer climbs by directory NAME',
    !str_contains($packagerSrc, "['.claude', 'worktrees', '.git']"));
$check('it identifies a checkout by its tracked contents instead',
    str_contains($packagerSrc, "is_dir(\$dir . '/skins')")
    && str_contains($packagerSrc, "is_file(\$dir . '/index.php')"));
// COMMENTS ARE NOT CODE, and this is the second time today I have had to say
// so in a check of mine. The packager EXPLAINS the core/db.php mistake in a
// comment, quoting the path, so a substring search over the whole file cannot
// tell the explanation from the thing. Line comments are stripped first, which
// lets the record of the mistake stay where the next reader will find it.
$packagerCode = preg_replace('~^\s*(?://|\*|/\*).*$~m', '', $packagerSrc) ?? $packagerSrc;
$check('and NOT by core/db.php, which is generated at install and not in the repo',
    !str_contains($packagerCode, "core/db.php"));
$check('while the comment still records why that marker was wrong',
    str_contains($packagerSrc, "core/db.php"));
$check('the parent is normalised BEFORE the join, so a drive root cannot mix separators',
    str_contains($packagerSrc, "rtrim(str_replace('\\\\', '/', dirname(\$base)), '/')"));
// SOURCE ONLY, AND DECLARED UNOBSERVABLE. The loop also breaks when the parent
// equals the directory, which is the filesystem root. Mutation proof showed
// removing that break changes no answer: the walk is capped at 40 steps and
// $base is only ever assigned when a checkout is found, so a saturating
// dirname() merely wastes iterations. It is kept for clarity and because the
// cap should not be what stops it, and it is held as a source check rather
// than pretended to be a behaviour one.
$check('SOURCE ONLY: the walk stops at the filesystem root rather than relying on the cap',
    str_contains($packagerSrc, 'if ($parent === $probe) { break; }'));

// The two defaults must AGREE. With git and without it, from this nested
// worktree, the answer is the same directory -- that is the whole point of the
// fallback, and the version that broke produced different answers.
[$code, $output] = $withoutGit(['tilez', '--output=' . $root]);
if (preg_match('~default is (.+?)\.?$~m', $output, $m) === 1) {
    $noGitAgain = rtrim(str_replace(chr(92), '/', trim($m[1], " .\t\r\n")), '/');
    $check('WITH GIT AND WITHOUT IT, the default is the SAME directory: ' . $noGitAgain,
        $withGitDefault !== '' && rtrim($withGitDefault, '/') === $noGitAgain);
    $check('and it carries no doubled slash', !str_contains($noGitAgain, '//'));
    $check('and no backslash survived', !str_contains($noGitAgain, chr(92)));
} else {
    $check('the no-git run states its default for the agreement check', false);
}

// And it must still WORK without git, not merely refuse: a real site owner
// unpacking a zip has no repository and still needs to package a skin.
$noGitOut = $out . '-nogit';
@mkdir($noGitOut, 0777, true);
[$code, $output] = $withoutGit(['tilez', '--output=' . $noGitOut]);
$check('a skin still packages with no git available',
    $code === 0 && glob($noGitOut . '/tilez-*.zip') !== []);
foreach (glob($noGitOut . '/*') ?: [] as $leftover) { @unlink($leftover); }
@rmdir($noGitOut);

// ---------------------------------------------------------------------------
// A NESTED STANDALONE CLONE. (SS-1600D, second blocker, reported by Codex when
// the guarded v0.7.1007D tag run stopped.)
//
// The agreement check above runs from WHEREVER THIS SUITE HAPPENS TO SIT, so
// it only ever exercised the shape of that one checkout. In a clone nested
// inside another checkout the two answers came apart, and the suite could not
// see it: measured before the fix, from the nested clone, with git the default
// was <...>/outer/nested-builds and without git it was <...>/outer-builds.
//
// WHY, and it is not the floor this time. A clone sitting inside another
// checkout is its OWN repository, so its builds belong beside it -- which is
// what git answers. The climb asked only "does this look like a checkout" and
// so sailed past the clone into the containing tree. "The outermost checkout
// on this path" and "my own repository" are different questions, and this is
// where they diverge.
//
// A .git DIRECTORY marks a standalone repository root while a linked
// worktree's .git is a FILE, so the ceiling is a filesystem fact and the
// no-git path stays genuinely git-free.
//
// THIS RELOCATES THE SHIPPED TOOL RATHER THAN RE-IMPLEMENTING IT. The packager
// and core/ are COPIED OUT OF THIS CHECKOUT at run time, so the thing under
// test is the file on disk now, not a snapshot of what it said when the check
// was written. That is the distinction this file has already been corrected
// for once: a local copy of the RULE would pass while the tool stayed broken;
// a relocated copy of the TOOL cannot.
$nestRoot = sys_get_temp_dir() . '/ss-nested-clone-' . bin2hex(random_bytes(6));
$nestInner = $nestRoot . '/outer/nested';
$copyTree = static function (string $src, string $dst) use (&$copyTree): void {
    @mkdir($dst, 0777, true);
    foreach (scandir($src) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        is_dir($src . '/' . $entry)
            ? $copyTree($src . '/' . $entry, $dst . '/' . $entry)
            : @copy($src . '/' . $entry, $dst . '/' . $entry);
    }
};
try {
    // The container only has to LOOK like a checkout; it is not a repository.
    @mkdir($nestRoot . '/outer/skins', 0777, true);
    @mkdir($nestRoot . '/outer/core', 0777, true);
    @file_put_contents($nestRoot . '/outer/index.php', "<?php\n");
    // The nested checkout is a real standalone repository with the real tool.
    @mkdir($nestInner . '/skins', 0777, true);
    @mkdir($nestInner . '/tools/_build', 0777, true);
    @file_put_contents($nestInner . '/index.php', "<?php\n");
    $copyTree($root . '/core', $nestInner . '/core');
    @copy($root . '/tools/_build/package-skin.php', $nestInner . '/tools/_build/package-skin.php');

    $initCode = 1;
    $initLines = [];
    exec('git -C ' . escapeshellarg($nestInner) . ' init -q . 2>&1', $initLines, $initCode);
    $isRepo = $initCode === 0 && is_dir($nestInner . '/.git');

    if (!$isRepo) {
        // UNKNOWN IS NEVER A PASS. Without a git binary the nested repository
        // cannot be created, so the case is unobservable here and says so
        // instead of reporting a green it did not earn.
        echo "NOT CHECKED the nested-standalone-clone agreement: git could not "
           . "initialise the fixture repository\n";
    } else {
        $nestedPackager = $nestInner . '/tools/_build/package-skin.php';
        $readDefault = static function (string $extraEnv) use ($nestedPackager, $nestInner): string {
            $previous = getenv('SNAPSMACK_GIT_BIN');
            if ($extraEnv !== '') { putenv('SNAPSMACK_GIT_BIN=' . $extraEnv); }
            else { putenv('SNAPSMACK_GIT_BIN'); }
            try {
                $lines = [];
                $code = 1;
                exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($nestedPackager)
                    . ' tilez --output=' . escapeshellarg($nestInner) . ' 2>&1', $lines, $code);
                $text = implode("\n", $lines);
                return preg_match('~default is (.+?)\.?$~m', $text, $mm) === 1
                    ? rtrim(str_replace(chr(92), '/', trim($mm[1], " .\t\r\n")), '/')
                    : '';
            } finally {
                if ($previous === false) { putenv('SNAPSMACK_GIT_BIN'); }
                else { putenv('SNAPSMACK_GIT_BIN=' . $previous); }
            }
        };
        $nestedWithGit    = $readDefault('');
        $nestedWithoutGit = $readDefault($nestRoot . '/no-such-git-binary');

        $check('the nested clone states its default with git', $nestedWithGit !== '');
        $check('the nested clone states its default without git', $nestedWithoutGit !== '');
        // The correct answer is the clone's own sibling, which is what git says.
        $expected = rtrim(str_replace(chr(92), '/', $nestInner), '/') . '-builds';
        $check('WITH GIT a nested standalone clone builds beside ITSELF: ' . $nestedWithGit,
            $nestedWithGit === $expected);
        $check('WITHOUT GIT it reaches the SAME answer and does not climb into the '
            . 'containing checkout: ' . $nestedWithoutGit,
            $nestedWithoutGit === $expected);
        // Named separately, because this exact pair of values is what stopped
        // the tag run and it should be legible in the output when it recurs.
        $check('so the two defaults AGREE in a nested standalone clone',
            $nestedWithGit !== '' && $nestedWithGit === $nestedWithoutGit);
    }
} finally {
    $rmTree = static function (string $dir) use (&$rmTree): void {
        if (!is_dir($dir)) { return; }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $path = $dir . '/' . $entry;
            @chmod($path, 0777);
            is_dir($path) ? $rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    };
    $rmTree($nestRoot);
    // CHECKED, not assumed: this fixture is several megabytes and leaving it
    // behind on every suite run would fill the temp directory.
    $check('the nested-clone fixture was removed', !is_dir($nestRoot));
}


echo "\n";
if ($fails) {
    echo "FAILED (" . count($fails) . "):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL PASS\n";

// NOT CHECKED: a real site taking a re-baselined package over the wire. That
// needs two installs.
//
// The footer here used to say the packager "defaults its output to the PROJECT
// ROOT", which was true when it was written and is now false -- the default is
// outside the checkout and the checkout is refused. Muse caught the stale
// claim. A comment that was true once is the most believable kind of wrong.

// ===== SNAPSMACK EOF =====
