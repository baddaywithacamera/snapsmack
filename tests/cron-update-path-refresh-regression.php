<?php
/** Static contract: every completed update repairs enabled cron command paths. */
$root = dirname(__DIR__);
$helper = file_get_contents($root . '/core/cron-register.php');
$update = file_get_contents($root . '/smack-update.php');

$checks = [
    'shared refresh helper exists' => str_contains($helper, 'function cron_refresh_enabled_jobs'),
    'disabled jobs stay disabled'  => str_contains($helper, 'if (!cron_job_registered($tag)) continue;'),
    'federation job is covered'    => str_contains($helper, "'# snapsmack-fediverse'"),
    'RSS job is covered'           => str_contains($helper, "'# snapsmack-rss-fetch'"),
    'version job is covered'       => str_contains($helper, "'# snapsmack-version-check'"),
    'automatic update refreshes'   => substr_count($update, 'cron_refresh_enabled_jobs(__DIR__)') >= 2,
    'update log names the repair'  => substr_count($update, "'label'  => 'Cron command paths'") >= 2,
];

$failed = false;
foreach ($checks as $name => $ok) {
    if ($ok) continue;
    fwrite(STDERR, "FAIL: {$name}\n");
    $failed = true;
}
if ($failed) exit(1);
echo "PASS: update finalization refreshes enabled cron command paths.\n";
// ===== SNAPSMACK EOF =====
