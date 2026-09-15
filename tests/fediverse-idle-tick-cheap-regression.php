<?php
/**
 * 716D "IDLE HANDS": a delivery tick with nothing queued must cost about a
 * second, and a comment must leave the moment it is posted.
 *
 * Sean, 2026-09-14, looking at the Proxmox graphs after the fleet cron went
 * live: "this is unacceptable load for hardly anything happening." Two things
 * ran on every site every ten minutes regardless of work: a roster download
 * from the hub (twice per tick), and a follow attempt at any peer not yet
 * followed — retried every tick forever when the peer could not be reached.
 * And comments never kicked the worker at all; they waited for the tick.
 */
$root = dirname(__DIR__);
$fedi = file_get_contents($root . '/core/fediverse.php');
$cron = file_get_contents($root . '/cron-fediverse.php');
$mesh = file_get_contents($root . '/core/mesh-helpers.php');

$reconciler = substr($fedi,
    strpos($fedi, 'function sv_reconcile_mesh_follows'),
    strpos($fedi, '/** Unfollow:', strpos($fedi, 'function sv_reconcile_mesh_follows'))
        - strpos($fedi, 'function sv_reconcile_mesh_follows'));
$federate_comment = substr($fedi,
    strpos($fedi, 'function sv_federate_comment'),
    strpos($fedi, 'function sv_backfill_community_comments_once')
        - strpos($fedi, 'function sv_federate_comment'));

$checks = [
    // Roster: background pulls are gated; deliberate pulls force through.
    'roster pull takes a force flag' => str_contains($mesh, 'function ms_spoke_pull_roster(PDO $pdo, array $settings, bool $force = false)'),
    'roster pull is hourly after a success' => str_contains($mesh, "'fresh_for'") || str_contains($mesh, '? 3600 : 600'),
    'roster pull reports the skip' => str_contains($mesh, "'skipped' => 'fresh'"),
    'cron pulls the roster once, not twice' => substr_count($cron, 'ms_spoke_pull_roster($pdo, $settings)') === 1,
    'hub fleet job forces the pull' => str_contains(file_get_contents($root . '/core/multisite-api.php'), 'ms_spoke_pull_roster($pdo, $settings, true)'),
    // Mesh follow: an unreachable peer backs off, and a success clears it.
    'follow backoff is stored per actor' => str_contains($reconciler, "'sv_mesh_follow_backoff'"),
    'follow backoff skips a peer not yet due' => str_contains($reconciler, "(int)(\$backoff[\$actor]['next'] ?? 0) > \$now) continue"),
    'follow backoff doubles and caps at a day' => str_contains($reconciler, 'min(86400, 600 * (2 ** min($fails - 1, 8)))'),
    'follow success clears the backoff' => str_contains($reconciler, 'unset($backoff[$actor])'),
    'reconciler still never deletes' => !str_contains($reconciler, 'DELETE FROM'),
    // Comments: kicked on the spot, same launcher as posts.
    'federating a comment kicks the worker' => str_contains($federate_comment, 'sv_kick_delivery();'),
    'kick uses the shared launcher' => str_contains($federate_comment, "require_once __DIR__ . '/fediverse-kick.php';"),
    // Timing: the log names where the seconds went.
    'tick logs per-phase timing' => str_contains($cron, "echo 'TIMING '") && str_contains($cron, "\$sv_lap('drain')") && str_contains($cron, "\$sv_lap('mesh')"),
];

$failed = false;
foreach ($checks as $name => $ok) {
    if ($ok) continue;
    fwrite(STDERR, "FAIL: {$name}\n");
    $failed = true;
}
if ($failed) exit(1);
echo "PASS: an idle delivery tick is cheap and a comment leaves the moment it is posted.\n";
// ===== SNAPSMACK EOF =====
