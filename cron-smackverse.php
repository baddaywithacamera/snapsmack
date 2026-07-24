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
 *   php cron-smackverse.php             — normal sweep + queue run
 *   php cron-smackverse.php resync [N]  — re-federate the N most recent posts
 *                                         (signed Update per Note, same id,
 *                                          drained at measured cadence)
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

// Cron and event kicks can arrive together. A database advisory lock works
// across the root cron user and the web/PHP user without filesystem ownership
// problems. It is released automatically when this connection closes.
$delivery_lock_name = 'snapsmack_sv_' . substr(hash('sha256', realpath($root) ?: $root), 0, 40);
$delivery_lock_stmt = $pdo->prepare("SELECT GET_LOCK(?, 0)");
$delivery_lock_stmt->execute([$delivery_lock_name]);
$delivery_lock = (int)$delivery_lock_stmt->fetchColumn();
if ($delivery_lock !== 1) {
    echo "SMACKVERSE delivery worker already running — nothing to do.\n";
    exit(0);
}

sv_ensure_tables($pdo);
sv_ensure_keys($pdo, $settings);

// RESYNC mode: php cron-smackverse.php resync [N]
// Re-federates the N most recent posts (default: smackverse_backfill_count) to
// all active followers by pushing a signed Update per Note — same id, current
// render (cover + full carousel stack), replacing the remote's cached copy in
// place. Use after a render change (bakes, covers, attachments): remote servers
// dedup plain re-Creates against their cache, and a Delete tombstones the id
// forever, so an Update is the only path that actually refreshes a federated
// post. Enqueued oldest-first, then drained at measured cadence so the posts
// land in chronological order with no burst to shuffle them.
if (($argv[1] ?? '') === 'resync') {
    $limit = isset($argv[2]) ? max(1, (int)$argv[2]) : null;
    list($rs_notes, $rs_deliveries) = sv_resync_recent($pdo, $settings, $limit);
    if ($rs_notes === 0) {
        echo "SMACKVERSE resync: nothing to do (no recent notes or no active followers).\n";
    } else {
        list($rsent, $rfailed) = sv_process_deliveries($pdo, $settings, 200, sv_delivery_cadence($settings));
        echo sprintf("SMACKVERSE resync: %d note(s) re-federated (%d Update deliveries; %d sent, %d retrying).\n",
                     $rs_notes, $rs_deliveries, $rsent, $rfailed);
    }
    exit(0);
}

list($units, $queued) = sv_sweep_new_posts($pdo, $settings);
// First-follow backfill: turn any pending backfill jobs (recorded by the inbox
// Follow handler) into paced deliveries BEFORE the drain, so a new follower's
// catalogue starts landing this run instead of next.
list($bf_jobs, $bf_queued) = sv_process_backfill_jobs($pdo, $settings);
// Paced drain: same measured cadence as resync so a first-follow backfill (and
// any sweep burst) lands on the remote in order, not shuffled by its async
// workers. CLI/cron context, so the inter-send sleeps cost nothing user-facing.
list($sent, $failed)  = sv_process_deliveries($pdo, $settings, 30, sv_delivery_cadence($settings));

// Profile propagation (AP spec): if the actor's bio, avatar or display name
// changed since we last federated it, push a signed Update(Actor) so followers'
// cached profiles refresh. Detected by fingerprint, so a profile edit made through
// ANY save path lands within a cron tick — no per-page hook to forget.
$actor_upd = sv_maybe_push_actor_update($pdo, $settings);

// Health stamp for the SMACKVERSE admin page's delivery panel.
sv_set_setting($pdo, $settings, 'smackverse_cron_last_run', date('Y-m-d H:i:s'));

echo sprintf(
    "SMACKVERSE sweep: %d new unit(s), %d delivery(ies) queued; backfill: %d job(s), %d queued. Queue run: %d sent, %d retrying/failed; profile-update: %d follower(s).\n",
    $units, $queued, $bf_jobs, $bf_queued, $sent, $failed, $actor_upd
);
exit(0);
// ===== SNAPSMACK EOF =====
