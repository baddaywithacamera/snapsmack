<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * Every cron script must load the helpers it calls, and must not guard the
 * scheduler heartbeat behind function_exists().
 *
 * THE FAULT THIS CLOSES. cron-pocket-album.php called
 * cron_stamp_scheduler_fire() inside `function_exists(...)` and nothing in its
 * require chain defined it: core/db.php reaches only constants.php, and
 * core/pocket-album-display.php reaches only safe-remote-fetch.php. The guard
 * was therefore always false, pocket_album_sched_last_fire never moved, and on
 * a site that does display a remote album CRON & JOBS would have shown the
 * Scheduler row red -- NEVER FIRED -- while the job ran every hour. The job
 * worked; the only thing that reports whether it works did not.
 *
 * Nothing caught it because a function_exists() guard turns a missing include
 * into silence, and because the stamp cannot be executed on SQLite (it writes
 * through MySQL's ON DUPLICATE KEY UPDATE). So this test does the next best
 * thing, and does it for all four scripts: it loads exactly what each script
 * requires, in a separate process, and asserts the helpers it calls are
 * actually defined. Found by Muse in review of 2382b8fd.
 */

$root = dirname(__DIR__);
$fails = [];
$check = static function (string $what, bool $ok) use (&$fails): void {
    if (!$ok) { $fails[] = $what; }
    echo ($ok ? 'PASS ' : 'FAIL ') . $what . "\n";
};

$scripts = ['cron-fediverse.php', 'cron-rss-fetch.php', 'cron-version-check.php',
            'cron-pocket-album.php'];

foreach ($scripts as $script) {
    $path = $root . '/' . $script;
    $source = (string)file_get_contents($path);
    $check($script . ' is in the build', $source !== '');

    // What it loads, exactly as written. The four scripts spell the site root
    // three different ways -- __DIR__ . '/core/x.php', "{$root}/core/x.php"
    // and $base . '/core/x.php' -- so all three forms are read, and the root
    // expression is discarded: only the path under the install matters here.
    preg_match_all(
        '~require(?:_once)?\s*\(?\s*(?:__DIR__\s*\.\s*|\$[a-z_]+\s*\.\s*)?'
        . '[\'"](?:\{\$[a-z_]+\})?(/core/[A-Za-z0-9_.\-]+\.php)[\'"]~i',
        $source, $req);
    $requires = array_values(array_unique($req[1]));
    $check($script . ' loads at least one core file', $requires !== []);

    // Which cron_* helpers it calls.
    preg_match_all('~\b(cron_[a-z_]+)\s*\(~', $source, $calls);
    $called = array_values(array_unique($calls[1]));
    $check($script . ' calls at least one cron helper: ' . implode(', ', $called), $called !== []);

    // THE SCHEDULER HEARTBEAT IS NOT OPTIONAL. A guard here is the fault: it
    // turns a missing include into a job that reports nothing, for ever.
    $check($script . ' stamps the scheduler heartbeat',
        strpos($source, 'cron_stamp_scheduler_fire(') !== false);
    $check($script . ' does NOT hide the heartbeat behind function_exists()',
        preg_match('~function_exists\s*\(\s*[\'"]cron_stamp_scheduler_fire[\'"]~', $source) !== 1);
    $check($script . ' requires core/cron-register.php, which defines it',
        in_array('/core/cron-register.php', $requires, true));

    // Now prove it: load ONLY what the script requires, in its own process,
    // and ask whether the helpers it calls exist.
    //
    // Two kinds of file are left out, and both exclusions make the check
    // STRICTER rather than weaker, because a helper must still turn up without
    // them:
    //   core/db.php       installer-generated; opens a real connection.
    //   core/updater*.php loading it SELF-HEALS .htaccess and .user.ini in the
    //                     install. A test must not write to the working tree;
    //                     the first run of this test created a .user.ini and I
    //                     deleted it.
    $side_effects = static fn(string $rel): bool =>
        $rel === '/core/db.php' || (bool)preg_match('~^/core/updater~', $rel);
    $loads = [];
    $skipped = [];
    foreach ($requires as $rel) {
        if ($side_effects($rel)) { $skipped[] = $rel; continue; }
        $loads[] = $root . $rel;
    }
    if ($skipped !== []) {
        echo '     (not loaded, writes to the install or needs a database: '
            . implode(', ', $skipped) . ")\n";
    }
    $probe = '<?php declare(strict_types=1);'
        . '$files = ' . var_export($loads, true) . ';'
        . 'foreach ($files as $f) { if (is_file($f)) { require_once $f; } }'
        . '$missing = [];'
        . 'foreach (' . var_export($called, true) . ' as $fn) {'
        . '  if (!function_exists($fn)) { $missing[] = $fn; }'
        . '}'
        . 'echo $missing ? implode(",", $missing) : "none";';
    $tmp = tempnam(sys_get_temp_dir(), 'cronprobe') . '.php';
    file_put_contents($tmp, $probe);
    $out = [];
    $code = 1;
    exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    @unlink($tmp);
    // The probe's answer is its LAST line. A required file may print chatter of
    // its own, and reading the whole buffer reported that chatter as a missing
    // function name.
    $lines = array_values(array_filter(array_map('trim', $out), static fn($l) => $l !== ''));
    $result = $lines === [] ? '' : (string)end($lines);
    $check($script . ': every cron helper it calls is defined by its own requires (missing: '
        . ($result === '' ? '(probe produced nothing)' : $result) . ')', $result === 'none');
}

// All four jobs must also be in the catalogue, or CRON & JOBS cannot give them
// an interval and cron_job_verdict() falls back to a wrong default.
require_once $root . '/core/cron-register.php';
$catalogue = cron_job_catalogue();
foreach (['fediverse', 'rss_fetch', 'version_check', 'pocket_album'] as $job) {
    $check('the catalogue holds ' . $job, isset($catalogue[$job]));
}
foreach ($catalogue as $job => $entry) {
    $check('the catalogue entry for ' . $job . ' names a script that is in the build',
        is_file($root . '/' . (string)($entry[2] ?? '')));
    $check('the catalogue entry for ' . $job . ' carries a schedule and an interval',
        trim((string)($entry[1] ?? '')) !== '' && (int)($entry[3] ?? 0) > 0);
}

// Each script's job name must be the catalogue key it stamps, or the verdict
// reads a stamp nothing writes.
$expected = ['cron-fediverse.php' => 'fediverse', 'cron-rss-fetch.php' => 'rss_fetch',
             'cron-version-check.php' => 'version_check', 'cron-pocket-album.php' => 'pocket_album'];
foreach ($expected as $script => $job) {
    $source = (string)file_get_contents($root . '/' . $script);
    $check($script . " stamps the catalogue key '" . $job . "'",
        strpos($source, "cron_stamp_scheduler_fire(\$pdo, '" . $job . "')") !== false);
}

echo "\n";
if ($fails) {
    echo "FAILED (" . count($fails) . "):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "ALL PASS\n";

// NOT CHECKED: that the stamp actually writes. cron_stamp_scheduler_fire()
// uses MySQL's ON DUPLICATE KEY UPDATE, so it cannot be executed against the
// SQLite used here. This proves the function is reachable and called
// unguarded, which is where the fault was.

// ===== SNAPSMACK EOF =====
