<?php
/**
 * The installer never writes a version it did not read from the package.
 *
 * SS-1078. If core/constants.php was missing or unreadable the installer did
 * not stop. It carried a hardcoded fallback of 0.7.191 -- a number from
 * February 2026 -- and then GENERATED a new core/constants.php declaring the
 * site to be that version, on top of whatever code was actually on disk.
 *
 * WHAT THAT CAUSED, and the third one is the dangerous one:
 *   1. the site advertised a February build through nodeinfo;
 *   2. the hub roster recorded the wrong number;
 *   3. THE UPDATER THEN OFFERED IT A DOWNGRADE AND CALLED IT AN UPGRADE.
 *      An update is offered whenever the remote version is greater than the
 *      installed one, and 0.7.670 is greater than 0.7.191 -- so a site running
 *      current code would be told the old stable build was newer and would
 *      take it, replacing current code with one the security model forbids.
 *      The updater's one protection, never going backwards, is defeated by a
 *      version string that lies.
 *
 * THE FIX IS TO REFUSE, NOT TO RAISE THE NUMBER. Bumping the fallback to the
 * current version hides the symptom and keeps the fault: the installer would
 * still be guessing its own version. It cannot know what it is installing
 * without that file, and an invented version is worse than a stop.
 *
 * THE DECISION NOW RIDES ON IT. Since SS-1076 the installer derives the update
 * track from this same version, so a fallback would also subscribe the site to
 * the wrong line.
 *
 * RUN, NOT READ. The last case builds a throwaway extraction, deletes
 * core/constants.php from it, and runs the real installer there -- which is
 * acceptance point 3 of the ticket, verbatim.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = realpath(__DIR__ . '/..');
$failures = [];
function inv_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

/** Source with comments stripped, so no check can match prose about itself. */
function inv_code(string $path): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($path)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

$install = inv_code("$root/install.php");

// ── No invented version anywhere in the file ───────────────────────────────
inv_test(!preg_match("/\\\$installer_version\s*=\s*'[0-9]/", $install),
    'install.php still assigns a hardcoded version number to $installer_version; '
  . 'that is the invented version SS-1078 is about');
inv_test(!str_contains($install, '0.7.191'),
    'the 0.7.191 fallback is still in install.php');

// ── The gate names the file it needs ───────────────────────────────────────
inv_test((bool)preg_match("#core/constants\.php'?\s*\)\s*\|\||!file_exists\(__DIR__ \. '/core/constants\.php'\)#", $install)
      || (bool)preg_match("#file_exists\(__DIR__ \. '/core/constants\.php'\)#", $install),
    'the codebase gate does not check for core/constants.php, so an incomplete '
  . 'extraction still passes it');

// ── And the version still reaches the places that need it ─────────────────
inv_test(str_contains($install, 'SNAPSMACK_VERSION_SHORT'),
    'install.php no longer reads the real version from the package');
inv_test(str_contains($install, 'updater_track_for_version'),
    'the update track no longer derives from the package version (SS-1076)');

// ── ACCEPTANCE POINT 3: remove the file and run it ────────────────────────
$dir = sys_get_temp_dir() . '/ss-inv-' . bin2hex(random_bytes(6));
@mkdir("$dir/core", 0777, true);
@copy("$root/install.php", "$dir/install.php");
// A tree that passes the OLD gate: core/ exists and holds parser.php, but
// constants.php is absent. That is exactly the incomplete extraction the
// ticket describes -- it used to install and declare itself 0.7.191.
file_put_contents("$dir/core/parser.php", "<?php\n// stand-in\n");
$runner = "$dir/_run.php";
file_put_contents($runner,
    '<?php $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["HTTP_HOST"]="example.test";'
  . '$_SERVER["REQUEST_URI"]="/install.php"; $_SERVER["SCRIPT_NAME"]="/install.php";'
  . '$_GET=[]; $_POST=[]; include ' . var_export("$dir/install.php", true) . ';');
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1');

$mentionsFile = stripos($out, 'constants.php') !== false;
$invented     = str_contains($out, '0.7.191');
$wroteIt      = is_file("$dir/core/constants.php");

inv_test($mentionsFile,
    'an extraction missing core/constants.php did not stop with a message naming '
  . 'the missing file. It said: ' . substr(trim(strip_tags($out)), 0, 220));
inv_test(!$invented,
    'the installer still announced version 0.7.191 on an extraction that has no '
  . 'version to read');
inv_test(!$wroteIt,
    'the installer WROTE core/constants.php for a package whose version it could '
  . 'not read -- that is the invented version reaching disk');

// THE RECOVERY PATH MUST NOT BE A WAY ROUND IT. The ticket names recovery as
// a second place that generated the file from the same fallback, and recovery
// is exactly when somebody is most likely to be running on a damaged tree.
file_put_contents($runner,
    '<?php $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["HTTP_HOST"]="example.test";'
  . '$_SERVER["REQUEST_URI"]="/install.php"; $_SERVER["SCRIPT_NAME"]="/install.php";'
  . '$_GET=["mode"=>"recovery"]; $_POST=[]; include ' . var_export("$dir/install.php", true) . ';');
$recOut = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1');
inv_test(stripos($recOut, 'constants.php') !== false,
    'RECOVERY MODE walked past the missing-file stop. It said: '
  . substr(trim(strip_tags($recOut)), 0, 200));
inv_test(!is_file("$dir/core/constants.php"),
    'recovery mode wrote core/constants.php for a package whose version it could not read');

foreach (glob("$dir/core/*") ?: [] as $f) @unlink($f);
foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
@rmdir("$dir/core"); @rmdir($dir);

// ── AND A GOOD EXTRACTION MUST STILL INSTALL ──────────────────────────────
// A stop that is too eager is worse than the fault it replaced: it would
// refuse every real install. This is the other half of the gate and it has to
// be held down by a test, not by having checked it once.
$ok = sys_get_temp_dir() . '/ss-ok-' . bin2hex(random_bytes(6));
@mkdir("$ok/core", 0777, true);
@copy("$root/install.php", "$ok/install.php");
file_put_contents("$ok/core/parser.php", "<?php\n");
file_put_contents("$ok/core/constants.php",
    "<?php\ndefine('SNAPSMACK_VERSION','Dev 0.7.999D');\ndefine('SNAPSMACK_VERSION_SHORT','0.7.999D');\n");
$okRunner = "$ok/_run.php";
file_put_contents($okRunner,
    '<?php $_SERVER["REQUEST_METHOD"]="GET"; $_SERVER["HTTP_HOST"]="example.test";'
  . '$_SERVER["REQUEST_URI"]="/install.php"; $_SERVER["SCRIPT_NAME"]="/install.php";'
  . '$_GET=[]; $_POST=[]; include ' . var_export("$ok/install.php", true) . ';');
$okOut = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($okRunner) . ' 2>&1');

inv_test(stripos($okOut, 'INCOMPLETE EXTRACTION') === false,
    'a COMPLETE extraction was refused as incomplete; the gate is too eager and '
  . 'would block every real install');
inv_test(str_contains($okOut, '0.7.999D'),
    'a complete extraction did not report the version it actually read from the package');
inv_test(stripos($okOut, 'fatal error') === false,
    'the installer fatals on a complete extraction: ' . substr(trim(strip_tags($okOut)), 0, 200));

foreach (glob("$ok/core/*") ?: [] as $f) @unlink($f);
foreach (glob("$ok/*") ?: [] as $f) @unlink($f);
@rmdir("$ok/core"); @rmdir($ok);

if ($failures) {
    fwrite(STDERR, "FAIL installer invents its version (" . count($failures) . ")\n  - "
        . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "PASS the installer refuses rather than inventing a version\n";
// ===== SNAPSMACK EOF =====
