<?php
/**
 * Two fleet findings from 2026-09-26 that must not come back:
 *
 * 1. HSTS reaches every response through the web server. CSP is selected by PHP
 *    because the explicit owner-code mode has a different, tested policy.
 * 2. SUYB backups (core/export-engine.php) copied only snap_ tables, so the
 *    PHOTOFRI.DAY challenge tables (pc_*) were never in any backup.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = realpath(__DIR__ . '/..');
$failures = [];

$hsts = 'Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"';
foreach (['core/htaccess-template', 'install.php'] as $f) {
    $src = file_get_contents("$root/$f");
    if (strpos($src, $hsts) === false) $failures[] = "$f: web-server rules do not send HSTS";
}
// CSP must be selected centrally so the owner-code switch has one precise effect.
$php = file_get_contents("$root/core/http-security-headers.php");
if (strpos($php, "Strict-Transport-Security: max-age=31536000; includeSubDomains") === false
    || strpos($php, 'snapsmack_public_csp(false)') === false) {
    $failures[] = 'central public security-header policy is not wired';
}

$export = file_get_contents("$root/core/export-engine.php");
if (strpos($export, "str_starts_with(\$row[0], 'pc_')") === false) {
    $failures[] = 'backup export leaves out the pc_ challenge tables';
}
$pc = file_get_contents("$root/core/photochallenge.php");
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?([a-z_]+)/', $pc, $m);
foreach ($m[1] as $table) {
    if (!str_starts_with($table, 'snap_') && !str_starts_with($table, 'pc_')) {
        $failures[] = "photochallenge creates $table, which the backup export does not copy";
    }
}

if ($failures) {
    fwrite(STDERR, "FAIL:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "PASS: security headers in web-server rules; challenge tables in backups\n";

// ===== SNAPSMACK EOF =====
