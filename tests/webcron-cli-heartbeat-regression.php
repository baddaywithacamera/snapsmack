<?php
/** Static guards for public web-cron suppression by healthy CLI workers. */
$root = dirname(__DIR__);
$web = file_get_contents($root . '/core/fediverse-webcron.php');
$fedi = file_get_contents($root . '/cron-fediverse.php');
$rss = file_get_contents($root . '/cron-rss-fetch.php');
$checks = [
    [str_contains($fedi, "'fediverse_cli_cron_last_run'"), 'federation CLI writes its own heartbeat'],
    [str_contains($web, "['fediverse_cli_cron_last_run']") && str_contains($web, '< 1500'), 'fresh federation CLI heartbeat suppresses web fallback'],
    [str_contains($rss, "'rss_cli_cron_last_run'") && str_contains($rss, "php_sapi_name() === 'cli'"), 'RSS CLI writes its own heartbeat only from CLI'],
    [str_contains($web, "['rss_cli_cron_last_run']") && str_contains($web, '< 5400'), 'fresh RSS CLI heartbeat suppresses web fallback'],
];
$failed = 0;
foreach ($checks as [$ok, $label]) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $failed++;
}
echo $failed ? "$failed FAILURE(S)\n" : "ALL PASS\n";
exit($failed ? 1 : 0);
// ===== SNAPSMACK EOF =====
