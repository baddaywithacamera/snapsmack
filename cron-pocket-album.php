<?php
declare(strict_types=1);

/**
 * SNAPSMACK — POCKET ALBUM cache refresh (SS-1575)
 *
 * Refreshes every REMOTE album this site displays with [pocket_album src=...].
 * A page render never fetches: it reads the cache this fills, so no visitor
 * ever waits on another server to answer. Same division as the photoblogs.fyi
 * discovery feed.
 *
 * It configures itself from the site's own published content. There is no list
 * to maintain: a shortcode on a page is the configuration, and deleting the
 * shortcode stops the fetching. A site that displays no remote album does
 * nothing here and exits 0.
 *
 * Local albums -- [pocket_album album="N"] with no src -- are a query at
 * render time and are not cached, so this script has nothing to do for them.
 *
 * Hourly is plenty. An album is a curation, not a feed.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

define('SNAPSMACK_CRON', true);
require_once __DIR__ . '/core/db.php';
// REQUIRED, not assumed. This script called cron_stamp_scheduler_fire() behind
// a function_exists() guard, and nothing in its require chain defined it:
// core/db.php pulls in constants.php, core/pocket-album-display.php pulls in
// safe-remote-fetch.php, and neither reaches cron-register.php. So the guard
// was always false and pocket_album_sched_last_fire never moved: on a site
// that does display a remote album, CRON & JOBS would show the Scheduler row
// red, NEVER FIRED, while the job ran every hour. That is exactly the silent
// failure this job's own ticket exists to kill. Found by Muse in review of
// 2382b8fd. The three sibling cron scripts require this file and call the
// stamp unguarded; so does this one now.
require_once __DIR__ . '/core/cron-register.php';
require_once __DIR__ . '/core/pocket-album-display.php';

$say = static function (string $line): void { echo $line . "\n"; };

// The scheduler heartbeat, stamped FIRST and only for a real scheduler fire,
// exactly as the other three cron scripts do -- a crontab line is not proof
// that a job runs; a moving stamp is. cron_stamp_scheduler_fire() itself
// refuses a RUN NOW (SNAPSMACK_LAUNCH=manual) and a non-CLI launch, so the
// decision lives in one place rather than being re-made here.
cron_stamp_scheduler_fire($pdo, 'pocket_album');

$reports = pocket_album_refresh($pdo, $say);

// Stamp the launcher-agnostic last run + status that CRON & JOBS reads. Unlike
// the scheduler heartbeat above, this one moves for RUN NOW too: it answers
// "did this job run at all", which is the question a RUN NOW is asking.
$pocket_stamp = static function (PDO $pdo, string $key, string $value): void {
    try {
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")->execute([$key, $value]);
    } catch (Throwable $e) {
        // A stamp that cannot be written must not fail the refresh that worked.
    }
};

$failed = 0;
foreach ($reports as $report) {
    if (empty($report['ok'])) {
        $failed++;
        $say('FAIL ' . $report['source'] . ' album ' . $report['album'] . ': ' . $report['error']);
    }
}

// A FAILURE IS REPORTED, NOT SWALLOWED, but it is not fatal: the cache the
// pages read is untouched by a failed refresh, so the site keeps showing the
// album it last had. Exit 1 so a monitor can see it.
$pocket_stamp($pdo, 'pocket_album_last_run', date('Y-m-d H:i:s'));
$pocket_stamp($pdo, 'pocket_album_last_status', $failed > 0
    ? ($failed . ' of ' . count($reports) . ' failed')
    : (count($reports) === 0 ? 'no remote albums on this site' : 'ok'));

$say(count($reports) - $failed . ' of ' . count($reports) . ' remote album(s) refreshed.');
exit($failed > 0 ? 1 : 0);

// ===== SNAPSMACK EOF =====
