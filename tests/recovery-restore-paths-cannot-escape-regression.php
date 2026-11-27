<?php
declare(strict_types=1);

/**
 * SECAUDIT 047 — the FOUR restore paths that had no containment check at all.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 *
 * The audit finding, and the branch this was split out of, are both about the
 * manifest phase: a containment check that failed open. That one is covered by
 * kit-manifest-cannot-escape-the-site-regression.php.
 *
 * THIS FILE IS ABOUT THE WORSE HALF, measured on dev before anything was
 * changed. Four other functions took a path straight out of the database and
 * joined it onto the site directory with NO check of any kind -- no "..", no
 * absolute path, no NUL byte, no containment, no extension test -- and then
 * ran mkdir -p and copy:
 *
 *   restoreImages()         img_file from snap_images
 *   restoreMediaAssets()    asset_path from snap_assets
 *   restoreBranding()       three URL settings from snap_settings
 *   regenerateAndChecksum() img_file again, to build a thumbs directory
 *                           beside the photograph and write two files in it
 *
 * ON THE ADMIN KIT-IMPORT PATH THOSE ROWS COME FROM THE KIT'S OWN SQL DUMP, so
 * every one of those values is attacker controlled. A guard on the manifest
 * phase alone would have left all four open.
 *
 * WHY THE LAST ONE MATTERS EVEN AFTER THE FIRST IS FIXED, and it is the reason
 * it is guarded here rather than left for a later build: blocking a path in
 * restoreImages() does not remove the row. The unsafe img_file is still in
 * snap_images afterwards, and the thumbnail regenerator would then build a
 * directory beside it and write into it. Fixing the restore and not the
 * regenerator leaves the escape reachable by a different door.
 *
 * NOTHING HERE WRITES OUTSIDE ITS OWN TEMPORARY DIRECTORY, and the assertion
 * that matters is the one that looks on disk afterwards: a count of "blocked"
 * is what the code says it did, and the file system is what it actually did.
 */

$root   = dirname(__DIR__);
$checks = 0;
$fails  = [];
$check  = static function (string $what, bool $ok) use (&$checks, &$fails): void {
    $checks++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $what . "\n";
    if (!$ok) { $fails[] = $what; }
};

require_once $root . '/core/constants.php';
require_once $root . '/core/recovery-engine.php';

// ---------------------------------------------------------------------------
// A real temporary site, and a sibling the escapes aim at.
// ---------------------------------------------------------------------------
$tmp     = sys_get_temp_dir() . '/ss-sec047-' . bin2hex(random_bytes(6));
$siteDir = $tmp . '/site';
$outside = $tmp . '/outside';
$flatDir = $tmp . '/flat';
foreach ([$siteDir, $outside, $flatDir] as $d) { mkdir($d, 0777, true); }
file_put_contents($flatDir . '/proof.jpg', 'JPEGDATA');
file_put_contents($flatDir . '/logo.png', 'PNGDATA');

$cleanup = static function (string $dir) use (&$cleanup): void {
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        $p = $dir . '/' . $e;
        if (is_dir($p) && !is_link($p)) { $cleanup($p); } else { @unlink($p); }
    }
    @rmdir($dir);
};

// Every file anywhere under a directory, so "did anything land outside" is
// answered by looking rather than by trusting a return value.
$filesUnder = static function (string $dir) use (&$filesUnder): array {
    $out = [];
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        $p = $dir . '/' . $e;
        if (is_dir($p) && !is_link($p)) { $out = array_merge($out, $filesUnder($p)); }
        else { $out[] = $p; }
    }
    return $out;
};

try {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE snap_images (id INTEGER PRIMARY KEY, img_file TEXT,
        img_thumb_square TEXT, img_thumb_aspect TEXT, img_checksum TEXT)');
    $pdo->exec('CREATE TABLE snap_assets (id INTEGER PRIMARY KEY, asset_path TEXT, asset_checksum TEXT)');
    $pdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');

    // THE HOSTILE VALUES. Each is a different way out, and the last one is the
    // one the string tests cannot see on their own.
    $hostile = [
        '../outside/proof.jpg'                  => 'parent-directory traversal',
        '../../outside/proof.jpg'               => 'two levels up',
        'a/../../outside/proof.jpg'             => 'traversal buried mid-path',
        '/etc/snapsmack-proof.jpg'              => 'absolute path',
        'uploads/shell.php'                     => 'executable extension',
        'uploads/shell.php.jpg'                 => 'double extension',
        '.htaccess'                             => 'dotfile',
    ];

    echo "\nSTAGE 1 — restoreImages(): img_file out of the kit's own dump\n";
    $i = 0;
    foreach (array_keys($hostile) as $bad) { $pdo->exec("INSERT INTO snap_images (id, img_file) VALUES (" . (++$i) . ", '" . $bad . "')"); }
    $pdo->exec("INSERT INTO snap_images (id, img_file) VALUES (100, 'uploads/2026/proof.jpg')");

    $before = $filesUnder($outside);
    $r = (new SnapSmackRecovery($pdo, $siteDir))->restoreImages($flatDir);
    $check('every hostile img_file was blocked, and the count is reported',
        ($r['blocked'] ?? -1) === count($hostile));
    $check('the one legitimate photograph still restored', ($r['restored'] ?? 0) === 1);
    $check('and it landed INSIDE the site, at its stated depth',
        is_file($siteDir . '/uploads/2026/proof.jpg'));
    $check('NOTHING was written outside the site', $filesUnder($outside) === $before);
    $check('and nothing landed at the temporary root either',
        !is_file($tmp . '/proof.jpg') && !is_file($tmp . '/outside/proof.jpg'));

    echo "\nSTAGE 2 — restoreMediaAssets(): asset_path, the same dump\n";
    $i = 0;
    foreach (array_keys($hostile) as $bad) { $pdo->exec("INSERT INTO snap_assets (id, asset_path) VALUES (" . (++$i) . ", '" . $bad . "')"); }
    $pdo->exec("INSERT INTO snap_assets (id, asset_path) VALUES (100, 'assets/logo.png')");
    $before = $filesUnder($outside);
    $a = (new SnapSmackRecovery($pdo, $siteDir))->restoreMediaAssets($flatDir);
    $check('every hostile asset_path was blocked and counted',
        ($a['blocked'] ?? -1) === count($hostile));
    $check('the legitimate asset restored, inside the site',
        ($a['restored'] ?? 0) === 1 && is_file($siteDir . '/assets/logo.png'));
    $check('NOTHING was written outside the site', $filesUnder($outside) === $before);

    echo "\nSTAGE 3 — restoreBranding(): three settings, equally kit-controlled\n";
    // ltrim('/') in the function makes an absolute path LOOK relative. It was
    // never a containment check and this proves it is not treated as one.
    $pdo->exec("INSERT INTO snap_settings VALUES ('header_logo_url', '/../outside/logo.png')");
    $pdo->exec("INSERT INTO snap_settings VALUES ('favicon_url', '../../outside/logo.png')");
    $pdo->exec("INSERT INTO snap_settings VALUES ('site_logo', 'branding/logo.png')");
    $before = $filesUnder($outside);
    $b = (new SnapSmackRecovery($pdo, $siteDir))->restoreBranding($flatDir);
    $check('both hostile branding paths were blocked and counted',
        ($b['blocked'] ?? -1) === 2);
    $check('the legitimate logo restored, inside the site',
        ($b['restored'] ?? 0) === 1 && is_file($siteDir . '/branding/logo.png'));
    $check('NOTHING was written outside the site', $filesUnder($outside) === $before);
    $check('and a leading slash was not quietly turned into a safe path',
        !is_file($outside . '/logo.png'));

    echo "\nSTAGE 4 — regenerateAndChecksum(): the door that stays open otherwise\n";
    // The hostile row is STILL in snap_images: a blocked restore does not
    // delete it. Give the escape target a real file so the function would
    // proceed, and prove it refuses before building a thumbs directory there.
    mkdir($outside . '/thumbtarget', 0777, true);
    file_put_contents($outside . '/thumbtarget/real.jpg', 'JPEGDATA');
    $pdo->exec("INSERT INTO snap_images (id, img_file) VALUES (200, '../outside/thumbtarget/real.jpg')");
    $before = $filesUnder($outside);
    $g = (new SnapSmackRecovery($pdo, $siteDir))->regenerateAndChecksum();
    $check('the regenerator ran and returned a result', is_array($g));
    $check('NO thumbs directory was created outside the site',
        !is_dir($outside . '/thumbtarget/thumbs'));
    $check('and not one file was written outside the site',
        $filesUnder($outside) === $before);

    echo "\nSTAGE 5 — one implementation, and the fail-open form is gone\n";
    $src = (string) @file_get_contents($root . '/core/recovery-engine.php');
    $check('core/recovery-engine.php is readable', $src !== '');
    // Comments are stripped before searching, so the comment that DOCUMENTS the
    // old form cannot satisfy or break a check about the live code.
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    $code = (string) preg_replace('#^\s*//.*$#m', '', $code);
    $check('the shared containment walk exists',
        strpos($code, 'function pathStaysInsideBase') !== false);
    $check('THE FAIL-OPEN FORM IS GONE FROM THE LIVE CODE',
        strpos($code, 'realpath(dirname($targetPath))') === false);
    $check('and no live code reads a $parentReal any more',
        strpos($code, '$parentReal') === false);
    // Five call sites, counted. A guard that stops being called is the fault
    // returning, and it would not show up in any behaviour above if a future
    // edit removed one of the four I added.
    $callers = preg_match_all('/\$this->(?:safeRelativeTarget|pathStaysInsideBase)\(/', $code);
    $check('all five guarded paths still call the shared check (found '
        . $callers . ', expecting 6 including the helper\'s own call)', $callers === 6);
} finally {
    $cleanup($tmp);
}

echo "\n";
if ($fails) {
    echo 'FAILED (' . count($fails) . " of {$checks}):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    fwrite(STDERR, "\nSECAUDIT 047: a kit-controlled path can still be written outside the site.\n");
    exit(1);
}
echo "SECAUDIT 047 PASS — the four unguarded restore paths refuse a kit-controlled\n"
   . "path that leaves the site, report what they refused, and still restore an\n"
   . "ordinary file ({$checks} checks).\n";
echo "NOT COVERED: symbolic links. symlink() needs a privilege this box does not\n"
   . "have, so the escapes here are string-based and the link cases live in\n"
   . "kit-manifest-cannot-escape-the-site-regression.php, which uses a Windows\n"
   . "junction. MySQL is not exercised; none of this is SQL-dependent. Nothing\n"
   . "was installed, rendered or deployed.\n";

// ===== SNAPSMACK EOF =====
