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
fj_test(str_contains($admin, '$sc_is_hub_install') && str_contains($admin, "=== 'fleet_join'"), 'fleet_join only runs on the smackcast hub install');
fj_test(str_contains($admin, 'sc_fleet_status_row($fj_node);   // fail-closed: re-verify at join time'), 'each spoke is re-verified at join time, not trusted from the review');
fj_test(str_contains($admin, 'snap_relay_allowlist'), 'selected spoke domains are pre-admitted so joins do not sit pending');
fj_test(str_contains($admin, '$fj_relay   = sv_actor_url($sv_settings);'), 'spokes are pointed at THIS hub\'s own relay actor');
fj_test(str_contains($admin, "'No answer — join NOT confirmed'"), 'an unreachable spoke reports honestly as NOT joined');
fj_test(str_contains($admin, "role = 'spoke' AND status = 'active' AND api_key_local <> ''"), 'fleet list covers active connected spokes only');

// ── Portal UI ───────────────────────────────────────────────────────────────
fj_test(str_contains($portal, 'REVIEW FLEET'), 'portal offers the fleet review');
fj_test(str_contains($portal, 'name="spoke_ids[]"'), 'join is per-spoke opt-in via checkboxes');
fj_test(str_contains($portal, 'FIX IT'), 'flagged blogs get a FIX IT link to their own portal');
fj_test(str_contains($portal, '$fready   = is_array($fstatus) && !$frow[\'problems\'] && !$fjoined;'), 'only problem-free, not-yet-joined blogs are tickable');
fj_test(substr_count($portal, 'name="reauth_password"') >= 3, 'fleet join form carries the step-up password field');
fj_test(str_contains($help, "fediverse-fleet-join"), 'help topic for the fleet join exists');

echo $fail === 0 ? "ALL PASS\n" : ("{$fail} FAILURE(S)\n");
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
