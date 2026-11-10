<?php
// SECAUDIT 008 (masthead-logo upload MIME bypass): assets/img must never execute
// a file placed there via an upload. Assert the hardening .htaccess is present.
declare(strict_types=1);
$root = dirname(__DIR__);
$ht = $root . '/assets/img/.htaccess';
$fails = 0;
function chk(string $l, bool $ok): void { global $fails; if ($ok) echo "ok: $l\n"; else { $fails++; fwrite(STDERR, "FAIL: $l\n"); } }
chk('assets/img/.htaccess exists', is_file($ht));
$c = is_file($ht) ? (string) file_get_contents($ht) : '';
chk('disables the PHP engine', stripos($c, 'php_flag engine off') !== false || stripos($c, 'php_admin_flag engine off') !== false);
chk('strips script handlers (RemoveHandler)', stripos($c, 'RemoveHandler') !== false);
chk('serves php as text/plain', stripos($c, 'AddType text/plain') !== false);
chk('denies known executable extensions', (bool) preg_match('/FilesMatch[^>]*php/i', $c));
if ($fails) { fwrite(STDERR, "\n$fails assertion(s) FAILED\n"); exit(1); }
echo "\nsecaudit 008: all checks passed\n";
// ===== SNAPSMACK EOF =====
