<?php
/**
 * SNAPSMACK — SMACKVERSE delivery cron (ActivityPub, v0.2)
 *
 * Scheduled task that (1) sweeps newly published content into federation
 * Notes for all followers and (2) processes the outbound delivery queue
 * (Accepts + Creates) with signed POSTs and exponential backoff.
 *
 * PULL model on purpose: no posting flow anywhere in the codebase is
 * touched by federation — this cron discovers new content by marker.
 * First-ever run initialises the markers and federates NOTHING, so an
 * existing library is never blasted at followers.
 *
 * USAGE:
 *   php cron-smackverse.php
 *
 * RECOMMENDED CRON SCHEDULE (every 10 minutes):
 *   0,10,20,30,40,50 * * * *  /usr/bin/php /path/to/cron-smackverse.php >> /dev/null 2>&1
 *
 * No-op (exit 0, no output changes) while smackverse_enabled != 1, so it
 * is safe to install the cron line before flipping the flag.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// --- BOOTSTRAP (CLI-safe, mirrors cron-version-check.php) ---
if (PHP_SAPI !== 'cli') {
    // Web invocation is not supported — the queue must not be drivable
    // (or DoS-able) from outside. Cron/CLI only.
    http_response_code(404);
    exit;
}

$root = __DIR__;
if (!file_exists("{$root}/core/db.php")) {
    fwrite(STDERR, "SnapSmack not installed (core/db.php missing). Exiting.\n");
    exit(1);
}
if (!defined('BASE_URL')) {
    define('BASE_URL', '/'); // CLI fallback; sv_base() prefers the site_url setting
}
require_once "{$root}/core/db.php";
require_once "{$root}/core/constants.php";
require_once "{$root}/core/smackverse.php";

try {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(1);
}

if (!sv_enabled($settings)) {
    echo "SMACKVERSE disabled — nothing to do.\n";
    exit(0);
}

sv_ensure_tables($pdo);
sv_ensure_keys($pdo, $settings);

list($units, $queued) = sv_sweep_new_posts($pdo, $settings);
list($sent, $failed)  = sv_process_deliveries($pdo, $settings, 30);

// Health stamp for the SMACKVERSE admin page's delivery panel.
sv_set_setting($pdo, $settings, 'smackverse_cron_last_run', date('Y-m-d H:i:s'));

echo sprintf(
    "SMACKVERSE sweep: %d new unit(s), %d delivery(ies) queued. Queue run: %d sent, %d retrying/failed.\n",
    $units, $queued, $sent, $failed
);
exit(0);
// ===== SNAPSMACK EOF =====
