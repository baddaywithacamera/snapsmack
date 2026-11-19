<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1427 — a restore that left files behind did not succeed.
 *
 * THE FAULT. The page decided success as empty($errors), and a missing
 * photograph is not an error. So a kit that brought the database back and left
 * four hundred photographs behind printed RECOVERY KIT IMPORTED SUCCESSFULLY
 * with "Missing files: 400" underneath it. The ticket: "Success must require
 * the full declared recovery result, and partial completion must name what
 * remains missing."
 *
 * THE PART THAT IS NOT OBVIOUS, and the reason this is a fourth outcome rather
 * than folding missing files into the failure branch: A PARTIAL RECOVERY IS
 * NORMAL AND IS NOT A FAILURE. The ordinary Recovery Kit inventories media
 * rather than carrying it, so on a rebuilt site "the database is back, your
 * photographs come from your own backup" is the expected result, not a fault.
 * Printing COMPLETED WITH ERRORS over it would be a lie in the other
 * direction, and would teach the owner that the word error means nothing --
 * which is exactly how 470 end-of-file failures trained everybody to scroll
 * past that check (SS-1288).
 *
 * So there are four outcomes, and this file renders all four from the page's
 * own template: refused, complete, partial, failed.
 */

$root = dirname(__DIR__);
$failures = [];

function pr_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

function pr_code_only(string $php): string {
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

echo "SS-1427 — a partial restore is neither a success nor a failure\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the page's own template and its own boundary\n";
// ─────────────────────────────────────────────────────────────────────────────
// Extracted from the file rather than retyped, for the reason STAGE 4c of the
// SS-1430 regression records: a copy of a condition tests the copy.
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
pr_check('the result template was extracted whole',
         $template !== '' && substr_count($template, '<?php if (') === substr_count($template, 'endif;'),
         $failures);

$boundary = [];
foreach (explode("\n", $page) as $line) {
    if (str_contains($line, "\$import_result['success'] =")
        || str_contains($line, "\$import_result['refused'] = !empty")) {
        $boundary[] = trim($line);
    }
}
pr_check('both boundary lines were found', count($boundary) === 2, $failures);

$render = static function (array $engineResult) use ($template, $boundary): string {
    $import_result = $engineResult;
    foreach ($boundary as $line) { eval($line); }
    ob_start();
    try {
        eval('?' . '>' . $template);
    } catch (Throwable $e) {
        ob_end_clean();
        return 'RENDER THREW: ' . $e->getMessage();
    }
    return (string)ob_get_clean();
};

$SUCCESS = '> RECOVERY KIT IMPORTED SUCCESSFULLY';
$PARTIAL = '> RECOVERY KIT IMPORTED — SOME FILES ARE NOT HERE YET';
$ERRORS  = '> RECOVERY KIT IMPORT COMPLETED WITH ERRORS';
$REFUSED = '> NOTHING WAS RESTORED — THIS SITE IS UNCHANGED';
foreach (['success' => $SUCCESS, 'partial' => $PARTIAL,
          'errors' => $ERRORS, 'refused' => $REFUSED] as $n => $b) {
    pr_check("the {$n} banner exists in the template, so its absence means something",
             str_contains($template, $b), $failures);
}

$probe = $render(['complete' => true, 'refused' => false, 'errors' => []]);
pr_check('the harness renders, so the negative checks below mean something',
         !str_starts_with($probe, 'RENDER THREW') && trim($probe) !== '', $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — THE FAULT: the old shape must no longer print success\n";
// ─────────────────────────────────────────────────────────────────────────────
// Exactly what SS-1427 describes: no errors, database restored, four hundred
// photographs absent. This is the shape that printed the success banner.
$partialResult = [
    'refused' => false, 'errors' => [], 'complete' => false,
    'sql_imported' => 412, 'files_restored' => 0, 'missing' => 400,
    'not_bundled' => 400, 'checksum_ok' => 0, 'checksum_fail' => 0,
    'remaining' => ['400 file(s) the database expects are not on disk and were '
                    . 'not in the kit.'],
];
$out = $render($partialResult);
pr_check('a restore that left 400 photographs behind does NOT print success',
         !str_contains($out, $SUCCESS), $failures);
pr_check('it prints its own banner: SOME FILES ARE NOT HERE YET',
         str_contains($out, $PARTIAL), $failures);
pr_check('it does NOT print COMPLETED WITH ERRORS, because nothing failed',
         !str_contains($out, $ERRORS), $failures);
pr_check('it does NOT say the site is unchanged, because the database WAS restored',
         !str_contains($out, $REFUSED), $failures);
pr_check('it says plainly that nothing failed',
         str_contains($out, 'Nothing failed'), $failures);
pr_check('and it NAMES what remains, rather than leaving four counters to read',
         str_contains($out, 'STILL TO DO') && str_contains($out, '400 file(s)'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — the other three outcomes still render as themselves\n";
// ─────────────────────────────────────────────────────────────────────────────
$out = $render(['refused' => false, 'errors' => [], 'complete' => true,
                'sql_imported' => 412, 'files_restored' => 88, 'checksum_ok' => 88]);
pr_check('a COMPLETE restore prints the success banner',
         str_contains($out, $SUCCESS) && !str_contains($out, $PARTIAL), $failures);
pr_check('and its counts', str_contains($out, '412'), $failures);

$out = $render(['refused' => false, 'complete' => false,
                'errors' => ['SQL: table snap_images failed'],
                'sql_imported' => 40, 'files_restored' => 0]);
pr_check('a FAILED restore still prints the errors banner',
         str_contains($out, $ERRORS) && !str_contains($out, $PARTIAL), $failures);
pr_check('and names the failure', str_contains($out, 'snap_images'), $failures);

$out = $render(['refused' => true, 'complete' => false,
                'errors' => ['NOTHING WAS RESTORED — step-up failed']]);
pr_check('a REFUSAL still prints the unchanged-site banner',
         str_contains($out, $REFUSED) && !str_contains($out, $PARTIAL), $failures);

// Errors AND outstanding files: the failure banner wins, and the outstanding
// list is still printed, because the owner needs both.
$out = $render(['refused' => false, 'complete' => false,
                'errors' => ['SQL: one statement failed'],
                'missing' => 12, 'remaining' => ['12 file(s) are not on disk']]);
pr_check('errors AND missing files: the errors banner wins',
         str_contains($out, $ERRORS) && !str_contains($out, $PARTIAL), $failures);
pr_check('and the outstanding list is still shown underneath it',
         str_contains($out, 'STILL TO DO'), $failures);

// FAIL CLOSED. A shape nobody planned for is not a success and not a refusal.
$out = $render(['sql_imported' => 0]);
pr_check('an unrecognised shape prints neither success nor refused',
         !str_contains($out, $SUCCESS) && !str_contains($out, $REFUSED), $failures);
$out = $render(['complete' => 'yes', 'errors' => [], 'refused' => false]);
pr_check('a truthy-but-not-true completeness claim is not a success',
         !str_contains($out, $SUCCESS), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — the ENGINE decides completeness, through a real import\n";
// ─────────────────────────────────────────────────────────────────────────────
// Not a hand-built array: a real kit through the real importRecoveryKit(),
// with one row pointing at a photograph the kit does not carry. The engine
// must come back incomplete and say what is outstanding.
require_once $root . '/core/constants.php';
require_once $root . '/core/recovery-engine.php';

final class PrDumpablePdo extends PDO {
    public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false {
        if (stripos($q, 'SHOW TABLES') === 0) {
            return parent::query("SELECT name FROM sqlite_master WHERE type='table' "
                . "AND name NOT LIKE 'sqlite_%'");
        }
        if (stripos($q, 'SHOW CREATE TABLE') === 0) {
            $t = trim(substr($q, strlen('SHOW CREATE TABLE')), " `\t\n");
            $sql = parent::query('SELECT sql FROM sqlite_master WHERE name='
                . parent::quote($t))->fetchColumn();
            return parent::query('SELECT ' . parent::quote($t) . ' AS a, '
                . parent::quote((string)$sql) . ' AS b');
        }
        return parent::query($q);
    }
}

$tmp  = sys_get_temp_dir() . '/ss1427_' . bin2hex(random_bytes(6));
$site = $tmp . '/site';
$kit  = $tmp . '/kit';
mkdir($site, 0777, true);
mkdir($kit, 0777, true);

$manifest = [
    'export_type' => 'recovery-kit', 'export_date' => '2026-11-19T10:00:00+00:00',
    'snapsmack_version' => '0.0.1D', 'site_name' => 'Found Textures',
    'site_url' => 'https://foundtextures.ca', 'site_mode' => 'photoblog',
    'site_uuid' => 'uuid-aaa', 'files' => ['database.sql' => ['sha256' => 'x']],
];
file_put_contents($kit . '/manifest.json', json_encode($manifest));
file_put_contents($kit . '/database.sql',
    "DROP TABLE IF EXISTS `snap_images`;\n"
    . "CREATE TABLE `snap_images` (id INTEGER PRIMARY KEY, img_file TEXT, "
    . "img_thumb_square TEXT, img_thumb_aspect TEXT, img_checksum TEXT);\n"
    . "INSERT INTO `snap_images` (id, img_file) VALUES (1, 'img_uploads/absent.jpg');\n");
$archive = $tmp . '/kit.tar';
$phar = new PharData($archive);
$phar->buildFromDirectory($kit);
unset($phar);

$pdo = new PrDumpablePdo('sqlite::memory:', null, null,
                         [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
foreach ([['installed_version', (string)SNAPSMACK_VERSION_SHORT], ['site_mode', 'photoblog'],
          ['site_url', 'https://foundtextures.ca'], ['site_uuid', 'uuid-aaa']] as $row) {
    $pdo->prepare('INSERT INTO snap_settings VALUES (?, ?)')->execute($row);
}

ob_start();
$real = (new SnapSmackRecovery($pdo, $site))->importRecoveryKit($archive);
$progress = (string)ob_get_clean();

pr_check('the real import ran the SQL', ($real['sql_imported'] ?? 0) > 0, $failures);
pr_check('and the engine reports it INCOMPLETE, not successful',
         ($real['complete'] ?? null) === false, $failures);
// ONE ERROR IS EXPECTED HERE AND IT IS THIS BOX, NOT THE PRODUCT.
// snap_schema_sync() runs a query containing DATABASE(), which is MySQL-only,
// so on the sqlite connection these fixtures use the sync always fails and the
// engine records that -- correctly, as an error. It would not happen on a real
// site. So the errors-free partial case is proven by rendering in STAGE 2, and
// what this end-to-end case proves is that nothing ELSE failed: the import ran,
// the media was reconciled, and the only complaint is the one this box causes.
$realErrors = (array)($real['errors'] ?? []);
$otherErrors = array_values(array_filter($realErrors,
    static fn (string $e): bool => !str_contains($e, 'Schema sync failed')));
pr_check('the only error is the sqlite-only schema sync, so nothing else failed: '
         . ($otherErrors ? implode(' | ', $otherErrors) : 'none'),
         $otherErrors === [], $failures);
pr_check('and it names what is outstanding',
         is_array($real['remaining'] ?? null) && $real['remaining'] !== [], $failures);
pr_check('in words an owner can act on, not counter names',
         str_contains(implode(' ', (array)($real['remaining'] ?? [])), 'backup'), $failures);
// The stream line only fires when there are no errors, and this box always
// has the schema one, so what is asserted is that the media phase reported
// the missing file. The no-error stream line is pinned from source below.
pr_check('the stream names the missing file',
         str_contains($progress, 'absent.jpg'), $failures);

// Rendered through the page. With the sqlite-only schema error present the
// errors banner correctly wins, so what matters here is that it does NOT claim
// success and DOES name the outstanding file. Strip that one error and the
// same result renders the partial banner, which is the product behaviour.
$out = $render($real);
pr_check('THE REAL RESULT does not claim success',
         !str_contains($out, $SUCCESS), $failures);
pr_check('and names the outstanding file either way',
         str_contains($out, 'STILL TO DO'), $failures);
$withoutBoxError = $real;
$withoutBoxError['errors'] = $otherErrors;
$out = $render($withoutBoxError);
pr_check("without this box's own schema error it renders the PARTIAL banner",
         str_contains($out, $PARTIAL) && !str_contains($out, $SUCCESS)
         && !str_contains($out, $ERRORS), $failures);

// A complete one, for contrast: no image rows at all, so nothing is outstanding.
$kit2 = $tmp . '/kit2';
mkdir($kit2, 0777, true);
file_put_contents($kit2 . '/manifest.json', json_encode($manifest));
file_put_contents($kit2 . '/database.sql',
    "DROP TABLE IF EXISTS `snap_images`;\n"
    . "CREATE TABLE `snap_images` (id INTEGER PRIMARY KEY, img_file TEXT, "
    . "img_thumb_square TEXT, img_thumb_aspect TEXT, img_checksum TEXT);\n");
$archive2 = $tmp . '/kit2.tar';
$phar2 = new PharData($archive2);
$phar2->buildFromDirectory($kit2);
unset($phar2);
$pdo2 = new PrDumpablePdo('sqlite::memory:', null, null,
                          [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo2->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
foreach ([['installed_version', (string)SNAPSMACK_VERSION_SHORT], ['site_mode', 'photoblog'],
          ['site_url', 'https://foundtextures.ca'], ['site_uuid', 'uuid-aaa']] as $row) {
    $pdo2->prepare('INSERT INTO snap_settings VALUES (?, ?)')->execute($row);
}
ob_start();
$clean = (new SnapSmackRecovery($pdo2, $tmp . '/site2'))->importRecoveryKit($archive2);
ob_end_clean();
// Same caveat: the schema sync cannot run here, so `complete` can never be
// true end to end on sqlite. What IS provable is that a kit with nothing
// outstanding produces an EMPTY remaining list -- the half of completeness
// this ticket adds -- and that with the box's own error removed it is
// complete and renders success.
pr_check('a kit with nothing outstanding has an EMPTY outstanding list',
         ($clean['remaining'] ?? ['x']) === [], $failures);
$cleanNoBoxError = $clean;
$cleanNoBoxError['errors'] = array_values(array_filter((array)($clean['errors'] ?? []),
    static fn (string $e): bool => !str_contains($e, 'Schema sync failed')));
$cleanNoBoxError['complete'] = $cleanNoBoxError['errors'] === []
    && ($clean['remaining'] ?? ['x']) === [];
pr_check("and with this box's own schema error removed it is complete",
         $cleanNoBoxError['complete'] === true, $failures);
pr_check('and renders the success banner',
         str_contains($render($cleanNoBoxError), $SUCCESS), $failures);

// THE ORDINARY KIT: media INVENTORIED, not carried. This is the not_bundled
// path, and it is the normal shape of a real Recovery Kit -- so it is the
// shape most owners will meet. A mutation that stopped counting not_bundled
// as outstanding survived an earlier version of this file, because every
// fixture here bundled everything and nothing exercised it. The ordinary case
// was the untested one.
$kit3 = $tmp . '/kit3';
mkdir($kit3, 0777, true);
$man3 = $manifest;
$man3['files']['assets/img/logo.png'] = [
    'restores_to' => 'assets/img/logo.png',
    'bundled'     => false,          // inventoried only: comes from your backup
];
file_put_contents($kit3 . '/manifest.json', json_encode($man3));
file_put_contents($kit3 . '/database.sql',
    "DROP TABLE IF EXISTS `snap_images`;
"
    . "CREATE TABLE `snap_images` (id INTEGER PRIMARY KEY, img_file TEXT, "
    . "img_thumb_square TEXT, img_thumb_aspect TEXT, img_checksum TEXT);
");
$archive3 = $tmp . '/kit3.tar';
$phar3 = new PharData($archive3);
$phar3->buildFromDirectory($kit3);
unset($phar3);
$pdo3 = new PrDumpablePdo('sqlite::memory:', null, null,
                          [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo3->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
foreach ([['installed_version', (string)SNAPSMACK_VERSION_SHORT], ['site_mode', 'photoblog'],
          ['site_url', 'https://foundtextures.ca'], ['site_uuid', 'uuid-aaa']] as $row) {
    $pdo3->prepare('INSERT INTO snap_settings VALUES (?, ?)')->execute($row);
}
ob_start();
$inv = (new SnapSmackRecovery($pdo3, $tmp . '/site3'))->importRecoveryKit($archive3);
ob_end_clean();

pr_check('an inventoried-only file is counted as not bundled',
         ($inv['not_bundled'] ?? 0) === 1, $failures);
pr_check('and the engine lists it as outstanding',
         str_contains(implode(' ', (array)($inv['remaining'] ?? [])), 'not carried inside it'),
         $failures);
pr_check('so the restore is NOT reported complete',
         ($inv['complete'] ?? null) === false, $failures);
$invNoBoxError = $inv;
$invNoBoxError['errors'] = array_values(array_filter((array)($inv['errors'] ?? []),
    static fn (string $e): bool => !str_contains($e, 'Schema sync failed')));
$out = $render($invNoBoxError);
pr_check('the ordinary kit renders the PARTIAL banner, not success and not errors',
         str_contains($out, $PARTIAL) && !str_contains($out, $SUCCESS)
         && !str_contains($out, $ERRORS), $failures);
pr_check('and says the inventoried file is normal for a kit rather than a fault',
         str_contains($out, 'normal for a kit'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — completeness is the ENGINE's, not re-derived by the page\n";
// ─────────────────────────────────────────────────────────────────────────────
$engineSrc = pr_code_only((string)file_get_contents($root . '/core/recovery-engine.php'));
$pageSrc   = pr_code_only($page);
pr_check('the engine sets complete from errors AND the outstanding list',
         str_contains($engineSrc, "\$result['complete']  = empty(\$result['errors']) && \$remaining === []"),
         $failures);
pr_check('the page takes completeness from the engine rather than recomputing it',
         str_contains($pageSrc, "(\$import_result['complete'] ?? false) === true"), $failures);
pr_check('and the page does not count missing files itself',
         !str_contains($pageSrc, "\$import_result['missing'] >") , $failures);
pr_check('the engine tells the owner, in the stream, when files are outstanding '
         . 'and nothing failed',
         str_contains($engineSrc, 'Some files are not here yet'), $failures);

foreach ([$tmp] as $d) {
    foreach (['/kit', '/kit2', '/site', '/site2'] as $sub) {
        foreach (glob($d . $sub . '/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
    }
    foreach (glob($d . '/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
}

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1427 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1427 PASS — a restore that left files behind prints its own banner,\n";
echo "names what is outstanding, claims no success, and is not called a\n";
echo "failure either. All four outcomes rendered from the page's own template.\n";
echo "NOT COVERED: no browser, so how the four banners LOOK is unverified.\n";
echo "Nothing rendered in a skin, installed or deployed.\n";
// ===== SNAPSMACK EOF =====
