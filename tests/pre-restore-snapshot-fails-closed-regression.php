<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1425 — a restore that cannot take a copy first does not happen.
 *
 * WHAT THIS PINS, in the order it matters:
 *
 *   1. A failed snapshot REFUSES the restore. Not a warning above a restore
 *      that then runs: nothing is overwritten.
 *   2. And the PAGE SAYS SO. The PM's instruction was explicit -- "make sure
 *      the snapshot fail-closed is tested by rendering, not just by return
 *      value" -- because the two faults this morning were both "the guard
 *      passes when its output isn't checked". So this runs the real template
 *      out of smack-disaster.php and asserts the banner the owner sees.
 *   3. No DROP TABLE ran. The refusal returns before importSqlDump().
 *   4. No transaction was added. A transaction cannot protect this restore and
 *      a future one that looks protected is worse than the honest version;
 *      see snapshotBeforeRestore() for the DDL implicit-commit reason.
 *   5. The snapshot's name ends .sql, which is what the root .htaccess denies,
 *      and the backups directory gets its own deny file.
 *   6. Retention is the updater's: keep 3.
 *
 * HOW THE FAILURE IS PRODUCED, since it has to be real. The snapshot dumper
 * runs SHOW TABLES, which is MySQL and MariaDB only. On the plain sqlite
 * connection used above, that throws -- so the snapshot genuinely cannot be
 * written and the fail-closed path is the live one, not a mocked one.
 *
 * STAGE 4b then answers those two statements from sqlite_master and lets the
 * real dumper run, which is how the success path, the .sql name, the deny file
 * and the unredacted contents are checked against the file on disk rather than
 * against source text. The statement FORMS are translated there, so that stage
 * proves the dumper's logic and not its SQL; the SQL needs a MySQL box.
 */

$root = dirname(__DIR__);
require_once $root . '/core/constants.php';
require_once $root . '/core/recovery-engine.php';
require_once $root . '/core/db-dump.php';

$failures = [];

function check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

/**
 * Source with comments removed.
 *
 * Every source assertion in this file goes through this, because three times
 * this week a check of mine matched the COMMENT describing the thing it was
 * looking for rather than the thing. Tokeniser, not line-prefix stripping.
 */
function code_only(string $php): string {
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

echo "SS-1425 — a restore with no way back does not run\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — a kit that passes every earlier gate\n";
// ─────────────────────────────────────────────────────────────────────────────
// Nothing below means anything unless the restore gets as far as the snapshot.
// So: a real .tar.gz kit, a manifest shaped as core/export-engine.php writes
// one, and a settings table whose values match it.
$tmp = sys_get_temp_dir() . '/ss1425_' . bin2hex(random_bytes(6));
mkdir($tmp . '/kit', 0777, true);

$manifest = [
    'export_type'       => 'recovery-kit',
    'export_date'       => '2026-11-18T12:00:00+00:00',
    'snapsmack_version' => '0.7.500D',          // older than this build: allowed
    'site_name'         => 'Found Textures',
    'site_url'          => 'https://foundtextures.ca',
    'site_mode'         => 'photoblog',
    'site_uuid'         => 'uuid-aaa',
    'files'             => ['database.sql' => ['sha256' => 'x']],
];
file_put_contents($tmp . '/kit/manifest.json', json_encode($manifest));
// A dump that WOULD destroy the site if it ran. That is the point: the test
// asserts it did not.
file_put_contents($tmp . '/kit/database.sql',
    "DROP TABLE IF EXISTS `snap_settings`;\n"
    . "CREATE TABLE `snap_settings` (setting_key TEXT, setting_val TEXT);\n"
    . "INSERT INTO `snap_settings` (setting_key, setting_val) VALUES ('from_the_kit','yes');\n");

$archive = $tmp . '/kit.tar';
$phar = new PharData($archive);
$phar->buildFromDirectory($tmp . '/kit');
unset($phar);
check('a real kit archive was built, so the engine has something to open',
      is_file($archive) && filesize($archive) > 0, $failures);

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
foreach ([['installed_version', '0.7.999D'], ['site_mode', 'photoblog'],
          ['site_url', 'https://foundtextures.ca'], ['site_uuid', 'uuid-aaa']] as $row) {
    $pdo->prepare('INSERT INTO snap_settings VALUES (?, ?)')->execute($row);
}
$pdo->exec("INSERT INTO snap_settings VALUES ('canary', 'still here')");

$e = new SnapSmackRecovery($pdo, $tmp . '/site');
check('the kit passes the preflight, so the snapshot is what stops it',
      $e->preflightKit($manifest, [
          'installed_version' => '0.7.999D', 'site_mode' => 'photoblog',
          'site_url' => 'https://foundtextures.ca', 'site_uuid' => 'uuid-aaa',
      ])['refusals'] === [], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — the snapshot cannot be taken, so the restore does not happen\n";
// ─────────────────────────────────────────────────────────────────────────────
ob_start();                                   // the engine streams progress
$result = $e->importRecoveryKit($archive);
$progress = (string)ob_get_clean();

check('the import reports an error rather than succeeding',
      !empty($result['errors']), $failures);
check('and the error says a copy could not be taken',
      str_contains(implode(' ', $result['errors']), 'pre-restore copy'), $failures);
check('and it says why that stops the restore, in words an owner can act on',
      str_contains(implode(' ', $result['errors']), 'with no way back'), $failures);
check('the result is marked REFUSED, not half-done',
      ($result['refused'] ?? null) === true, $failures);
check('no SQL statement was imported',
      ($result['sql_imported'] ?? -1) === 0, $failures);
check('the progress stream told the owner nothing was changed',
      str_contains($progress, 'NOTHING WAS CHANGED'), $failures);

// THE DATABASE ITSELF. The kit's dump would have dropped snap_settings and
// replaced it. Reading the live table is the only check that cannot be
// satisfied by a return value.
$canary = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='canary'")
              ->fetchColumn();
check('the live database is untouched: the canary row is still there',
      $canary === 'still here', $failures);
$fromKit = $pdo->query("SELECT COUNT(*) FROM snap_settings WHERE setting_key='from_the_kit'")
                ->fetchColumn();
check("and nothing from the kit's dump reached it",
      (int)$fromKit === 0, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — WHAT THE PAGE PRINTS (the PM's 'test by rendering')\n";
// ─────────────────────────────────────────────────────────────────────────────
// A return value nobody renders is how SS-1427 and SS-1430 both happened. This
// takes the real result above, pushes it through the page's own boundary and
// the page's own template, and asserts on the banner the owner reads.
$page = (string)file_get_contents($root . '/smack-disaster.php');

$tpl_start = strpos($page, '<?php if (isset($import_result)');
$template  = '';
if ($tpl_start !== false) {
    preg_match_all('/<\?php\s+(if|endif)\b/', $page, $tags,
                   PREG_OFFSET_CAPTURE | PREG_PATTERN_ORDER, $tpl_start);
    $depth = 0;
    foreach ($tags[1] as $i => $tag) {
        if ($tag[0] === 'if') { $depth++; continue; }
        $depth--;
        if ($depth === 0) {
            $close = strpos($page, '?' . '>', $tags[0][$i][1]);
            if ($close !== false) $template = substr($page, $tpl_start, $close + 2 - $tpl_start);
            break;
        }
    }
}
check('the result template was extracted whole from the page',
      $template !== '' && substr_count($template, '<?php if (') === substr_count($template, 'endif;'),
      $failures);

// The page's own boundary lines, read out of the page rather than retyped.
$boundary = [];
foreach (explode("\n", $page) as $line) {
    if (str_contains($line, "\$import_result['success'] =")
        || str_contains($line, "\$import_result['refused'] = !empty")) {
        $boundary[] = trim($line);
    }
}
check('both boundary lines were found in the page',
      count($boundary) === 2, $failures);

$render = static function (array $engineResult) use ($template, $boundary): string {
    $import_result = $engineResult;
    foreach ($boundary as $line) { eval($line); }
    ob_start();
    try {
        eval('?' . '>' . $template);
    } catch (Throwable $ex) {
        ob_end_clean();
        return 'RENDER THREW: ' . $ex->getMessage();
    }
    return (string)ob_get_clean();
};

$REFUSED = '> NOTHING WAS RESTORED — THIS SITE IS UNCHANGED';
$SUCCESS = '> RECOVERY KIT IMPORTED SUCCESSFULLY';
$ERRORS  = '> RECOVERY KIT IMPORT COMPLETED WITH ERRORS';
foreach (['refused' => $REFUSED, 'success' => $SUCCESS, 'errors' => $ERRORS] as $n => $b) {
    check("the {$n} banner line exists in the template, so its absence means something",
          str_contains($template, $b), $failures);
}

$out = $render($result);
check('the render happened, so the checks below are reading real output',
      !str_starts_with($out, 'RENDER THREW') && trim($out) !== '', $failures);
check('the page tells the owner THIS SITE IS UNCHANGED',
      str_contains($out, $REFUSED), $failures);
check('the page does NOT say the kit imported successfully',
      !str_contains($out, $SUCCESS), $failures);
check('the page does NOT say the import completed with errors, which would mean '
      . 'the database had been touched',
      !str_contains($out, $ERRORS), $failures);
check('and it prints the reason, so the owner knows what to fix',
      str_contains($out, 'pre-restore copy') || str_contains($out, 'no way back'),
      $failures);

// And the other way round: a half-done import must NOT claim to be a refusal.
$half = $result;
$half['refused']      = false;
$half['sql_imported'] = 40;
$half['errors']       = ['SQL: table snap_images failed'];
$out = $render($half);
check('a half-done import prints the errors banner instead',
      str_contains($out, $ERRORS) && !str_contains($out, $REFUSED), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — the directory, the name, and the deny file\n";
// ─────────────────────────────────────────────────────────────────────────────
$dir = $tmp . '/backups';
$made = snap_backup_dir_secure($dir);
check('the backups directory is created when it is missing',
      $made === $dir && is_dir($dir), $failures);
check('a deny-all .htaccess is written into it', is_file($dir . '/.htaccess'), $failures);
$ht = (string)file_get_contents($dir . '/.htaccess');
check('covering Apache 2.4', str_contains($ht, 'Require all denied'), $failures);
check('and Apache 2.2', str_contains($ht, 'Deny from all'), $failures);
check('and an index.html so a directory listing shows nothing',
      is_file($dir . '/index.html'), $failures);

// An existing deny file is not overwritten: an owner may have hardened it.
file_put_contents($dir . '/.htaccess', "# mine\nRequire all denied\n");
snap_backup_dir_secure($dir);
check("an owner's own .htaccess is left alone",
      str_contains((string)file_get_contents($dir . '/.htaccess'), '# mine'), $failures);

$engineSrc = code_only((string)file_get_contents($root . '/core/recovery-engine.php'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4b — A RESTORE THAT CAN TAKE A COPY, end to end\n";
// ─────────────────────────────────────────────────────────────────────────────
// Three mutations survived the first version of this file -- the snapshot
// renamed .bak, the deny file dropped, and 'refused' never cleared -- because
// everything above stops at the fail-closed path and the rest was source greps
// that a rename walks straight past.
//
// SHOW TABLES and SHOW CREATE TABLE are the only MySQL-isms in the dumper, so
// this answers those two from sqlite and lets the real dumper run on the real
// connection. Nothing else is stubbed: the file on disk below was written by
// snap_dump_database(), and the restore that follows it is the real one.
//
// STILL NOT A MYSQL TEST, and I am not going to call it one: the statement
// forms are translated, so this proves the dumper's logic and not its SQL.
class Ss1425DumpablePdo extends PDO {
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (stripos($query, 'SHOW TABLES') === 0) {
            return parent::query("SELECT name FROM sqlite_master WHERE type='table' "
                . "AND name NOT LIKE 'sqlite_%'");
        }
        if (stripos($query, 'SHOW CREATE TABLE') === 0) {
            $t = trim(substr($query, strlen('SHOW CREATE TABLE')), " `\t\n");
            $sql = parent::query("SELECT sql FROM sqlite_master WHERE name="
                . parent::quote($t))->fetchColumn();
            return parent::query('SELECT ' . parent::quote($t) . ' AS a, '
                . parent::quote((string)$sql) . ' AS b');
        }
        return parent::query($query);
    }
}

$pdo2 = new Ss1425DumpablePdo('sqlite::memory:', null, null,
                              [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo2->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
foreach ([['installed_version', '0.7.999D'], ['site_mode', 'photoblog'],
          ['site_url', 'https://foundtextures.ca'], ['site_uuid', 'uuid-aaa'],
          // An unredacted secret. A snapshot that loses this is not a rollback.
          ['fleet_node_key', 'SECRET-NODE-KEY-9f3a']] as $row) {
    $pdo2->prepare('INSERT INTO snap_settings VALUES (?, ?)')->execute($row);
}

$site2 = $tmp . '/site2';
mkdir($site2, 0777, true);
$e2 = new SnapSmackRecovery($pdo2, $site2);

ob_start();
$ok = $e2->importRecoveryKit($archive);
$progress2 = (string)ob_get_clean();

$snapFile = $ok['snapshot']['file'] ?? null;
check('the snapshot was written', is_string($snapFile) && is_file($snapFile), $failures);
check('its name ends .sql, which is the extension the root .htaccess denies',
      is_string($snapFile) && str_ends_with($snapFile, '.sql'), $failures);
check('and begins pre-restore_, beside the updater pre-update_ dumps',
      is_string($snapFile) && str_starts_with(basename($snapFile), 'pre-restore_'), $failures);
check('a deny-all .htaccess sits in the directory it landed in',
      is_string($snapFile) && is_file(dirname($snapFile) . '/.htaccess'), $failures);
check('the reported size matches the file on disk',
      is_string($snapFile) && ($ok['snapshot']['bytes'] ?? 0) === filesize($snapFile), $failures);

$snapBody = is_string($snapFile) ? (string)file_get_contents($snapFile) : '';
check('the snapshot carries the site data, not an empty shell',
      str_contains($snapBody, 'snap_settings') && str_contains($snapBody, 'INSERT INTO'),
      $failures);
check('and it is UNREDACTED: the secret is in it, because this file never leaves the box',
      str_contains($snapBody, 'SECRET-NODE-KEY-9f3a'), $failures);
check('the owner is told where the copy is',
      str_contains($progress2, basename((string)$snapFile)), $failures);

check('the restore then ran: SQL was imported', ($ok['sql_imported'] ?? 0) > 0, $failures);
check('and the result is NOT marked refused, because the site WAS changed',
      ($ok['refused'] ?? null) === false, $failures);
$out = $render($ok);
check('so the page does not tell the owner the site is unchanged',
      !str_contains($out, $REFUSED), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — retention is the updater's policy, keep 3\n";
// ─────────────────────────────────────────────────────────────────────────────
$prune = $tmp . '/prune';
mkdir($prune, 0777, true);
for ($i = 1; $i <= 6; $i++) {
    $f = $prune . '/pre-restore_0.7.999D_2026-11-1' . $i . '_00-00-00.sql';
    file_put_contents($f, 'x');
    touch($f, 1700000000 + $i * 60);
}
file_put_contents($prune . '/pre-update_0.7.999D_keepme.sql', 'x');
$removed = snap_prune_dumps($prune, 'pre-restore_', 3);
check('three of six snapshots are deleted', $removed === 3, $failures);
check('and the three newest are the ones kept',
      count(glob($prune . '/pre-restore_*.sql') ?: []) === 3
      && is_file($prune . '/pre-restore_0.7.999D_2026-11-16_00-00-00.sql')
      && !is_file($prune . '/pre-restore_0.7.999D_2026-11-11_00-00-00.sql'), $failures);
check("the updater's own pre-update dumps are NOT touched by this prune",
      is_file($prune . '/pre-update_0.7.999D_keepme.sql'), $failures);
check('pruning a directory with three or fewer does nothing',
      snap_prune_dumps($prune, 'pre-restore_', 3) === 0, $failures);

$updaterSrc = code_only((string)file_get_contents($root . '/core/updater.php'));
check("the number 3 is the updater's, not one invented here",
      str_contains($updaterSrc, 'function updater_prune_backups(int $keep = 3)'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 6 — no transaction, deliberately, and the reason is recorded\n";
// ─────────────────────────────────────────────────────────────────────────────
// The obvious fix for this ticket is a transaction around the import. It would
// roll back nothing -- the kit's dump carries DDL, and DDL implicitly commits
// in MySQL and MariaDB -- and a restore that LOOKS protected is worse than one
// that is honestly unprotected. These assertions are read from comment-stripped
// code, so the note explaining the absence cannot satisfy them.
$importStart = strpos($engineSrc, 'public function importRecoveryKit');
$importBody  = $importStart === false ? '' : substr($engineSrc, $importStart, 9000);
check('the import body was located', $importBody !== '', $failures);
check('no beginTransaction around the restore', !str_contains($importBody, 'beginTransaction'),
      $failures);
check('and no rollBack pretending to undo it', !str_contains($importBody, 'rollBack'),
      $failures);
// The reason must be WRITTEN DOWN, or the next person adds one. This is the one
// assertion in this file that is meant to match a comment, and it reads the raw
// source on purpose.
$engineRaw = (string)file_get_contents($root . '/core/recovery-engine.php');
check('the reason a transaction is absent is recorded in the file',
      str_contains($engineRaw, 'IMPLICIT COMMIT'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 7 — the snapshot is UNREDACTED, because it never leaves the box\n";
// ─────────────────────────────────────────────────────────────────────────────
// core/export-engine.php redacts secrets out of a kit's dump, correctly: a kit
// is handed to somebody. A snapshot exists so the owner can get back to where
// they were, and a rollback that loses the site's own fleet keys is not a
// rollback. So the snapshot dumper must NOT call the redactor.
$dumpSrc = code_only((string)file_get_contents($root . '/core/db-dump.php'));
check('the snapshot dumper does not redact', !str_contains($dumpSrc, 'redactSecrets'),
      $failures);
check('it streams rows in batches rather than holding a table in memory',
      str_contains($dumpSrc, 'LIMIT') && str_contains($dumpSrc, 'OFFSET'), $failures);
check('a dump that fails part way is deleted, not left looking usable',
      str_contains($dumpSrc, '@unlink($destFile)'), $failures);

$err = '';
$bytes = snap_dump_database($pdo, $tmp . '/nope.sql', 'test', $err);
check('on a driver that cannot be dumped it reports failure rather than half a file',
      $bytes === false && $err !== '', $failures);
check('and leaves no file behind to be mistaken for a backup',
      !is_file($tmp . '/nope.sql'), $failures);

// Clean up.
// CLEAN THE WHOLE TREE. This listed the directories it knew about and missed
// the ones STAGE 4b creates (site2, and the snapshot's own backups folder
// under it), so every run left a fixture behind. Between this file and its
// neighbours that came to 160 directories in the owner's temp folder in one
// afternoon -- against an explicit drive-hygiene rule -- and one of them
// failed a later run on its own litter.
$prs_rmtree = static function (string $dir) use (&$prs_rmtree): void {
    $tmpReal = realpath(sys_get_temp_dir());
    $real    = realpath($dir);
    if ($tmpReal === false || $real === false) return;
    if (strncmp($real, $tmpReal, strlen($tmpReal)) !== 0 || $real === $tmpReal) return;
    foreach (new RecursiveIteratorIterator(
                 new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
                 RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($real);
};
$prs_rmtree($tmp);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1425 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1425 PASS — a restore that cannot copy the database first refuses, the\n";
echo "page says the site is unchanged, and the live data is still there.\n";
echo "NOT COVERED, stated rather than implied: STAGE 4b answers SHOW TABLES and\n";
echo "SHOW CREATE TABLE out of sqlite_master, so the dumper's LOGIC runs on a real\n";
echo "connection and its MySQL STATEMENT FORMS do not. Those need a MySQL box.\n";
echo "One mutation survives on purpose: deleting the empty-file guard in\n";
echo "snap_dump_database() changes nothing observable, because a dump that got\n";
echo "that far always has content.\n";
// ===== SNAPSMACK EOF =====
