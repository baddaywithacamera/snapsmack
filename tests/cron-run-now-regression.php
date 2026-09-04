<?php
/** Static regression guard for non-blocking admin-triggered cron jobs. */
$helper = file_get_contents(__DIR__ . '/../core/cron-register.php');
$admin = file_get_contents(__DIR__ . '/../smack-cron.php');
$checks = [
    'detached runner' => 'function cron_run_detached',
    'new session' => '/usr/bin/setsid',
    'hangup fallback' => '/usr/bin/nohup',
    'discarded detached output' => '> /dev/null 2>&1 &',
    'admin uses detached runner' => 'cron_run_detached($php_cli, $script_abs)',
    'truthful start message' => 'started in the background',
];
foreach ($checks as $name => $needle) {
    $source = $name === 'admin uses detached runner' || $name === 'truthful start message' ? $admin : $helper;
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "Missing: {$name}\n"); exit(1);
    }
}
if (strpos($admin, "@exec(escapeshellarg(\$php_cli)") !== false) {
    fwrite(STDERR, "RUN NOW returned to synchronous execution\n"); exit(1);
}
echo "Cron RUN NOW regression checks passed.\n";
// ===== SNAPSMACK EOF =====
