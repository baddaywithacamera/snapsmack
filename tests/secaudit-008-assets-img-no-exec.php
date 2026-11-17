<?php
// SECAUDIT 008 (masthead-logo upload MIME bypass): assets/img must never execute
// a file placed there via an upload. The canonical guard is
// core/upload-execution-guard.php; this test proves the shipped assets/img/.htaccess
// is byte-identical to that guard (so it passes is_current() and never drifts).
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/upload-execution-guard.php';
$root = dirname(__DIR__);
$ht = $root . '/assets/img/.htaccess';
$fails = 0;
function chk(string $l, bool $ok): void { global $fails; if ($ok) echo "ok: $l\n"; else { $fails++; fwrite(STDERR, "FAIL: $l\n"); } }
chk('assets/img/.htaccess exists', is_file($ht));
chk('matches the canonical upload-execution guard (no drift)',
    is_file($ht) && hash_equals(hash('sha256', snapsmack_upload_execution_guard_content()), hash_file('sha256', $ht)));
chk('guard refuses script execution (SetHandler none + deny)',
    strpos(snapsmack_upload_execution_guard_content(), 'SetHandler none') !== false
    && strpos(snapsmack_upload_execution_guard_content(), 'Require all denied') !== false);
chk('guard strips php/phar handlers', strpos(snapsmack_upload_execution_guard_content(), 'RemoveHandler .php') !== false);
if ($fails) { fwrite(STDERR, "\n$fails assertion(s) FAILED\n"); exit(1); }
echo "\nsecaudit 008: all checks passed\n";
// ===== SNAPSMACK EOF =====
