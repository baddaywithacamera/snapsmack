<?php
/**
 * SNAPSMACK — FEDIVERSE delivery cron (ActivityPub, v0.2)
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
 *   php cron-fediverse.php             — normal sweep + queue run
 *   php cron-fediverse.php resync [N]  — re-federate the N most recent posts
 *                                         (signed Update per Note, same id,
 *                                          drained at measured cadence)
 *
 * RECOMMENDED CRON SCHEDULE (every 10 minutes):
 *   0,10,20,30,40,50 * * * *  /usr/bin/php /path/to/cron-fediverse.php >> /dev/null 2>&1
 *
 * No-op (exit 0, no output changes) while fediverse_enabled != 1, so it
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
require_once "{$root}/core/fediverse.php";

try {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(1);
}

// Roster synchronization is lightweight and must not depend on federation
// delivery being enabled. This keeps FEDBOARD healthy on every spoke.
require_once "{$root}/core/mesh-helpers.php";
if (function_exists('ms_spoke_pull_roster')) {
    try { ms_spoke_pull_roster($pdo, $settings); } catch (Throwable $e) {}
}

if (!sv_enabled($settings)) {
    echo "FEDIVERSE disabled — nothing to do.\n";
    exit(0);
}

if (($settings['distribution_profile'] ?? '') === 'smackcast'
    && ($settings['smackback_enabled'] ?? '0') === '1'
    && ($settings['smackback_status'] ?? 'clean') === 'breach') {
    fwrite(STDERR, "SMACKCAST delivery suspended by SMACKBACK breach.\n");
    exit(1);
}

// Cron and event kicks can arrive together. A database advisory lock works
// across the root cron user and the web/PHP user without filesystem ownership
// problems. It is released automatically when this connection closes.
$delivery_lock_name = 'snapsmack_sv_' . substr(hash('sha256', realpath($root) ?: $root), 0, 40);
$delivery_lock_stmt = $pdo->prepare("SELECT GET_LOCK(?, 0)");
$delivery_lock_stmt->execute([$delivery_lock_name]);
$delivery_lock = (int)$delivery_lock_stmt->fetchColumn();
if ($delivery_lock !== 1) {
    echo "FEDIVERSE delivery worker already running — nothing to do.\n";
    exit(0);
}

// Record the start, not merely the end. The admin can now distinguish a cron
// that never launched from one that is actively working through a slow queue.
sv_set_setting($pdo, $settings, 'fediverse_cron_last_run', date('Y-m-d H:i:s'));
sv_set_setting($pdo, $settings, 'fediverse_cron_last_status', 'running');

sv_ensure_tables($pdo);
sv_ensure_keys($pdo, $settings);

// Receiver-side relay dereference failures are durable work, separate from the
// outbound delivery queue. The helper is inert when this blog has no jobs.
$relay_ingest = [0, 0];
if (function_exists('sc_relay_process_ingest_jobs')) {
    try { $relay_ingest = sc_relay_process_ingest_jobs($pdo, $settings, 20); }
    catch (Throwable $e) { fwrite(STDERR, "Optional relay ingest maintenance failed; ordinary delivery will continue: " . $e->getMessage() . "\n"); }
}
$relay_recovery = [0, 0];
if (function_exists('sc_relay_recover_member_outboxes')) {
    try { $relay_recovery = sc_relay_recover_member_outboxes($pdo, $settings, 5, 20); }
    catch (Throwable $e) { fwrite(STDERR, "Optional relay outbox recovery failed; ordinary delivery will continue: " . $e->getMessage() . "\n"); }
}
$pc_maintenance = [0, 0, 0];
if (function_exists('pc_cron_maintain')) {
    $pc_maintenance = pc_cron_maintain($pdo, $settings, 25);
}
$curator = ['disabled', 0, false, ''];
if (function_exists('sc_curator_cron')) {
    try { $curator = sc_curator_cron($pdo, $settings); }
    catch (Throwable $e) { fwrite(STDERR, "Optional curator maintenance failed; ordinary delivery will continue: " . $e->getMessage() . "\n"); }
}

// RESYNC mode: php cron-fediverse.php resync [N]
// Re-federates the N most recent posts (default: fediverse_backfill_count) to
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
        echo "FEDIVERSE resync: nothing to do (no recent notes or no active followers).\n";
    } else {
        list($rsent, $rfailed) = sv_process_deliveries($pdo, $settings, 200, sv_delivery_cadence($settings));
        echo sprintf("FEDIVERSE resync: %d note(s) re-federated (%d Update deliveries; %d sent, %d retrying).\n",
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
// Keep each ten-minute tick bounded. Remaining rows stay durable and are
// resumed oldest-first on the next tick; no delivery is discarded.
list($sent, $failed)  = sv_process_deliveries(
    $pdo, $settings, 30, sv_delivery_cadence($settings), null, null, null, 240
);

// Profile propagation (AP spec): if the actor's bio, avatar or display name
// changed since we last federated it, push a signed Update(Actor) so followers'
// cached profiles refresh. Detected by fingerprint, so a profile edit made through
// ANY save path lands within a cron tick — no per-page hook to forget.
$actor_upd = sv_maybe_push_actor_update($pdo, $settings);

// Mesh roster refresh so the FEDBOARD site-picker fills without anyone loading
// the Multisite admin page (its only other trigger).
if (is_file("{$root}/core/mesh-helpers.php")) {
    require_once "{$root}/core/mesh-helpers.php";
    if (function_exists('ms_spoke_pull_roster')) {
        try { ms_spoke_pull_roster($pdo, $settings); } catch (Throwable $e) {}
    }
}

// Health stamp for the FEDIVERSE admin page's delivery panel.
sv_set_setting($pdo, $settings, 'fediverse_cron_last_run', date('Y-m-d H:i:s'));
sv_set_setting($pdo, $settings, 'fediverse_cron_last_status', 'ok');

echo sprintf(
    "FEDIVERSE sweep: %d new unit(s), %d delivery(ies) queued; backfill: %d job(s), %d queued. Queue run: %d sent, %d retrying/failed; relay ingest: %d recovered, %d retrying/shelved; outbox recovery: %d members, %d recovered; PHOTOFRI: %d finalized, %d gardened, %d withdrawn; profile-update: %d follower(s); CURATOR: %s, %d discovered%s (%s).\n",
    $units, $queued, $bf_jobs, $bf_queued, $sent, $failed, $relay_ingest[0], $relay_ingest[1], $relay_recovery[0], $relay_recovery[1], $pc_maintenance[0], $pc_maintenance[1], $pc_maintenance[2], $actor_upd,
    $curator[0], $curator[1], $curator[2] ? ' (scan complete)' : '', $curator[3]
);
exit(0);
// ===== SNAPSMACK EOF =====
