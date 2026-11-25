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
require_once __DIR__ . '/core/pocket-album-display.php';

$say = static function (string $line): void { echo $line . "\n"; };

// The scheduler heartbeat, stamped FIRST and only for a real scheduler fire,
// exactly as the other three cron scripts do -- a crontab line is not proof
// that a job runs; a moving stamp is. RUN NOW sets SNAPSMACK_LAUNCH=manual and
// must not move it.
if (($_SERVER['SNAPSMACK_LAUNCH'] ?? getenv('SNAPSMACK_LAUNCH') ?: '') !== 'manual'
    && function_exists('cron_stamp_scheduler_fire')) {
    cron_stamp_scheduler_fire($pdo, 'pocket_album');
}

$reports = pocket_album_refresh($pdo, $say);

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
$say(count($reports) - $failed . ' of ' . count($reports) . ' remote album(s) refreshed.');
exit($failed > 0 ? 1 : 0);

// ===== SNAPSMACK EOF =====
