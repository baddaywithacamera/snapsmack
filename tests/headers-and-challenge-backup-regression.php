<?php
/**
 * Two fleet findings from 2026-09-26 that must not come back:
 *
 * 1. HSTS + CSP reached only some sites because only PHP sent them, and that PHP
 *    step was skipped on many responses. The web-server rules now send them too,
 *    in both the template the updater re-applies and the installer's copy.
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
$csp  = 'Header always set Content-Security-Policy "object-src \'none\'; base-uri \'self\'; frame-ancestors \'self\'"';
foreach (['core/htaccess-template', 'install.php'] as $f) {
    $src = file_get_contents("$root/$f");
    if (strpos($src, $hsts) === false) $failures[] = "$f: web-server rules do not send HSTS";
    if (strpos($src, $csp)  === false) $failures[] = "$f: web-server rules do not send CSP";
}
// The web-server values must match what PHP sends, or a site gets two different policies.
$php = file_get_contents("$root/core/http-security-headers.php");
if (strpos($php, "Strict-Transport-Security: max-age=31536000; includeSubDomains") === false
    || strpos($php, "Content-Security-Policy: object-src 'none'; base-uri 'self'; frame-ancestors 'self'") === false) {
    $failures[] = 'PHP and web-server security header values have drifted apart';
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
