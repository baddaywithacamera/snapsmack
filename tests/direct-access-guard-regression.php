<?php
/**
 * SECAUDIT 050-B — the five sensitive core includes refuse a direct HTTP request.
 *
 * The fix was written on 2026-08-20 (22d26860) but stranded on an unmerged branch
 * while the public disclosure said it had shipped. This test makes it impossible
 * for the guard to go missing from dev again: it serves the tree with PHP's own
 * web server (which honours no .htaccess, like nginx) and requests each file.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root  = realpath(__DIR__ . '/..');
$files = ['updater', 'release-pubkey', 'skin-registry', 'manifest-inventory', 'auth-smack'];
$failures = [];

// 1. Every file carries the guard in its first lines.
foreach ($files as $f) {
    $head = implode('', array_slice(file("$root/core/$f.php"), 0, 12));
    if (strpos($head, "realpath(\$_SERVER['SCRIPT_FILENAME']) === @realpath(__FILE__)") === false) {
        $failures[] = "core/$f.php has no direct-access guard";
    }
}

// 2. The self-healed release-pubkey.php the updater writes keeps the guard.
$updater = file_get_contents("$root/core/updater.php");
if (strpos($updater, "a self-healed copy keeps the direct-access guard") === false) {
    $failures[] = 'updater self-heal writes release-pubkey.php without the guard';
}

// 3. The Apache block list names the real auth include, not the dead names.
$ht = file_get_contents("$root/core/htaccess-template");
if (!preg_match('/<FilesMatch "\^\(db\|auth-smack\|constants\|release-pubkey\|updater\|skin-registry\|manifest-inventory\)\\\\\.php\$">/', $ht)) {
    $failures[] = 'htaccess-template core FilesMatch list is not the reconciled list';
}

// 4. Live: no web-server rules at all, a direct request gets 404 and no body.
$port = 18000 + random_int(0, 999);
$php  = PHP_BINARY;
$cmd  = sprintf('"%s" -S 127.0.0.1:%d -t "%s"', $php, $port, $root);
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
if (!is_resource($proc)) {
    $failures[] = 'could not start the PHP test server';
} else {
    usleep(700000);
    foreach ($files as $f) {
        $ctx  = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $body = @file_get_contents("http://127.0.0.1:$port/core/$f.php", false, $ctx);
        $code = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
        }
        if ($code !== 404) $failures[] = "direct request to core/$f.php returned $code, expected 404";
        if (trim((string)$body) !== '') $failures[] = "direct request to core/$f.php returned a body";
    }
    proc_terminate($proc);
    proc_close($proc);
}

if ($failures) {
    fwrite(STDERR, "FAIL:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "PASS: SECAUDIT 050-B direct-access guards (5 files, no web-server rules)\n";

// ===== SNAPSMACK EOF =====
