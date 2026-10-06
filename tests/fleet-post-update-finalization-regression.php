<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * Regression: fleet completion work must run in a fresh request after the
 * update request replaces its own controller and updater files.
 */

$root = dirname(__DIR__);
$failures = [];
$checks = 0;

function fleet_finalize_check(bool $ok, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $message;
}

$api = (string)file_get_contents($root . '/core/multisite-api.php');
$hub = (string)file_get_contents($root . '/smack-multisite.php');

$endpoint = "\$resource === 'updates' && \$sub_action === 'finalize'";
$trigger_url = '/api.php?route=multisite/updates/trigger';
$finalize_url = '/api.php?route=multisite/updates/finalize';

fleet_finalize_check(str_contains($api, $endpoint), 'fresh-request finalize endpoint is missing');
fleet_finalize_check(str_contains($api, "Only a hub may finalize spoke updates"), 'finalize endpoint is not hub-authorized');
fleet_finalize_check(str_contains($api, "multisite_allow_update"), 'finalize endpoint is not protected by remote-update consent');
fleet_finalize_check(str_contains($api, 'updater_reconcile_public_site($pdo)'), 'finalize endpoint does not reconcile public files');
fleet_finalize_check(str_contains($api, "'status'      => 'finalized'"), 'finalize endpoint does not report completion');

$trigger_pos = strpos($hub, $trigger_url);
$finalize_pos = strpos($hub, $finalize_url, $trigger_pos === false ? 0 : $trigger_pos);
fleet_finalize_check($trigger_pos !== false, 'hub update trigger is missing');
fleet_finalize_check($finalize_pos !== false && $finalize_pos > $trigger_pos, 'hub does not finalize in a second request after update');
fleet_finalize_check(str_contains($hub, "'updated_not_finalized'"), 'hub does not distinguish installed from finalized');
fleet_finalize_check(str_contains($hub, "\$result['ok'] = false;"), 'finalization failure does not fail deployment verification');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PASS: fleet post-update finalization regression suite ({$checks} checks)\n";
// ===== SNAPSMACK EOF =====
