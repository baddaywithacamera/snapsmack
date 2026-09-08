<?php
/**
 * Regression: the stale-inbox-ban self-heal (0.7.667D) must be ROSTER-SCOPED and
 * one-shot — it must never become a blanket "delete all inbox bans", which would
 * unban a genuine outside flooder the old limiter legitimately caught.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$root = dirname(__DIR__);
$fedi = file_get_contents($root . '/core/fediverse.php');
$cron = file_get_contents($root . '/cron-fediverse.php');
$updr = file_get_contents($root . '/core/updater.php');

// The healer body (function to the next function).
$start = strpos($fedi, 'function sv_heal_stale_inbox_bans_once');
$body  = $start !== false ? substr($fedi, $start, 2600) : '';

$checks = [
    'healer exists' => $start !== false,
    'targets only the retired ban reason' =>
        str_contains($body, "reason = 'auto:fediverse_inbox'"),
    'is roster-scoped (reads the multisite roster to build fleet IPs)' =>
        str_contains($body, 'snap_multisite_nodes') && str_contains($body, '$fleet_ips'),
    'deletes only a ban whose IP is a fleet IP — never blanket' =>
        str_contains($body, 'isset($fleet_ips[(string)$b[\'ip\']])')
        && !preg_match('/DELETE FROM `?snap_ip_bans`?\s+WHERE\s+reason/i', $body),
    'resolves hosts (multi-server fleets, not one hardcoded IP)' =>
        str_contains($body, 'sv_resolve_host_bounded') && !str_contains($body, '199.126.129.179'),
    'one-shot per version (done-stamp)' =>
        str_contains($body, "'fedi_inbox_ban_heal_done'"),
    'will not stamp done if the roster could not be fully resolved' =>
        str_contains($body, '$resolve_failed'),
    'wired into the CLI cron' =>
        str_contains($cron, 'sv_heal_stale_inbox_bans_once('),
    'wired into the web sweep' =>
        substr_count($fedi, 'sv_heal_stale_inbox_bans_once(') >= 2, // definition + sweep call
    'wired into the updater — a dormant spoke heals on upgrade, not just on a cron it never runs' =>
        str_contains($updr, 'sv_heal_stale_inbox_bans_once('),
];

$failed = [];
foreach ($checks as $label => $ok) { if (!$ok) $failed[] = $label; }
if ($failed) {
    fwrite(STDERR, "FAIL: " . implode('; ', $failed) . "\n");
    exit(1);
}
echo "PASS: stale-inbox-ban heal is roster-scoped, one-shot, and never a blanket delete.\n";
// ===== SNAPSMACK EOF =====
