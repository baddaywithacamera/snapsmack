<?php
/** Static guards for the consent-directory curator. */
$root = dirname(__DIR__);
$curator = file_get_contents($root . '/core/curator-directory.php');
$relay = file_get_contents($root . '/core/smackcast-relay.php');
$admin = file_get_contents($root . '/core/fediverse-admin-shared.php');
$portal = file_get_contents($root . '/smack-fediverse-portal.php');
$cron = file_get_contents($root . '/cron-fediverse.php');
$fedi = file_get_contents($root . '/core/fediverse.php');
$router = file_get_contents($root . '/fediverse.php');
$fail = 0;
function curator_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    if (!$ok) $fail++;
}
curator_test(str_contains($curator, "\$settings['_ap_actor_url'] = \$base"), 'curator is a secondary actor rather than a relay rename');
curator_test(str_contains($curator, "strtolower(sv_domain(\$settings)) === 'photoblogs.fyi'"), 'worker is locked to photoblogs.fyi');
curator_test(str_contains($fedi, "'acct:curator@photoblogs.fyi'"), 'WebFinger exposes the secondary curator identity');
curator_test(str_contains($router, "(\$seg[0] ?? '') === 'curator'"), 'curator path endpoints route independently');
curator_test(str_contains($fedi, "actor_role") && str_contains($fedi, "sc_curator_settings(\$pdo, \$primary_settings, true)"), 'queued curator work retains curator signing keys');
curator_test(str_contains($fedi, "\$primary_settings = \$pdo->query"), 'a curator kick cannot sign primary queue rows as curator');
curator_test(str_contains($curator, '/api/_meta-api/explore/topic/list'), 'uses the public JSON directory endpoint');
curator_test(!str_contains($curator, '/people?topics='), 'does not scrape the HTML people page');
curator_test(str_contains($curator, "['slugs' => ['photography']]"), 'selects the consented photography topic');
curator_test(str_contains($curator, 'time() + 10800'), 'directory pages are spread over about three days');
curator_test(str_contains($curator, 'time() + 900'), 'remote account actions are limited to one per 15 minutes');
curator_test(str_contains($curator, 'INTERVAL 1 HOUR'), 'each destination server is limited to one follow per hour');
curator_test(str_contains($curator, 'last_done > time() - 30 * 86400'), 'complete rescans are monthly');
curator_test(str_contains($curator, "state='missing'"), 'a completed scan marks disappeared accounts');
curator_test(str_contains($curator, "'no longer in consent directory'"), 'disappeared managed accounts are unfollowed');
curator_test(str_contains($curator, 'already followed manually'), 'manual follows are detected and preserved');
curator_test(str_contains($curator, "status='active'"), 'connected active hub sites are excluded');
curator_test(str_contains($curator, '$failures >= 3'), 'dead actors require three failures before retirement');
curator_test(str_contains($relay, 'function sc_relay_actor_is_source'), 'relay recognizes curator-managed sources explicitly');
curator_test(str_contains($relay, "c.state IN ('following','followed') AND f.state='accepted'"), 'only accepted curator follows enrich the relay');
curator_test(str_contains($admin, "['curator_toggle','curator_run']"), 'curator controls are step-up gated');
curator_test(str_contains($portal, '@curator@photoblogs.fyi'), 'portal names the curator identity clearly');
curator_test(str_contains($portal, 'SHOW STORED ACCOUNTS'), 'portal exposes the stored account ledger');
curator_test(str_contains($cron, "\$settings['site_mode']") && str_contains($cron, "=== 'fedistructure'"), 'cron gates curator work to FEDISTRUCTURE');
curator_test(str_contains($cron, "\$settings['node_role']") && str_contains($cron, "=== 'hub'"), 'cron gates curator work to the hub role');
curator_test(str_contains($cron, '$is_fedistructure_hub && function_exists') && str_contains($cron, 'sc_curator_cron($pdo, $settings)'), 'only a FEDISTRUCTURE hub advances the curator');
curator_test(str_contains($cron, "\$curator = null") && str_contains($cron, "is_array(\$curator)"), 'ordinary sites do not report curator activity');
echo $fail === 0 ? "ALL PASS\n" : "{$fail} FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);
// ===== SNAPSMACK EOF =====
