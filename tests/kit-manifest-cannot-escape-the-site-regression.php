<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SECAUDIT 047 / SS-1426 — the MANIFEST phase cannot write outside the site.
 *
 * WHY THIS FILE EXISTS AT ALL. The manifest phase's containment guard has been
 * in core/recovery-engine.php since SECAUDIT 047 and had no negative test. It
 * was also the guard I copied when I wrote the media phase's
 * safeRelativeTarget() for SS-1426/1431 -- so when Codex found a symlink
 * escape in my copy, the original had the identical hole, untested, in the same
 * file, on the same attacker-controlled input.
 *
 * THE ESCAPE, measured on the code before the repair rather than argued:
 * a manifest entry with restores_to "media/new/notes.txt", where "media" is an
 * existing junction pointing outside the site and "media/new" does not exist
 * yet. The old guard asked realpath(dirname($targetPath)), which returns false
 * for a path whose parent is not there, and it SKIPPED the check when that
 * happened -- not failed it, skipped it. The phase then created the missing
 * directory THROUGH the junction and copied the bundled file outside the site:
 * files_restored 1, nothing blocked, no error.
 *
 * A linked media directory is not an exotic setup. Moving uploads onto a bigger
 * volume and leaving a link behind is an ordinary hosting arrangement, so this
 * is reachable on a real site by a kit the owner was handed.
 *
 * WHAT THIS TEST PROVES, and the only way it can be proved: it builds a real
 * .tar.gz kit with a hostile manifest, runs the real importRecoveryKit(), and
 * then looks at the filesystem on the far side of the link. No return value is
 * trusted for the verdict -- the counters are checked too, but the file either
 * is out there or it is not.
 *
 * WHAT IT DOES NOT PROVE: nothing is installed, rendered or deployed, and no
 * live site is touched. Every path is inside a disposable temp tree that this
 * file removes, link first.
 */

$root = dirname(__DIR__);
require_once $root . '/core/constants.php';
require_once $root . '/core/recovery-engine.php';

$failures = [];

function me_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

/**
 * Make a directory link, or return false.
 *
 * symlink() needs SeCreateSymbolicLink on Windows and normally fails
 * unelevated, so a directory junction via mklink /J is the fallback and needs
 * no privilege. On Linux and macOS the first call simply works. The escape
 * works through all of them.
 */
function me_make_dir_link(string $target, string $link): bool {
    if (@symlink($target, $link)) return true;
    if (DIRECTORY_SEPARATOR !== '\\') return false;
    $w = str_replace('/', DIRECTORY_SEPARATOR, $link);
    $t = str_replace('/', DIRECTORY_SEPARATOR, $target);
    @exec('cmd /c mklink /J "' . $w . '" "' . $t . '" 2>&1', $out, $rc);
    return $rc === 0 && realpath($link) !== false;
}

/** Remove a directory link WITHOUT following it. */
function me_drop_dir_link(string $link): void {
    if (DIRECTORY_SEPARATOR === '\\') { @rmdir($link); @unlink($link); return; }
    @unlink($link);
    @rmdir($link);
}

/**
 * Remove a fixture tree, guarded to paths under the system temp directory.
 *
 * The guard is not decoration: a recursive delete driven by a variable is the
 * shape of an accident, and this one runs in a suite people execute without
 * reading. Any link must be dropped BEFORE this runs, or the walk follows it
 * and deletes the link's target instead.
 */
function me_rmtree(string $dir): void {
    $tmp  = realpath(sys_get_temp_dir());
    $real = realpath($dir);
    if ($tmp === false || $real === false) return;
    if (strncmp($real, $tmp, strlen($tmp)) !== 0 || $real === $tmp) return;
    foreach (new RecursiveIteratorIterator(
                 new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
                 RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($real);
}

/**
 * sqlite that answers the two MySQL-only statements the pre-restore snapshot
 * needs. Without it the SS-1425 snapshot fails, the import refuses, and every
 * check below passes on an import that never ran -- which would make this file
 * worse than having no file.
 */
class MeDumpablePdo extends PDO {
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        if (stripos($query, 'SHOW TABLES') === 0) {
            return parent::query("SELECT name FROM sqlite_master WHERE type='table' "
                . "AND name NOT LIKE 'sqlite_%'");
        }
        if (stripos($query, 'SHOW CREATE TABLE') === 0) {
            $t = trim(substr($query, strlen('SHOW CREATE TABLE')), " `\t\n");
            $sql = parent::query('SELECT sql FROM sqlite_master WHERE name='
                . parent::quote($t))->fetchColumn();
            return parent::query('SELECT ' . parent::quote($t) . ' AS a, '
                . parent::quote((string)$sql) . ' AS b');
        }
        return parent::query($query);
    }
}

/**
 * Build a kit whose manifest aims the given bundled files at the given
 * targets, run the real import, and hand back the result and the stream.
 *
 * @param array<string,string> $entries  bundled name => restores_to
 * @return array{0: array<string,mixed>, 1: string, 2: string}
 */
function me_import(array $entries, string $siteDir, string $tmpDir): array {
    $kit = $tmpDir . '/kit_' . bin2hex(random_bytes(4));
    mkdir($kit, 0777, true);

    $files = ['database.sql' => ['sha256' => 'x']];
    foreach ($entries as $name => $restoresTo) {
        $payload = 'ESCAPED-PAYLOAD-' . $name;
        file_put_contents($kit . '/' . $name, $payload);
        $files[$name] = [
            'restores_to' => $restoresTo,
            'checksum'    => 'sha256:' . hash('sha256', $payload),
        ];
    }

    file_put_contents($kit . '/manifest.json', json_encode([
        'export_type'       => 'recovery-kit',
        'export_date'       => '2026-11-19T22:00:00+00:00',
        'snapsmack_version' => '0.0.1D',
        'site_name'         => 'Found Textures',
        'site_url'          => 'https://foundtextures.ca',
        'site_mode'         => 'photoblog',
        'site_uuid'         => 'uuid-aaa',
        'files'             => $files,
    ]));
    file_put_contents($kit . '/database.sql',
        "DROP TABLE IF EXISTS `snap_images`;\n"
        . "CREATE TABLE `snap_images` (id INTEGER PRIMARY KEY, img_file TEXT, "
        . "img_thumb_square TEXT, img_thumb_aspect TEXT, img_checksum TEXT);\n");

    $archive = $kit . '.tar';
    $phar = new PharData($archive);
    $phar->buildFromDirectory($kit);
    unset($phar);

    $pdo = new MeDumpablePdo('sqlite::memory:', null, null,
                             [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
    foreach ([['installed_version', (string)SNAPSMACK_VERSION_SHORT],
              ['site_mode', 'photoblog'], ['site_url', 'https://foundtextures.ca'],
              ['site_uuid', 'uuid-aaa']] as $row) {
        $pdo->prepare('INSERT INTO snap_settings VALUES (?, ?)')->execute($row);
    }

    ob_start();
    $result = (new SnapSmackRecovery($pdo, $siteDir))->importRecoveryKit($archive);
    $stream = (string)ob_get_clean();

    return [$result, $stream, implode(' ', (array)($result['errors'] ?? []))];
}

echo "SECAUDIT 047 / SS-1426 — a hostile manifest cannot write outside the site\n\n";

$tmp  = sys_get_temp_dir() . '/ssmanifest_' . bin2hex(random_bytes(6));
$site = $tmp . '/site';
$out  = $tmp . '/outside';
mkdir($site, 0777, true);
mkdir($out, 0777, true);

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THROUGH A LINK, WITH THE DEEPER DIRECTORY MISSING\n";
// ─────────────────────────────────────────────────────────────────────────────
// The case the old guard skipped. The link must already exist and the child
// must NOT: with "media/new" present the old realpath(dirname()) check caught
// it, which is exactly why the hole was invisible.
$link     = $site . '/media';
$haveLink = me_make_dir_link($out, $link);

if (!$haveLink) {
    echo "  NOT CHECKED  this box cannot create a directory link, so the\n";
    echo "               manifest symlink escape is UNVERIFIED here. Not a pass.\n";
    $failures[] = 'NOT CHECKED: manifest symlink escape could not be exercised';
} else {
    me_check('the fixture has a link inside the site pointing outside',
             realpath($link) !== false && realpath($link) === realpath($out), $failures);
    me_check('and the deeper directory does NOT exist, which is what skipped the old check',
             realpath($link . '/new') === false, $failures);

    [$r1, $s1, $e1] = me_import(['notes.txt' => 'media/new/notes.txt'], $site, $tmp);

    me_check('the manifest entry is BLOCKED, not restored',
             ($r1['files_restored'] ?? 1) === 0, $failures);
    me_check('the refusal says it resolves outside the site, not something vaguer',
             str_contains($e1, 'resolves outside the site directory')
             || str_contains($s1, 'resolves outside the site directory'), $failures);
    me_check('and it is recorded as an ERROR, not counted as merely absent from the kit',
             str_contains($e1, 'escapes base dir')
             && ($r1['missing'] ?? 1) === 0, $failures);

    // THE CHECK THAT MATTERS, and the one that failed before the repair.
    $leaked = [];
    foreach (['/new/notes.txt', '/new', '/notes.txt'] as $probe) {
        if (file_exists($out . $probe)) $leaked[] = $out . $probe;
    }
    me_check('NOTHING was written through the link, and no directory made there'
             . ($leaked ? ' — FOUND: ' . implode(', ', $leaked) : ''),
             $leaked === [], $failures);

    // ─────────────────────────────────────────────────────────────────────────
    echo "\nSTAGE 2 — straight into the link, and deeper past it\n";
    // ─────────────────────────────────────────────────────────────────────────
    [$r2, $s2, $e2] = me_import([
        'a.txt' => 'media/a.txt',
        'b.txt' => 'media/deep/deeper/b.txt',
    ], $site, $tmp);
    me_check('both are BLOCKED',
             ($r2['files_restored'] ?? 1) === 0, $failures);
    $leaked2 = [];
    foreach (['/a.txt', '/deep', '/deep/deeper/b.txt'] as $probe) {
        if (file_exists($out . $probe)) $leaked2[] = $out . $probe;
    }
    me_check('and nothing reached the far side of the link'
             . ($leaked2 ? ' — FOUND: ' . implode(', ', $leaked2) : ''),
             $leaked2 === [], $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — THE STRING CASES THE OLD GUARD DID BLOCK, now with a test\n";
// ─────────────────────────────────────────────────────────────────────────────
// These were correct before this change and had no test. A repair that
// accidentally loosened one of them would have gone unnoticed, which is the
// other half of why this file exists.
$stringCases = [
    'traversal'                  => '../escaped.txt',
    'traversal, two levels'      => '../../escaped.txt',
    'traversal mid-path'         => 'img/../../escaped.txt',
    'absolute path'              => '/etc/escaped.txt',
    'drive-lettered path'        => 'C:/escaped.txt',
    'executable extension'       => 'shell.php',
    'executable middle token'    => 'shell.php.jpg',
    'dotfile'                    => '.htaccess',
    'dotfile in a subdirectory'  => 'ok/.htaccess',
    'skin code'                  => 'skins/alfred/layout.php',
];
foreach ($stringCases as $label => $target) {
    [$rs, $ss, $es] = me_import(['payload.txt' => $target], $site, $tmp);
    me_check("REFUSED: {$label} ({$target})",
             ($rs['files_restored'] ?? 1) === 0
             && (str_contains($es, 'Rejected') || str_contains($es, 'escapes base dir')),
             $failures);
}

$strays = [];
foreach (['/escaped.txt', '/etc', '/shell.php', '/.htaccess'] as $probe) {
    if (file_exists($tmp . $probe)) $strays[] = $tmp . $probe;
}
me_check('and none of them put a file beside the site'
         . ($strays ? ' — FOUND: ' . implode(', ', $strays) : ''),
         $strays === [], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — A LEGITIMATE BUNDLED FILE STILL RESTORES\n";
// ─────────────────────────────────────────────────────────────────────────────
// Without this the whole file would pass on a guard that refused everything,
// which is the direction a containment repair fails in. The target is four
// directories deep and none of them exists, because that is the case the walk
// has to allow rather than refuse.
[$rg, $sg, $eg] = me_import(['readme.txt' => 'data/notes/2026/11/readme.txt'], $site, $tmp);
me_check('the ordinary target is restored',
         ($rg['files_restored'] ?? 0) >= 1, $failures);
me_check('and the file really is on disk, inside the site, at that depth',
         is_file($site . '/data/notes/2026/11/readme.txt'), $failures);
me_check('and it was not reported as an escape',
         !str_contains($eg, 'escapes base dir'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — ONE containment check, not two\n";
// ─────────────────────────────────────────────────────────────────────────────
// Two copies of this check is the reason one of them stayed broken while the
// other was repaired. Read comment-stripped, so the note explaining the
// de-duplication cannot satisfy the check that it happened.
$src = '';
foreach (@token_get_all((string)file_get_contents($root . '/core/recovery-engine.php')) as $t) {
    if (is_array($t)) {
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
        $src .= $t[1];
        continue;
    }
    $src .= $t;
}
me_check('the shared helper exists',
         str_contains($src, 'function pathStaysInsideBase'), $failures);
me_check('and both phases call it, rather than carrying their own copy',
         substr_count($src, '$this->pathStaysInsideBase(') >= 2, $failures);
me_check('the old parent-only form is gone from the file',
         !str_contains($src, 'realpath(dirname($targetPath))'), $failures);

// Drop the link BEFORE the recursive delete, or the walk follows it.
if ($haveLink) me_drop_dir_link($link);
me_rmtree($tmp);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SECAUDIT 047 / SS-1426 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SECAUDIT 047 / SS-1426 PASS — a hostile manifest cannot write outside the\n";
echo "site, through a link or otherwise, and an ordinary bundled file four\n";
echo "directories deep still restores.\n";
echo "NOT COVERED: the escape is exercised through a Windows JUNCTION here,\n";
echo "because symlink() needs a privilege this box does not have. The guard's\n";
echo "broken-link branch is therefore NOT reached on Windows, where a dangling\n";
echo "junction reports file_exists, is_link and realpath all false; that branch\n";
echo "needs a host with real symlinks. Nothing installed, rendered or deployed.\n";
// ===== SNAPSMACK EOF =====
