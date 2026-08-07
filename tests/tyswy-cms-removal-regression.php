<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * TAKE YOUR SHIT WITH YOU — CMS removal regression (spec section 4).
 *
 * Spec section 4 has one rule that is easy to break by accident and impossible
 * to notice afterwards:
 *
 *     Removal and desktop replacement MUST ship together. There must be no
 *     release where the owner loses the ability to export portable content.
 *
 * So this suite asserts BOTH halves in the same pass. Removing the old cards is
 * only correct while the replacement is present; a future commit that deletes,
 * renames or breaks the desktop tool has to fail here rather than quietly
 * leaving a release with no portable export at all.
 *
 * It also asserts what must NOT have been removed. exportWordPressWXR() and
 * exportPortableJSON() lived in the same class as the recovery kit and the SQL
 * dumps, and those are RECOVERY — a different job that stays on the server.
 */

$root = dirname(__DIR__);
$failures = [];
$checks   = 0;

function r_ok(bool $ok, string $msg): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $msg;
}

$backup = file_get_contents($root . '/smack-backup.php');
$engine = file_get_contents($root . '/core/export-engine.php');
$help   = file_get_contents($root . '/smack-help.php');

// ── The methods are gone ────────────────────────────────────────────────────
r_ok(!preg_match('/function\s+exportWordPressWXR\s*\(/i', $engine),
     'SnapSmackExport::exportWordPressWXR() is back — it builds a whole-site '
     . 'export in PHP memory (spec section 4 removed it)');
r_ok(!preg_match('/function\s+exportPortableJSON\s*\(/i', $engine),
     'SnapSmackExport::exportPortableJSON() is back — same problem');

// ── No caller anywhere in the shipped tree ──────────────────────────────────
$callers = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (substr($path, -4) !== '.php') continue;
    // Release staging, worktrees and archived copies of the codebase are not
    // the shipped tree and are deliberately not policed here.
    foreach (['/smack-central/', '/.claude/', '/_continuity/', '/_spec/',
              '/node_modules/', '/vendor/', '/tests/'] as $skip) {
        if (strpos($path, $skip) !== false) continue 2;
    }
    $src = file_get_contents($path);
    if (preg_match('/->\s*export(WordPressWXR|PortableJSON)\s*\(/', $src)) {
        $callers[] = substr($path, strlen($root) + 1);
    }
}
r_ok(empty($callers),
     'something still calls the removed portable exporters: ' . implode(', ', $callers));

// ── The POST handlers are gone from the backup page ─────────────────────────
r_ok(!preg_match('/\$type\s*===\s*[\'"]wxr[\'"]/', $backup),
     "smack-backup.php still handles the 'wxr' export type");
r_ok(!preg_match('/\$type\s*===\s*[\'"]json_export[\'"]/', $backup),
     "smack-backup.php still handles the 'json_export' export type");
r_ok(!preg_match('/name="type"\s+value="wxr"/', $backup),
     'the WORDPRESS WXR card is still on the Backup page');
r_ok(!preg_match('/name="type"\s+value="json_export"/', $backup),
     'the PORTABLE JSON card is still on the Backup page');

// ── What must NOT have been removed ─────────────────────────────────────────
// Recovery is a different job from portability and stays on the server.
foreach (['exportRecoveryKit', 'exportInventory', 'generateSqlDump',
          'streamSqlDump', 'redactSecrets'] as $keep) {
    r_ok((bool)preg_match('/function\s+' . $keep . '\s*\(/i', $engine),
         "SnapSmackExport::{$keep}() was removed — that is RECOVERY, not "
         . 'portability, and it must stay');
}
foreach (['value="full"', 'value="schema"', 'value="source"', 'smack-verify.php',
          'smack-ftp.php'] as $keep) {
    r_ok(strpos($backup, $keep) !== false,
         "the Backup page lost a recovery control ({$keep})");
}

// ── The replacement ships in the SAME release ───────────────────────────────
// This is the half that matters. Without it, this file is just a suite that
// congratulates a release for having no portable export.
r_ok(is_file($root . '/core/tyswy-api.php'),
     'core/tyswy-api.php is missing — the portable-export API is the replacement '
     . 'for what was removed, and it has to be here');

$api = file_get_contents($root . '/api.php');
r_ok(strpos($api, "strpos(\$route, 'tyswy') === 0") !== false,
     'api.php no longer routes the tyswy export API');

$keys = file_get_contents($root . '/smack-api-keys.php');
r_ok(strpos($keys, "'tyswy'") !== false,
     'a tyswy export key can no longer be minted, so the desktop tool cannot '
     . 'authenticate and nothing can export portable content');

foreach (['main.py', 'export_engine.py', 'tyswy_client.py', 'portable_archive.py',
          'wordpress_adapter.py', 'export_state.py',
          'schema/snapsmack-portable-v1.schema.json'] as $part) {
    r_ok(is_file($root . '/tools/take-your-shit-with-you/' . $part),
         "the desktop replacement is missing {$part} — removal and replacement "
         . 'must ship together (spec section 4)');
}

// ── The Backup page points somewhere ────────────────────────────────────────
r_ok(stripos($backup, 'TAKE YOUR SHIT WITH YOU') !== false,
     'the Backup page does not mention the replacement, so an owner looking for '
     . 'the export buttons finds nothing where they used to be');
r_ok(strpos($backup, 'smack-api-keys.php') !== false,
     'the Backup page does not say where to get an export key');
r_ok(strpos($backup, 'smack-tools.php') !== false,
     'the Backup page does not link anywhere the owner can actually get the tool');

$toolspage = file_get_contents($root . '/smack-tools.php');
r_ok(stripos($toolspage, 'Take Your Shit With You') !== false,
     'the Companion Tools page does not list the portable-export tool, so the '
     . 'Backup page points at a page that does not mention it');
// A dead download button on the page that guarantees an owner can leave is the
// worst place in the product for one, so the registry can mark a tool unreleased.
r_ok(strpos($toolspage, "\$tool['available'] ?? true") !== false,
     'the Companion Tools page no longer honours the availability flag — an '
     . 'unreleased tool would show a download button that 404s');

// ── Help text no longer claims the CMS makes these files ────────────────────
r_ok(!preg_match('/<strong>WXR Export<\/strong>/', $help),
     'help still advertises a WXR Export control that no longer exists');
r_ok(!preg_match('/<strong>JSON Export<\/strong>/', $help),
     'help still advertises a JSON Export control that no longer exists');
r_ok(stripos($help, 'TAKE YOUR SHIT WITH YOU') !== false,
     'help does not tell the owner where portable export went');
// Standing instruction: never send an owner to phpMyAdmin to fix something.
// Deliberately NOT a blanket search for the word — the security section
// legitimately lists /phpmyadmin as a path bots probe, and a test that cannot
// tell those two apart would eventually be silenced rather than fixed.
r_ok(!preg_match('/(restore|use|using|open|go to)[^.<]{0,40}phpmyadmin/i', $help),
     'help sends the owner to phpMyAdmin — use the migration runner or the '
     . 'Recovery Kit importer instead');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: TYSWY CMS removal regression suite ({$checks} checks)\n";
// ===== SNAPSMACK EOF =====
