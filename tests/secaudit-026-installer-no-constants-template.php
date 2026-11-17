<?php
// SECAUDIT 026 (installer constants breach): install.php must carry NO hand-built
// core/constants.php template. Two drifted templates ($constants_php, $const_php)
// false-breached fresh installs on first login; both are removed. The shipped
// package file is canonical.
declare(strict_types=1);
$root = dirname(__DIR__);
$src = (string) file_get_contents($root . '/install.php');
$fails = 0;
function chk(string $l, bool $ok): void { global $fails; if ($ok) echo "ok: $l\n"; else { $fails++; fwrite(STDERR, "FAIL: $l\n"); } }
chk('no $constants_php template variable', strpos($src, '$constants_php') === false);
chk('no $const_php template variable', strpos($src, '$const_php') === false);
chk('no hardcoded codename in a generated template', strpos($src, 'Shit and Git') === false);
chk('installer does not GENERATE core/constants.php header comment', strpos($src, '--- GENERATE core/constants.php ---') === false);
chk('the canonical-file rule is documented (SECAUDIT 026)', strpos($src, 'SECAUDIT 026') !== false);
if ($fails) { fwrite(STDERR, "\n$fails assertion(s) FAILED\n"); exit(1); }
echo "\nsecaudit 026: all checks passed\n";
// ===== SNAPSMACK EOF =====
