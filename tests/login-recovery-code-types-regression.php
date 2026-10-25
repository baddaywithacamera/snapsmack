<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/auth-recovery.php';

$accountCode = 'SNAP-ABCD-EFGH-JKLM';
$totpCode = '12ABCDEF-34567890';
$user = [
    'id' => 1,
    'username' => 'sean',
    'recovery_code_hash' => password_hash($accountCode, PASSWORD_DEFAULT),
    'totp_recovery_json' => json_encode([password_hash($totpCode, PASSWORD_DEFAULT)]),
];

$account = snapsmack_match_recovery_code($user, strtolower($accountCode));
if (!$account || ($account['_snapsmack_recovery_kind'] ?? '') !== 'account') throw new RuntimeException('Account recovery code was rejected.');

$totp = snapsmack_match_recovery_code($user, strtolower($totpCode));
if (!$totp || ($totp['_snapsmack_recovery_kind'] ?? '') !== 'totp') throw new RuntimeException('Saved 2FA recovery code was rejected.');
if (snapsmack_match_recovery_code($user, 'wrong-code') !== false) throw new RuntimeException('Invalid recovery code was accepted.');

echo "login recovery code types regression: ok\n";
