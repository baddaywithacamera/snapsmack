<?php
$kick = file_get_contents(__DIR__ . '/../core/fediverse-kick.php');
$checks = [
    [str_contains($kick, "require_once __DIR__ . '/cron-register.php'"), 'event kick loads the shared cron launcher'],
    [str_contains($kick, 'return cron_run_detached($php, $script);'), 'event kick returns the real detached-launch result'],
    [!str_contains($kick, '> /dev/null 2>&1 &'), 'event kick no longer discards launcher failures'],
];
$failed = 0;
foreach ($checks as [$ok, $label]) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $failed++;
}
exit($failed ? 1 : 0);
