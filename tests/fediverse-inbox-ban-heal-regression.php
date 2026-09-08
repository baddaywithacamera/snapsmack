<?php
/**
 * Regression: the fleet's self-inflicted inbox ban (reason 'auto:fediverse_inbox',
 * created by the retired pre-666D IP limiter) must clear AUTOMATICALLY, with no
 * cron / DNS / roster dependency, and must never wipe unrelated bans.
 *
 * Two mechanisms:
 *   1. PRIMARY — sv_inbox_rate_ok() drops the retired-reason ban ON CONTACT (the
 *      blocked request heals its own block; works on a dormant spoke and IPv6
 *      host, where the 667D/668D roster+DNS scoping failed).
 *   2. SECONDARY — sv_heal_stale_inbox_bans_once() sweeps the retired reason once
 *      per version, wired into cron + web sweep + updater.
 * Both are scoped strictly to the retired reason, so real bans (probe, auth) stand.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$root = dirname(__DIR__);
$fedi = file_get_contents($root . '/core/fediverse.php');
$cron = file_get_contents($root . '/cron-fediverse.php');
$updr = file_get_contents($root . '/core/updater.php');

$rl_start = strpos($fedi, 'function sv_inbox_rate_ok');
$rl = $rl_start !== false ? substr($fedi, $rl_start, 1600) : '';
$hl_start = strpos($fedi, 'function sv_heal_stale_inbox_bans_once');
$hl = $hl_start !== false ? substr($fedi, $hl_start, 1400) : '';

$checks = [
    // --- PRIMARY: self-heal at the door ---
    'inbox check self-heals the retired ban on contact' =>
        strpos($rl, "'auto:fediverse_inbox'") !== false
        && strpos($rl, 'DELETE FROM snap_ip_bans WHERE id = ?') !== false,
    'inbox check still honours a real ban (probe/auth)' =>
        strpos($rl, '$real_ban') !== false && strpos($rl, 'if ($real_ban) return false;') !== false,

    // --- SECONDARY: one-shot sweep, reason-scoped ---
    'sweep clears the retired reason' =>
        strpos($hl, "DELETE FROM snap_ip_bans WHERE reason = 'auto:fediverse_inbox'") !== false,
    'sweep is one-shot per version' =>
        strpos($hl, "'fedi_inbox_ban_heal_done'") !== false,

    // --- SAFETY: never an unscoped ban wipe, anywhere ---
    'no unscoped snap_ip_bans wipe exists' =>
        !preg_match('/DELETE\s+FROM\s+`?snap_ip_bans`?\s*(?:;|WHERE\s+1\b)/i', $fedi),

    // --- wired everywhere a spoke might run ---
    'sweep wired into CLI cron' => str_contains($cron, 'sv_heal_stale_inbox_bans_once('),
    'sweep wired into web sweep' => substr_count($fedi, 'sv_heal_stale_inbox_bans_once(') >= 2,
    'sweep wired into updater (dormant spokes heal on upgrade)' =>
        str_contains($updr, 'sv_heal_stale_inbox_bans_once('),
];

$failed = [];
foreach ($checks as $label => $ok) { if (!$ok) $failed[] = $label; }
if ($failed) {
    fwrite(STDERR, "FAIL: " . implode('; ', $failed) . "\n");
    exit(1);
}
echo "PASS: inbox self-ban clears on contact + one-shot sweep, reason-scoped, no unscoped wipe.\n";
// ===== SNAPSMACK EOF =====
