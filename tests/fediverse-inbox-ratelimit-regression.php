<?php
/**
 * Regression: the inbox flood guard must be SHARED-IP SAFE and must still LIMIT.
 *
 * The whole fleet lives behind ONE shared IP (vhost-differentiated — the normal
 * shared-hosting deployment), so an IP-keyed limit that counts legitimate
 * traffic blacks out the fleet. The 0.7.665D design moves accountability to the
 * SIGNATURE: verified traffic is limited PER SENDING INSTANCE (host), unverified
 * junk is capped per IP, and the inbox path never auto-bans. This guard asserts
 * BOTH halves — it must not be possible to satisfy it by disabling the limiter.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$root   = dirname(__DIR__);
$fedi   = file_get_contents($root . '/core/fediverse.php');
$router = file_get_contents($root . '/fediverse.php');

// The block covering the three inbox-rate functions (read-only IP check, the
// unverified note, and the per-instance verified limit).
$blk_start = strpos($fedi, 'function sv_inbox_rate_ok');
$block     = $blk_start !== false ? substr($fedi, $blk_start, 4000) : '';

$verify_at = strpos($router, '$actor_doc = sv_verify_signature($raw)');
$note_at   = strpos($router, 'sv_inbox_note_unverified($pdo)');
$actor_at  = strpos($router, 'sv_inbox_actor_rate_ok($pdo, $sv_actor_host');

$checks = [
    // --- it still LIMITS (both halves present) ---
    'per-instance verified limit exists' =>
        str_contains($fedi, 'function sv_inbox_actor_rate_ok'),
    'verified limit is keyed on the sending instance, not the IP' =>
        str_contains($fedi, "'fediverse_inbox_verified'")
        && str_contains($fedi, "hash('sha256', \$actor_host)"),
    'unverified junk is still capped per IP' =>
        str_contains($fedi, "'fediverse_inbox_unverified'")
        && str_contains($fedi, 'function sv_inbox_note_unverified'),
    'the verified limit is enforced in the inbox router after verification' =>
        $actor_at !== false && $verify_at !== false && $actor_at > $verify_at,

    // --- it is SHARED-IP SAFE (legit traffic not IP-counted, no ban) ---
    'the IP check is read-only — verified traffic is never counted against the IP' =>
        strpos($block, 'sv_inbox_actor_rate_ok') !== false      // window reached all 3 funcs
        && strpos(substr($fedi, $blk_start, strpos($fedi, 'function sv_inbox_note_unverified') - $blk_start),
                  'INSERT INTO snap_rate_limits') === false,     // ...none inside sv_inbox_rate_ok
    'the inbox flood path never auto-bans (a shared-IP ban would silence the fleet)' =>
        $block !== '' && strpos($block, 'sv_inbox_actor_rate_ok') !== false
        && strpos($block, 'snap_ip_record_ban') === false,
    'junk is counted ONLY after a failed verification, never before' =>
        $note_at !== false && $verify_at !== false && $note_at > $verify_at,
    'a rate-limited sender is told to back off (Retry-After)' =>
        str_contains($router, 'Retry-After: 600'),
];

$failed = [];
foreach ($checks as $label => $ok) { if (!$ok) $failed[] = $label; }
if ($failed) {
    fwrite(STDERR, "FAIL: " . implode('; ', $failed) . "\n");
    exit(1);
}
echo "PASS: inbox flood guard is shared-IP safe and still limits both halves.\n";
// ===== SNAPSMACK EOF =====
