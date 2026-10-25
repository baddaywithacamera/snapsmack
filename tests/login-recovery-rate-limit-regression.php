<?php
declare(strict_types=1);

$login = (string)file_get_contents(dirname(__DIR__) . '/snap-in.php');

function recovery_limit_check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

recovery_limit_check(
    str_contains($login, "\$active_tab = ((\$_GET['tab'] ?? '') === 'recovery') ? 'recovery' : 'password';"),
    'The forgot-password link cannot activate the Recovery Code panel on GET.'
);
recovery_limit_check(str_contains($login, "action = 'login_fail'"), 'Password failures lost their independent counter.');
recovery_limit_check(str_contains($login, "action = 'recovery_fail'"), 'Recovery-code failures have no independent counter.');
recovery_limit_check(
    strpos($login, 'snap_login_is_rate_limited($pdo, $_snap_ip)') > strpos($login, "if (\$login_type === 'password')"),
    'Password throttling still runs before the selected authentication method is known.'
);
recovery_limit_check(
    strpos($login, 'snap_recovery_is_rate_limited($pdo, $_snap_ip)') > strpos($login, "elseif (\$login_type === 'recovery')"),
    'Recovery throttling is not confined to recovery-code submissions.'
);
recovery_limit_check(
    str_contains($login, 'snap_record_recovery_failure($pdo, $_snap_ip)'),
    'A bad recovery code still increments the password counter.'
);
recovery_limit_check(
    str_contains($login, 'Wait ten minutes or use a recovery code now.'),
    'Password cooldown does not tell the owner that recovery remains available.'
);

echo "login recovery rate-limit regression: ok\n";
