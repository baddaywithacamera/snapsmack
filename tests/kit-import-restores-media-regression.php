<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1426 + SS-1431 — a kit import reconciles the media, not just the rows.
 *
 * THE DIAGNOSIS, AND I HAD IT WRONG FIRST. I carried this as "three helpers
 * with no callers". They have callers: install.php:3146-3179 runs
 * ensureDirectories, restoreImages, regenerateAndChecksum, restoreMediaAssets
 * and restoreBranding as five numbered phases, and that path works. What was
 * missing is that the ADMIN kit-import path called none of it.
 *
 * So a kit import restored the database and the bundled files, synced the
 * schema, and left every photograph, thumbnail, asset and branding file
 * unreconciled. snap_images rows pointed at files that might not be on disk
 * and nothing counted them: the owner was told the restore worked and their
 * photographs were not there. SS-1431 says reuse the installer's path, so the
 * sequence is copied from it rather than written again.
 *
 * WHAT THIS PROVES BY LOOKING AT THE DISK AND THE DATABASE, which is the only
 * way to prove it: a real .tar.gz kit goes through the real importRecoveryKit()
 * and then the photograph is read off the filesystem at the path the restored
 * row claims, and the restored row is read back out of the database.
 *
 * WHAT IT CANNOT PROVE HERE, stated rather than implied: this box has no GD
 * extension, so regenerateAndChecksum() cannot make a thumbnail. That is not
 * worked around -- it is asserted, because a GD-less host is a real host and
 * the import must survive one. The thumbnail phase failing must not lose the
 * count of photographs already copied, and that is checked below. Proving a
 * thumbnail is actually generated needs a box with GD.
 */

$root = dirname(__DIR__);
require_once $root . '/core/constants.php';
require_once $root . '/core/recovery-engine.php';

$failures = [];

function km_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

function km_code_only(string $php): string {
    $out = '';
    foreach (@token_get_all($php) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) continue;
            $out .= $t[1];
            continue;
        }
        $out .= $t;
    }
    return $out;
}

/**
 * sqlite that answers the two MySQL-only statements the snapshot dumper needs.
 *
 * Borrowed from tests/pre-restore-snapshot-fails-closed-regression.php. Without
 * it the SS-1425 snapshot fails and the import refuses before it ever reaches
 * the media, which would make every check below pass on an import that never
 * happened -- the exact shape the PM named on Tuesday.
 */
class KmDumpablePdo extends PDO {
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
 * Remove a fixture directory and everything under it.
 *
 * Guarded to paths inside the system temp directory, because a recursive
 * delete driven by a variable is exactly the shape of an accident, and this
 * one runs in a test suite that people execute without reading.
 */
function km_rmtree(string $dir): void {
    $tmp = realpath(sys_get_temp_dir());
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

/** A 1x1 JPEG, byte for byte, because this box has no GD to make one with. */
function km_jpeg(): string {
    return base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
        . 'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPDI0NP/bAEMBCQkJDAsMGA0NGDIhHCEyMjIyMjIy'
        . 'MjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEB'
        . 'AxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9'
        . 'AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6'
        . 'Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ip'
        . 'qrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oADAMBAAIR'
        . 'AxEAPwD3+gD/2Q=='
    );
}

echo "SS-1426 + SS-1431 — a kit import brings the photographs back\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — a kit with a photograph in it, and a site without one\n";
// ─────────────────────────────────────────────────────────────────────────────
$tmp  = sys_get_temp_dir() . '/ss1426_' . bin2hex(random_bytes(6));
$site = $tmp . '/site';
$kit  = $tmp . '/kit';
mkdir($site, 0777, true);
mkdir($kit, 0777, true);

$rel = 'img_uploads/2026/11/seascape.jpg';
file_put_contents($kit . '/seascape.jpg', km_jpeg());
km_check('the kit carries a real photograph beside its manifest',
         is_file($kit . '/seascape.jpg') && filesize($kit . '/seascape.jpg') > 100, $failures);

$manifest = [
    'export_type'       => 'recovery-kit',
    'export_date'       => '2026-11-19T09:00:00+00:00',
    'snapsmack_version' => '0.0.1D',          // older than any build: allowed
    'site_name'         => 'Found Textures',
    'site_url'          => 'https://foundtextures.ca',
    'site_mode'         => 'photoblog',
    'site_uuid'         => 'uuid-aaa',
    'files'             => ['database.sql' => ['sha256' => 'x']],
];
file_put_contents($kit . '/manifest.json', json_encode($manifest));

// The dump brings back a row that POINTS AT A FILE THE SITE DOES NOT HAVE.
// That is the whole fault: before this change the row came back and the
// photograph did not.
file_put_contents($kit . '/database.sql',
    "DROP TABLE IF EXISTS `snap_images`;\n"
    . "CREATE TABLE `snap_images` (id INTEGER PRIMARY KEY, img_file TEXT, "
    . "img_thumb_square TEXT, img_thumb_aspect TEXT, img_checksum TEXT);\n"
    . "INSERT INTO `snap_images` (id, img_file) VALUES (1, '{$rel}');\n");

$archive = $tmp . '/kit.tar';
$phar = new PharData($archive);
$phar->buildFromDirectory($kit);
unset($phar);

$pdo = new KmDumpablePdo('sqlite::memory:', null, null,
                         [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
foreach ([['installed_version', (string)SNAPSMACK_VERSION_SHORT],
          ['site_mode', 'photoblog'], ['site_url', 'https://foundtextures.ca'],
          ['site_uuid', 'uuid-aaa']] as $row) {
    $pdo->prepare('INSERT INTO snap_settings VALUES (?, ?)')->execute($row);
}

km_check('the site does NOT have the photograph before the import',
         !is_file($site . '/' . $rel), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — the import, and then the DISK\n";
// ─────────────────────────────────────────────────────────────────────────────
$engine = new SnapSmackRecovery($pdo, $site);
ob_start();
$result = $engine->importRecoveryKit($archive);
$progress = (string)ob_get_clean();

km_check('the import got past the snapshot and ran the SQL',
         ($result['sql_imported'] ?? 0) > 0, $failures);
km_check('and it is not a refusal', ($result['refused'] ?? null) === false, $failures);

// THE CHECK THIS TICKET EXISTS FOR. Read the file off the disk at the path the
// restored row claims. No return value can fake this.
km_check('THE PHOTOGRAPH IS ON DISK at the path the restored row points to',
         is_file($site . '/' . $rel), $failures);
km_check('and it is the same bytes the kit carried',
         is_file($site . '/' . $rel)
         && hash_file('sha256', $site . '/' . $rel) === hash('sha256', km_jpeg()),
         $failures);

// And the database row is still the one that pointed at it.
$stored = $pdo->query("SELECT img_file FROM snap_images WHERE id=1")->fetchColumn();
km_check('the restored row still points at that path', $stored === $rel, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — the counts the owner reads\n";
// ─────────────────────────────────────────────────────────────────────────────
km_check('the photograph is counted in files_restored',
         ($result['files_restored'] ?? 0) >= 1, $failures);
km_check('the media sub-result records the image phase',
         isset($result['media']['images']['restored'])
         && $result['media']['images']['restored'] === 1, $failures);
km_check('the progress stream told the owner about the images phase',
         str_contains($progress, 'Images:'), $failures);
km_check('and named the file as it restored it',
         str_contains($progress, 'seascape.jpg'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — A GD-LESS HOST MUST NOT LOSE THE COUNT (my own first bug)\n";
// ─────────────────────────────────────────────────────────────────────────────
// regenerateAndChecksum() reaches imagecreatefromjpeg() through loadImage(),
// which on a host without the GD extension is a fatal Error, not a handled
// failure. My first version of this wiring folded all four phases' counts in
// at the END of one try block, so the throw skipped the accounting: the
// photographs were copied and the owner was told 0 restored. Reporting LESS
// than happened is the same class of lie as reporting more.
//
// This box has no GD, so that path is live here rather than hypothetical.
$hasGd = function_exists('imagecreatefromjpeg');
echo '  (this box ' . ($hasGd ? 'HAS' : 'does NOT have') . " GD)\n";
km_check('the photograph count survived the thumbnail phase either way',
         ($result['media']['images']['restored'] ?? 0) === 1
         && ($result['files_restored'] ?? 0) >= 1, $failures);
if (!$hasGd) {
    km_check('and the failing phase is named, not swallowed and not blamed on the restore',
             str_contains(implode(' ', $result['errors'] ?? []), 'Thumbnails')
             || ($result['media']['thumbs'] ?? null) !== null, $failures);
    km_check('while the database is still reported as restored',
             ($result['sql_imported'] ?? 0) > 0, $failures);
    // AND THE PHASES AFTER IT STILL RAN. A mutation changing `continue` to
    // `break` survived an earlier version of this file: on a GD-less host the
    // thumbnail phase fails, and with `break` the assets and branding phases
    // would never run at all -- silently, because these fixtures have no asset
    // or branding rows for them to act on. One host missing one extension
    // would have cost the owner their branding too.
    km_check('the phases AFTER the failing one still ran',
             ($result['media']['assets'] ?? null) !== null
             && ($result['media']['branding'] ?? null) !== null, $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — a kit WITHOUT the photograph counts it missing, honestly\n";
// ─────────────────────────────────────────────────────────────────────────────
$tmp2  = sys_get_temp_dir() . '/ss1426b_' . bin2hex(random_bytes(6));
$site2 = $tmp2 . '/site';
$kit2  = $tmp2 . '/kit';
mkdir($site2, 0777, true);
mkdir($kit2, 0777, true);
file_put_contents($kit2 . '/manifest.json', json_encode($manifest));
file_put_contents($kit2 . '/database.sql',
    "DROP TABLE IF EXISTS `snap_images`;\n"
    . "CREATE TABLE `snap_images` (id INTEGER PRIMARY KEY, img_file TEXT, "
    . "img_thumb_square TEXT, img_thumb_aspect TEXT, img_checksum TEXT);\n"
    . "INSERT INTO `snap_images` (id, img_file) VALUES (1, 'img_uploads/2026/gone.jpg');\n");
$archive2 = $tmp2 . '/kit.tar';
$phar2 = new PharData($archive2);
$phar2->buildFromDirectory($kit2);
unset($phar2);

$pdo2 = new KmDumpablePdo('sqlite::memory:', null, null,
                          [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo2->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
foreach ([['installed_version', (string)SNAPSMACK_VERSION_SHORT],
          ['site_mode', 'photoblog'], ['site_url', 'https://foundtextures.ca'],
          ['site_uuid', 'uuid-aaa']] as $row) {
    $pdo2->prepare('INSERT INTO snap_settings VALUES (?, ?)')->execute($row);
}

ob_start();
$r2 = (new SnapSmackRecovery($pdo2, $site2))->importRecoveryKit($archive2);
$progress2 = (string)ob_get_clean();

km_check('a photograph the kit does not carry is counted MISSING',
         ($r2['missing'] ?? 0) >= 1, $failures);
km_check('and not counted as restored',
         ($r2['media']['images']['restored'] ?? 0) === 0, $failures);
km_check('and the owner is told, in the stream, which file is missing',
         str_contains($progress2, 'gone.jpg'), $failures);
km_check('and nothing was invented on disk',
         !is_file($site2 . '/img_uploads/2026/gone.jpg'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5b — A HOSTILE KIT CANNOT WRITE OUTSIDE THE SITE (Codex's FAIL)\n";
// ─────────────────────────────────────────────────────────────────────────────
// THE FAULT WAS MINE AND THIS TICKET CAUSED IT. restoreImages(),
// restoreMediaAssets() and restoreBranding() have always joined
// baseDir . '/' . <value from the database> and copied with no containment
// check. Survivable while install.php was their only caller, where the rows
// come from a fresh install.
//
// Wiring them into the kit-import path made those rows come from the KIT'S OWN
// SQL DUMP. Codex proved it: snap_images.img_file of "../escaped.php" plus a
// matching file beside the manifest writes a PHP file OUTSIDE the site root
// and reports restored: 1 with no errors. I reproduced it before changing
// anything, and it is reproduced here so it cannot come back.
//
// I did this four lines from the manifest phase's own SECAUDIT 047 containment
// check, which I had read and quoted while studying that code. Seeing a guard
// is not the same as noticing one is missing next door.
$evil = sys_get_temp_dir() . '/ss1426_evil_' . bin2hex(random_bytes(6));
$evilSite = $evil . '/site';
$evilFlat = $evil . '/flat';
mkdir($evilSite, 0777, true);
mkdir($evilFlat, 0777, true);
file_put_contents($evilFlat . '/escaped.php', "<?php echo 'PWNED';");
file_put_contents($evilFlat . '/logo.png', km_jpeg());

$evilPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$evilPdo->exec('CREATE TABLE snap_images (id INTEGER PRIMARY KEY, img_file TEXT)');
$evilPdo->exec('CREATE TABLE snap_assets (id INTEGER PRIMARY KEY, asset_path TEXT)');
$evilPdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');

$evilPaths = [
    '../escaped.php'        => 'parent-directory traversal',
    '../../escaped.php'     => 'traversal, two levels',
    'img/../../escaped.php' => 'traversal mid-path',
    '/etc/escaped.php'      => 'absolute path',
    'shell.php.jpg'         => 'executable extension in a middle token',
    '.htaccess'             => 'dotfile',
    'ok/.htaccess'          => 'dotfile in a subdirectory',
];
$i = 0;
foreach ($evilPaths as $bad => $label) {
    $evilPdo->prepare('INSERT INTO snap_images (id, img_file) VALUES (?, ?)')
            ->execute([++$i, $bad]);
}
$evilPdo->prepare('INSERT INTO snap_assets (id, asset_path) VALUES (1, ?)')
        ->execute(['../escaped.php']);
$evilPdo->prepare('INSERT INTO snap_settings VALUES (?, ?)')
        ->execute(['header_logo_url', '../escaped.php']);

$evilEngine = new SnapSmackRecovery($evilPdo, $evilSite);
ob_start();
$ri = $evilEngine->restoreImages($evilFlat);
$ra = $evilEngine->restoreMediaAssets($evilFlat);
$rb = $evilEngine->restoreBranding($evilFlat);
$evilProgress = (string)ob_get_clean();

km_check('every hostile image path is BLOCKED, none restored',
         ($ri['blocked'] ?? 0) === count($evilPaths) && ($ri['restored'] ?? 1) === 0,
         $failures);
km_check('and none is counted as merely missing, which would read as an absent file',
         ($ri['missing'] ?? 1) === 0, $failures);
km_check('a hostile asset path is BLOCKED',
         ($ra['blocked'] ?? 0) === 1 && ($ra['restored'] ?? 1) === 0, $failures);
km_check('a hostile branding path is BLOCKED',
         ($rb['blocked'] ?? 0) === 1 && ($rb['restored'] ?? 1) === 0, $failures);
km_check('each refusal says WHY, so the owner can see what the kit tried',
         str_contains($evilProgress, 'BLOCKED unsafe image path')
         && str_contains($evilProgress, 'BLOCKED unsafe asset path')
         && str_contains($evilProgress, 'BLOCKED unsafe branding path'), $failures);

// THE CHECK THAT MATTERS: the filesystem, whatever the counters say.
//
// SCOPED TO THIS FIXTURE, and that is a repair of my own. The first version
// probed $evil . '/../escaped.php', which resolves OUT of the fixture and into
// the shared temp directory -- where it found a PWNED file left by my own
// earlier reproduction and failed a run that had written nothing. A test that
// can be failed by debris from a previous run is the flake I spent this
// afternoon fixing in the maintenance-lock test, authored again an hour later.
//
// baseDir is $evil/site, so the escape target for '../escaped.php' is exactly
// $evil/escaped.php. That is the probe. Anything further out is not this
// fixture's business and cannot be attributed to this run.
$outside = [];
foreach (['/escaped.php', '/etc'] as $probe) {
    if (file_exists($evil . $probe)) $outside[] = $evil . $probe;
}
km_check('NOTHING was written outside the site root'
         . ($outside ? ' — FOUND: ' . implode(', ', $outside) : ''),
         $outside === [], $failures);

$inside = array_values(array_filter((array)scandir($evilSite),
    static fn (string $f): bool => $f !== '.' && $f !== '..'));
km_check('and nothing was written INSIDE it either, since every path was hostile'
         . ($inside ? ' — FOUND: ' . implode(', ', $inside) : ''),
         $inside === [], $failures);

// A LEGITIMATE path still works. Without this the stage would pass on a helper
// that blocked its own ordinary input.
$evilPdo->exec('DELETE FROM snap_images');
$evilPdo->prepare('INSERT INTO snap_images (id, img_file) VALUES (1, ?)')
        ->execute(['img_uploads/2026/logo.png']);
ob_start();
$good = $evilEngine->restoreImages($evilFlat);
ob_end_clean();
km_check('an ordinary path is still restored, so the guard refuses the hostile and not all',
         ($good['restored'] ?? 0) === 1 && ($good['blocked'] ?? 1) === 0, $failures);
km_check('and that file really is on disk, inside the site',
         is_file($evilSite . '/img_uploads/2026/logo.png'), $failures);

// CLEAN UP THE WHOLE TREE, not just the files this stage expected.
//
// My tests left 160 fixture directories and four files called escaped.php --
// one of them containing a PHP payload -- in the owner's temp directory in a
// single afternoon, because every one of them unlinked the files it knew about
// and never removed the directories. Sean's drive-hygiene rule is explicit
// about scattered folders, and a test that litters is a test that will one day
// fail on its own litter, which is exactly what happened above.
km_rmtree($evil);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 6 — the same five helpers the installer uses, read from source\n";
// ─────────────────────────────────────────────────────────────────────────────
// SS-1431 asks for the installer's path to be REUSED, not reimplemented. These
// read comment-stripped code, so the note explaining the reuse cannot satisfy
// the check that the reuse happened.
$engineSrc  = km_code_only((string)file_get_contents($root . '/core/recovery-engine.php'));
$installSrc = km_code_only((string)file_get_contents($root . '/install.php'));
$start = strpos($engineSrc, 'public function importRecoveryKit');
$body  = $start === false ? '' : substr($engineSrc, $start, 16000);
km_check('the import body was located', $body !== '', $failures);

foreach (['ensureDirectories', 'restoreImages', 'regenerateAndChecksum',
          'restoreMediaAssets', 'restoreBranding'] as $helper) {
    km_check("the kit import calls {$helper}(), as the installer does",
             str_contains($body, $helper . '(')
             && str_contains($installSrc, $helper . '('), $failures);
}
km_check('no second implementation of the image walk was written',
         substr_count($engineSrc, 'SELECT id, img_file FROM snap_images') <= 2, $failures);

// THE ORDERING THAT MATTERS: the media phase runs after the schema sync,
// because regenerateAndChecksum() writes columns an older kit's schema may
// not have until the sync adds them.
$syncAt  = strpos($body, 'snap_schema_sync(');
$mediaAt = strpos($body, 'restoreImages(');
km_check('the media phase runs AFTER the schema sync, not before',
         $syncAt !== false && $mediaAt !== false && $syncAt < $mediaAt, $failures);

// Clean up.
foreach ([$tmp, $tmp2] as $d) { km_rmtree($d); }

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1426 + SS-1431 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1426 + SS-1431 PASS — a kit import puts the photograph back on disk at\n";
echo "the path the restored row claims, counts what it restored and what is\n";
echo "missing, and keeps both counts when a host cannot make thumbnails.\n";
echo "NOT COVERED: no GD on this box, so a thumbnail is never actually\n";
echo "generated here -- the phase failing is asserted instead, because a\n";
echo "GD-less host is a real host. Branding and media-asset restoration are\n";
echo "exercised through the import but have no rows to act on in these\n";
echo "fixtures. Nothing rendered, installed or deployed.\n";
// ===== SNAPSMACK EOF =====
