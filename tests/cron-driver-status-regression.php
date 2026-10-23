<?php
/** Regression: the dashboard distinguishes RSS driver health from host capability. */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
require_once $root . '/core/cron-register.php';

$now = strtotime('2026-10-22 12:00:00');
$cases = [
    'local wins when fresh' => [
        ['multisite_role' => 'spoke', 'rss_cli_cron_last_run' => '2026-10-22 11:30:00', 'rss_hub_driver_last_tick' => '2026-10-22 11:45:00'],
        'local',
    ],
    'hub owns its own fresh stamp' => [
        ['multisite_role' => 'spoke', 'rss_hub_driver_last_tick' => '2026-10-22 11:30:00'],
        'hub',
    ],
    'standalone cannot masquerade as hub' => [
        ['multisite_role' => '', 'rss_hub_driver_last_tick' => '2026-10-22 11:30:00'],
        'stale',
    ],
    'no evidence means not running' => [[], 'none'],
    'old evidence is stale' => [
        ['multisite_role' => 'spoke', 'rss_cli_cron_last_run' => '2026-10-22 08:00:00'],
        'stale',
    ],
];

$fail = [];
foreach ($cases as $name => [$settings, $expected]) {
    $actual = cron_rss_driver_state($settings, $now)['state'];
    if ($actual !== $expected) $fail[] = "$name: expected $expected, got $actual";
}

$dashboard = file_get_contents($root . '/smack-admin.php');
$api = file_get_contents($root . '/core/multisite-api.php');
$checks = [
    [str_contains($dashboard, 'ACTIVE RSS DRIVER'), 'dashboard names the active RSS driver'],
    [str_contains($dashboard, 'LOCAL SCHEDULER'), 'dashboard reports local capability separately'],
    [str_contains($dashboard, 'Registering a local job is safe'), 'hub state explains local takeover'],
    [!str_contains($dashboard, 'RSS last-updated features are unavailable'), 'false blanket unavailable claim removed'],
    [str_contains($api, "'rss_hub_driver_last_tick'"), 'authenticated hub endpoint owns a dedicated stamp'],
];
foreach ($checks as [$ok, $message]) if (!$ok) $fail[] = $message;

if ($fail) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $fail) . "\n");
    exit(1);
}
echo "PASS cron driver status regression\n";

// ===== SNAPSMACK EOF =====

