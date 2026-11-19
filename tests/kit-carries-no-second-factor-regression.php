<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * SS-1433 — a Recovery Kit is not a second factor, and an old one cannot
 * revive a credential you replaced.
 *
 * TWO DEFECTS, and the second is the one the ticket is really about:
 *
 *   1. snap_users was dumped verbatim, so every kit carried the TOTP seed, the
 *      TOTP recovery codes and the account recovery-code hash. A kit is a file
 *      made to be copied elsewhere and kept for years. A year-old kit still
 *      generates valid TOTP codes today.
 *   2. Restoring an OLDER kit silently REVIVED credentials revoked since it was
 *      taken. The owner rotates a recovery code after a scare; the kit predates
 *      that; the restore puts the old one back and nothing says so.
 *
 * (1) is fixed on the export side and (2) on the restore side, and NEITHER IS
 * SUFFICIENT ALONE. The export change cannot reach a kit somebody made last
 * March, and those kits are the whole problem — so the restore revokes
 * unconditionally, without looking for a redaction marker.
 *
 * PASSWORD HASHES SURVIVE, deliberately. The export engine's own note is
 * right: a kit that restores a site nobody can log into is not a backup.
 * force_password_change is set instead.
 *
 * AND totp_enabled IS CLEARED WITH THE SECRET. A row with totp_enabled = 1 and
 * no secret is an account demanding a factor nothing can satisfy: the owner
 * locked out of their own site by a security fix. That case is tested.
 */

$root = dirname(__DIR__);
require_once $root . '/core/constants.php';
require_once $root . '/core/recovery-engine.php';

$failures = [];

function sf_check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

function sf_code_only(string $php): string {
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

final class SfDumpablePdo extends PDO {
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

echo "SS-1433 — a kit is not a second factor\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — AN OLD KIT, carrying the credentials the owner has since replaced\n";
// ─────────────────────────────────────────────────────────────────────────────
// This is defect (2), and it is the one an export change cannot touch. The
// dump below is what a kit made before any of this work looks like: real TOTP
// seed, real recovery hash, 2FA on.
$tmp  = sys_get_temp_dir() . '/ss1433_' . bin2hex(random_bytes(6));
$site = $tmp . '/site';
$kit  = $tmp . '/kit';
mkdir($site, 0777, true);
mkdir($kit, 0777, true);

$manifest = [
    'export_type' => 'recovery-kit', 'export_date' => '2026-03-01T09:00:00+00:00',
    'snapsmack_version' => '0.0.1D', 'site_name' => 'Found Textures',
    'site_url' => 'https://foundtextures.ca', 'site_mode' => 'photoblog',
    'site_uuid' => 'uuid-aaa', 'files' => ['database.sql' => ['sha256' => 'x']],
];
file_put_contents($kit . '/manifest.json', json_encode($manifest));

$oldSeed = 'JBSWY3DPEHPK3PXP';
$oldRecovery = '$2y$10$oldrecoveryhasholdrecoveryhasholdrecoveryhasholdrecover';
$passwordHash = '$2y$10$realpasswordhashrealpasswordhashrealpasswordhashrealp';
file_put_contents($kit . '/database.sql',
    "DROP TABLE IF EXISTS `snap_users`;\n"
    . "CREATE TABLE `snap_users` (id INTEGER PRIMARY KEY, username TEXT, "
    . "password_hash TEXT, totp_secret TEXT, totp_enabled INTEGER, "
    . "totp_recovery_json TEXT, recovery_code_hash TEXT, "
    . "force_password_change INTEGER);\n"
    . "INSERT INTO `snap_users` (id, username, password_hash, totp_secret, totp_enabled, "
    . "totp_recovery_json, recovery_code_hash, force_password_change) VALUES "
    . "(1, 'sean', '{$passwordHash}', '{$oldSeed}', 1, "
    . "'[\"old-code-1\",\"old-code-2\"]', '{$oldRecovery}', 0);\n");

$archive = $tmp . '/kit.tar';
$phar = new PharData($archive);
$phar->buildFromDirectory($kit);
unset($phar);

$pdo = new SfDumpablePdo('sqlite::memory:', null, null,
                         [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
foreach ([['installed_version', (string)SNAPSMACK_VERSION_SHORT], ['site_mode', 'photoblog'],
          ['site_url', 'https://foundtextures.ca'], ['site_uuid', 'uuid-aaa']] as $row) {
    $pdo->prepare('INSERT INTO snap_settings VALUES (?, ?)')->execute($row);
}

sf_check('the kit carries a real TOTP seed, as every kit made so far does',
         str_contains((string)file_get_contents($kit . '/database.sql'), $oldSeed), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — after the import, READ THE DATABASE BACK\n";
// ─────────────────────────────────────────────────────────────────────────────
ob_start();
$result = (new SnapSmackRecovery($pdo, $site))->importRecoveryKit($archive);
$progress = (string)ob_get_clean();

sf_check('the import ran the SQL', ($result['sql_imported'] ?? 0) > 0, $failures);

$user = $pdo->query('SELECT * FROM snap_users WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: [];
sf_check('the account row came back', ($user['username'] ?? '') === 'sean', $failures);

// THE CHECKS THIS TICKET EXISTS FOR. Read out of the live table, not a return
// value: the kit's own dump put these values in, and they must be gone.
// REVOKED MEANS THE KEY IS THERE AND HOLDS NOTHING, and the helper exists
// because my first version of these three checks used `?? $oldSeed`. The
// column comes back as NULL, `??` fires on NULL as well as on a missing key,
// so the fallback supplied the old seed and the comparison said the seed was
// still present. A CORRECT result read as a failure -- and the same mistake
// the other way round would have hidden a real one.
$blank = static function (array $row, string $col): bool {
    if (!array_key_exists($col, $row)) return false;   // column gone is not proof
    return $row[$col] === null || $row[$col] === '';
};
sf_check('THE OLD TOTP SEED IS GONE from the restored account',
         $blank($user, 'totp_secret'), $failures);
sf_check('and specifically is not still the value the kit carried',
         ($user['totp_secret'] ?? null) !== $oldSeed, $failures);
sf_check('the TOTP recovery codes are gone',
         $blank($user, 'totp_recovery_json'), $failures);
sf_check('the old recovery-code hash is gone',
         $blank($user, 'recovery_code_hash')
         && ($user['recovery_code_hash'] ?? null) !== $oldRecovery, $failures);

// AND NOT A LOCKOUT.
sf_check('2FA is switched OFF, so the account does not demand a factor it cannot satisfy',
         (int)($user['totp_enabled'] ?? 1) === 0, $failures);
sf_check('and a password change is forced, so the owner must set a new one',
         (int)($user['force_password_change'] ?? 0) === 1, $failures);

// AND THE OWNER CAN STILL GET IN.
sf_check('THE PASSWORD HASH SURVIVES: a kit that locks the owner out is not a backup',
         ($user['password_hash'] ?? '') === $passwordHash, $failures);

// AND THEY ARE TOLD.
sf_check('the progress stream says the second factors were revoked',
         str_contains($progress, 'Second factors revoked'), $failures);
sf_check('the result warns the owner in words they can act on',
         str_contains(implode(' ', (array)($result['warnings'] ?? [])), 're-enrol'), $failures);
sf_check('and says passwords still work for the first sign-in',
         str_contains(implode(' ', (array)($result['warnings'] ?? [])),
                      'Passwords still work'), $failures);
sf_check('and the revocation is recorded in the result',
         !empty($result['second_factors_revoked']), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — the EXPORT side no longer puts them in a kit at all\n";
// ─────────────────────────────────────────────────────────────────────────────
// redactSecrets() is private, so it is exercised through reflection rather
// than by asserting its source reads a certain way.
require_once $root . '/core/export-engine.php';
$exportClass = null;
foreach (['SnapSmackExport', 'SnapSmackExporter', 'SnapExport'] as $c) {
    if (class_exists($c)) { $exportClass = $c; break; }
}
sf_check('the export engine class was located: ' . ($exportClass ?? 'NONE'),
         $exportClass !== null, $failures);

if ($exportClass !== null) {
    $rc = new ReflectionClass($exportClass);
    if ($rc->hasMethod('redactSecrets')) {
        $m = $rc->getMethod('redactSecrets');
        $inst = $rc->newInstanceWithoutConstructor();
        $out = $m->invoke($inst, 'snap_users', [
            'id' => 1, 'username' => 'sean', 'password_hash' => $passwordHash,
            'totp_secret' => $oldSeed, 'totp_enabled' => 1,
            'totp_recovery_json' => '["a","b"]', 'recovery_code_hash' => $oldRecovery,
        ]);
        sf_check('the exported row carries NO TOTP seed',
                 ($out['totp_secret'] ?? 'x') === '', $failures);
        sf_check('no TOTP recovery codes', ($out['totp_recovery_json'] ?? 'x') === '', $failures);
        sf_check('no recovery-code hash', ($out['recovery_code_hash'] ?? 'x') === '', $failures);
        sf_check('2FA off in the dump, so the kit cannot restore a lockout',
                 (int)($out['totp_enabled'] ?? 1) === 0, $failures);
        sf_check('and the PASSWORD HASH IS UNTOUCHED',
                 ($out['password_hash'] ?? '') === $passwordHash, $failures);
        sf_check('EMPTY, not a marker: a placeholder would be restored verbatim (SS-1417)',
                 !str_contains(strtoupper(implode('|', array_map('strval', $out))), 'REDACTED'),
                 $failures);

        // Other tables must be unaffected by this change.
        $node = $m->invoke($inst, 'snap_multisite_nodes',
                           ['api_key_local' => 'live-key', 'totp_secret' => 'x']);
        sf_check('the node-key redaction still works (SS-1412 unbroken)',
                 ($node['api_key_local'] ?? 'x') === '', $failures);
    } else {
        sf_check('redactSecrets() exists on the export engine', false, $failures);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — the restore side does NOT depend on the export being fixed\n";
// ─────────────────────────────────────────────────────────────────────────────
// The point of doing both. If the restore only cleared marker values it would
// protect nobody holding a kit made before today, and those are every kit that
// exists. Read from comment-stripped code so the note explaining it cannot
// satisfy the check.
$engineSrc = sf_code_only((string)file_get_contents($root . '/core/recovery-engine.php'));
$start = strpos($engineSrc, 'private function revokeRestoredSecondFactors');
$body  = $start === false ? '' : substr($engineSrc, $start, 2000);
sf_check('the revocation method was located', $body !== '', $failures);
sf_check('it does NOT look for a redaction marker',
         $body !== '' && !str_contains($body, 'REDACTED'), $failures);
sf_check('it clears the three credential columns',
         $body !== '' && str_contains($body, 'totp_secret')
         && str_contains($body, 'totp_recovery_json')
         && str_contains($body, 'recovery_code_hash'), $failures);
sf_check('it does NOT touch password_hash',
         $body !== '' && !str_contains($body, 'password_hash'), $failures);
// THE CALL SITE, not the name. My first version searched for
// 'revokeRestoredSecondFactors()' and found the DEFINITION, which sits above
// the schema sync in the file -- so the ordering check failed on correct code.
// Third time this week a check of mine has matched a declaration instead of a
// use; searching for '$this->' is the difference.
sf_check('and it runs AFTER the schema sync, which may add those very columns',
         ($p1 = strpos($engineSrc, 'snap_schema_sync(')) !== false
         && ($p2 = strpos($engineSrc, '$this->revokeRestoredSecondFactors()')) !== false
         && $p1 < $p2, $failures);

// A failure to revoke must be LOUD: live second factors are back on the site.
sf_check('a failure to revoke is reported as an error, not a warning',
         str_contains($engineSrc, "Could not revoke the second factors"), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "
STAGE 5 — the owner is TOLD, including about the kits they already have
";
// ─────────────────────────────────────────────────────────────────────────────
// The ticket says decide which credential material may exist in a kit. A
// decision nobody can read is not a decision. The help text used to say a kit
// contains "user credentials", which is now wrong in exactly the way that
// matters, and it said nothing about the kits already sitting in Sean's
// backups -- which DO carry second factors and always will.
$help = (string)file_get_contents($root . '/smack-help.php');
sf_check('the help no longer claims a kit carries user credentials',
         !str_contains($help, 'settings, user credentials'), $failures);
sf_check('it says passwords ARE included, and why',
         str_contains($help, 'Account passwords are included'), $failures);
sf_check('it says second factors are NOT',
         str_contains($help, 'Second factors') && str_contains($help, 'are <em>not</em>'),
         $failures);
sf_check('it warns that OLDER kits still contain them',
         str_contains($help, 'DO contain those second factors'), $failures);
sf_check('and tells the owner what to do after an import',
         str_contains($help, 'set up two-factor authentication again'), $failures);
sf_check('and that their password still works for the first sign-in',
         str_contains($help, 'still works for that first'), $failures);

// CLEAN THE WHOLE TREE, and do it before the failure block so a FAILING run
// does not litter either -- the earlier version unlinked one directory's files
// and left the rest, which is how these tests put 160 fixtures in the owner's
// temp folder in an afternoon.
$sf_rmtree = static function (string $dir): void {
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
$sf_rmtree($tmp);
@unlink($archive);

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1433 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1433 PASS — a kit no longer carries a second factor, an older kit\n";
echo "cannot revive one, the owner can still sign in, and they are told to\n";
echo "re-enrol.\n";
echo "NOT COVERED: whether a TOTP app would accept the revoked seed is not\n";
echo "testable here and does not need to be -- the seed is gone from the row.\n";
echo "The admin screens that ask for 2FA re-enrolment are not exercised.\n";
// ===== SNAPSMACK EOF =====
