<?php
/**
 * OPAUDIT 014 "Last One In" — regression.
 *
 * Every site on a shared box writes the same tag into the same crontab. The
 * helper must tell lines apart by script path, adopt only orphan (dead-path)
 * lines, and judge "is cron running" on the scheduler heartbeat alone.
 */
require_once dirname(__DIR__) . '/core/cron-register.php';

$fail = 0;
$check = static function (string $name, bool $ok) use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$ok) $fail++;
};

$tag = '# snapsmack-fediverse';
$root_a = '/var/www/site-a';
$root_b = '/var/www/site-b';
$line_a = "*/10 * * * * '/usr/bin/php' '/var/www/site-a/cron-fediverse.php' >> '/var/www/site-a/logs/x.log' 2>&1 {$tag}";
$line_b = "*/10 * * * * '/usr/bin/php' '/var/www/site-b/cron-fediverse.php' >> '/var/www/site-b/logs/x.log' 2>&1 {$tag}";
$line_prefix_trap = "*/10 * * * * '/usr/bin/php' '/var/www/site-a-archive/cron-fediverse.php' 2>&1 {$tag}";

// 1. Same tag, different site: not ours.
$check('site A line is A\'s',            cron_line_is_ours($line_a, $tag, $root_a));
$check('site B line is not A\'s',        !cron_line_is_ours($line_b, $tag, $root_a));
$check('site B line is B\'s',            cron_line_is_ours($line_b, $tag, $root_b));
$check('/site-a-archive is not /site-a', !cron_line_is_ours($line_prefix_trap, $tag, $root_a));
$check('wrong tag is never ours',        !cron_line_is_ours($line_a, '# snapsmack-rss-fetch', $root_a));

// 2. Orphans: a tagged line whose script is gone is dead; a live one is not.
$tmp = tempnam(sys_get_temp_dir(), 'ss014');
$live_dir = $tmp . '.d';
@mkdir($live_dir);
file_put_contents($live_dir . '/cron-fediverse.php', "<?php\n");
$live_line = "*/10 * * * * '/usr/bin/php' '{$live_dir}/cron-fediverse.php' 2>&1 {$tag}";
$check('line to a live script is not dead',   !cron_line_is_dead($live_line, $tag));
$check('line to a missing script is dead',    cron_line_is_dead($line_a, $tag));
$check('untagged line is never dead',         !cron_line_is_dead(str_replace($tag, '', $line_a), $tag));
$check('site root derives from script path',  cron_site_root($live_dir . '/cron-fediverse.php') === rtrim(realpath($live_dir), '/\\'));
@unlink($live_dir . '/cron-fediverse.php'); @rmdir($live_dir); @unlink($tmp);

// 3. The verdict reads ONLY the scheduler heartbeat.
$now = time();
$ts = static fn(int $ago) => date('Y-m-d H:i:s', $now - $ago);
[$st] = cron_job_verdict(['fediverse_sched_last_fire' => $ts(120), 'fediverse_cron_last_run' => $ts(5)], 'fediverse');
$check('fired 2 min ago = firing', $st === 'firing');
[$st] = cron_job_verdict(['fediverse_sched_last_fire' => $ts(3600), 'fediverse_cron_last_run' => $ts(5)], 'fediverse');
$check('fresh last_run cannot hide a stale scheduler', $st === 'stale');
[$st] = cron_job_verdict(['fediverse_cron_last_run' => $ts(5)], 'fediverse');
$check('no scheduler record = never (last_run irrelevant)', $st === 'never');
[$st] = cron_job_verdict(['rss_fetch_sched_last_fire' => $ts(5400)], 'rss_fetch');
$check('rss 90 min ago is still firing (2x hourly)', $st === 'firing');
[$st] = cron_job_verdict(['rss_fetch_sched_last_fire' => $ts(7300)], 'rss_fetch');
$check('rss > 2h is stale', $st === 'stale');
[$st] = cron_job_verdict(['fediverse_sched_last_fire' => $ts(1800), 'deploy_finalized_at' => $ts(1200)], 'fediverse');
$check('deploy 20 min ago, no fire since = not-since-deploy', $st === 'not-since-deploy');
[$st] = cron_job_verdict(['fediverse_sched_last_fire' => $ts(900), 'deploy_finalized_at' => $ts(300)], 'fediverse');
$check('deploy 5 min ago: gate not yet armed', $st === 'firing');
[$st] = cron_job_verdict(['fediverse_sched_last_fire' => $ts(60), 'deploy_finalized_at' => $ts(1200)], 'fediverse');
$check('fired after the deploy = firing', $st === 'firing');
[$st] = cron_job_verdict(['version_check_sched_last_fire' => $ts(3600), 'deploy_finalized_at' => $ts(1200)], 'version_check');
$check('6-hourly job, deploy 20 min ago: gate waits one interval', $st === 'firing');
[$st] = cron_job_verdict(['version_check_sched_last_fire' => $ts(30000), 'deploy_finalized_at' => $ts(22000)], 'version_check');
$check('6-hourly job, deploy 6h+ ago, no fire since = not-since-deploy', $st === 'not-since-deploy');

// 4. Manual launches are marked so the scripts skip the scheduler stamp.
$src = file_get_contents(dirname(__DIR__) . '/core/cron-register.php');
$check('RUN NOW / kicks set SNAPSMACK_LAUNCH=manual', str_contains($src, "'SNAPSMACK_LAUNCH=manual ' . \$prefix"));
foreach (['cron-fediverse.php' => 'fediverse', 'cron-rss-fetch.php' => 'rss_fetch', 'cron-version-check.php' => 'version_check'] as $f => $job) {
    $check("{$f} stamps the scheduler heartbeat", str_contains(file_get_contents(dirname(__DIR__) . '/' . $f), "cron_stamp_scheduler_fire(\$pdo, '{$job}')"));
}
// 5. No page or endpoint edits the crontab by tag alone any more.
foreach (['smack-admin.php', 'smack-update.php'] as $f) {
    $check("{$f} has no raw crontab edit", !str_contains(file_get_contents(dirname(__DIR__) . '/' . $f), "exec(\"crontab {\$tmp}"));
}
$check('disable-federation removes only this site\'s line',
    str_contains(file_get_contents(dirname(__DIR__) . '/core/fediverse-admin-shared.php'), "cron_remove_job('# snapsmack-fediverse', dirname(__DIR__) . '/cron-fediverse.php')"));
$check('webcron repair is heartbeat-gated',
    str_contains(file_get_contents(dirname(__DIR__) . '/core/fediverse-webcron.php'), "cron_job_verdict(\$settings, 'fediverse')"));
$check('fleet API ships sched_state', str_contains(file_get_contents(dirname(__DIR__) . '/core/multisite-api.php'), "'sched_state'     => \$sched_state"));

if ($fail) { fwrite(STDERR, "{$fail} check(s) failed.\n"); exit(1); }
echo "ALL PASS — cron lines are per-site and the scheduler heartbeat is the only truth.\n";
// ===== SNAPSMACK EOF =====
