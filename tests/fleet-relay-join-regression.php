<?php
/**
 * FLEET JOIN (0.7.629D "FIELD TRIP") regression — hub-driven relay join for
 * every multisite spoke, with a fix-it-first review and step-up gating.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root   = dirname(__DIR__);
$api    = file_get_contents($root . '/core/multisite-api.php');
$sv     = file_get_contents($root . '/core/fediverse.php');
$admin  = file_get_contents($root . '/core/fediverse-admin-shared.php');
$portal = file_get_contents($root . '/smack-fediverse-portal.php');
$help   = file_get_contents($root . '/smack-help.php');
$fail = 0;
function fj_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

// ── Spoke endpoints exist and fail closed ───────────────────────────────────
fj_test(str_contains($api, "\$resource === 'fediverse' && \$sub_action === 'status'"), 'spoke exposes a read-only fediverse status endpoint');
fj_test(str_contains($api, "\$resource === 'fediverse' && \$sub_action === 'relay-join'"), 'spoke exposes a hub-triggered relay-join endpoint');
fj_test(str_contains($api, "if (!sv_enabled(\$settings)) {"), 'relay-join refuses when federation is off');
fj_test(str_contains($api, "trim((string)(\$settings['fediverse_handle'] ?? '')) === ''"), 'relay-join refuses a blog with no explicitly-chosen handle');
fj_test(str_contains($api, "stripos(\$fj_relay, 'https://') !== 0"), 'relay-join requires an https relay actor URL');
fj_test(str_contains($api, "\$settings['photoblogs_relay_url'] = \$fj_relay;"), 'relay-join points the spoke at the given relay before joining');
fj_test(str_contains($api, 'list($fj_ok, $fj_msg) = sv_relay_join($pdo, $settings);'), 'relay-join reuses the portal JOIN NETWORK path (same signed Follow)');

// ── Preflight problem detection ─────────────────────────────────────────────
fj_test(str_contains($sv, 'function sv_fleet_join_problems(array $status): array'), 'preflight problem detector exists in core');
fj_test(str_contains($sv, "'Federation is turned OFF'"), 'preflight flags federation off');
fj_test(str_contains($sv, 'No handle chosen'), 'preflight flags a blank handle');
fj_test(str_contains($sv, "strpos(\$handle, '.') !== false"), 'preflight flags a dotted (domain-shaped) handle');
fj_test(str_contains($sv, '$handle === $first_label'), 'preflight flags a handle equal to the domain first label');

// ── Hub handler: step-up gated, fail-closed, pre-admitted ───────────────────
fj_test(str_contains($admin, "(\$_POST['action'] ?? '') === 'fleet_join'"), 'hub fleet_join handler exists');
fj_test(preg_match("/fleet_join'\\)\\s*\\{\\s*\\n\\s*\\\$ra = reauth_verify/", $admin) === 1, 'fleet_join is password+TOTP step-up gated before any work');
fj_test(str_contains($admin, "no valid relay is configured for this hub") && str_contains($admin, "stripos(\$fj_relay, 'https://') !== 0"), 'fleet join requires only a valid https relay target — a management hub need not be a relay member itself');
fj_test(str_contains($admin, 'sc_fleet_status_row($fj_node);   // fail-closed: re-verify at join time'), 'each spoke is re-verified at join time, not trusted from the review');
fj_test(str_contains($admin, 'snap_relay_allowlist'), 'selected spoke domains are pre-admitted so joins do not sit pending');
fj_test(str_contains($admin, '$fj_relay   = $sc_is_hub_install ? sv_actor_url($sv_settings) : sv_relay_actor_url($sv_settings);'), 'target = own actor when this blog IS the relay, else the relay this hub is joined to');
fj_test(str_contains($admin, "'No answer — join NOT confirmed'"), 'an unreachable spoke reports honestly as NOT joined');
fj_test(str_contains($admin, "role = 'spoke' AND status = 'active' AND api_key_local <> ''"), 'fleet list covers active connected spokes only');

// ── 0.7.640D fixes: slow joins, pending state, relay-side admission ─────────
fj_test(str_contains($admin, 'int $timeout = 6'), 'sc_fleet_call takes a per-call timeout (6s default for status sweeps)');
fj_test(str_contains($admin, "['relay_url' => \$fj_relay], 30"), 'the JOIN call gets a 30s window — 6s false-failed real joins');
fj_test(str_contains($admin, 'CURLOPT_CONNECTTIMEOUT'), 'connect timeout stays tight so a dead box still fails fast');
fj_test(str_contains($admin, "confirmed by a follow-up check"), 'a silent join is re-checked via status before being reported as failed');
fj_test(str_contains($admin, "'Already on the relay — nothing to do.'"), 'an already-accepted member is skipped at join time, never re-joined');
fj_test(str_contains($admin, "multisite/fediverse/relay-admit"), 'when this hub is NOT the relay, admission is requested ON the relay');
fj_test(preg_match('/if \(\$sc_is_hub_install\) \{\s*\n\s*try \{\s*\n\s*\$pdo->prepare\("INSERT IGNORE INTO snap_relay_allowlist/', $admin) === 1, 'the LOCAL allowlist is only written when this blog IS the relay');
fj_test(str_contains($api, "\$resource === 'fediverse' && \$sub_action === 'relay-admit' && \$method === 'POST'"), 'relay exposes the admission endpoint');
fj_test(str_contains($api, 'This install is not the network relay — nothing to admit to.'), 'relay-admit fails closed on any install that is not the relay');
fj_test(str_contains($api, "domain must be a bare hostname"), 'relay-admit validates the domain shape');

// ── Portal UI ───────────────────────────────────────────────────────────────
fj_test(str_contains($portal, 'REVIEW FLEET'), 'portal offers the fleet review');
fj_test(str_contains($portal, 'if ($sc_fleet_spoke_count > 0): ?>'), 'FLEET is its own box on ANY hub with spokes (not gated on being the smackcast relay)');
fj_test(str_contains($portal, 'name="spoke_ids[]"'), 'join is per-spoke opt-in via checkboxes');
fj_test(str_contains($portal, 'FIX IT'), 'flagged blogs get a FIX IT link to their own portal');
fj_test(str_contains($portal, '$fready   = is_array($fstatus) && !$frow[\'problems\'] && !$fjoined && !$fpending;'), 'only problem-free, not-joined, not-pending blogs are tickable');
fj_test(str_contains($portal, "Join sent &mdash; awaiting the relay's Accept"), 'a pending join shows as pending, not Ready — the re-check-everyone bug');
fj_test(str_contains($portal, '$frelay_host !== \'\' && $frelay_host === $ftarget_host'), 'joined/pending only count against THIS hub\'s target relay, not some other relay');
fj_test(str_contains($portal, 'Already on the relay (<?php echo htmlspecialchars($frelay_host); ?>)'), 'the joined row names WHICH relay the blog is on');
fj_test(substr_count($portal, 'name="reauth_password"') >= 3, 'fleet join form carries the step-up password field');
fj_test(str_contains($help, "fediverse-fleet-join"), 'help topic for the fleet join exists');

echo $fail === 0 ? "ALL PASS\n" : ("{$fail} FAILURE(S)\n");
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
