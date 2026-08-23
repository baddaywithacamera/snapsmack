<?php
/**
 * SNAPSMACK — FEDBOARD regression checks.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
$failures = [];

function fb_expect(bool $condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}

$read = static fn(string $path): string => file_get_contents($root . '/' . $path) ?: '';
$constants = $read('core/constants.php');
$roster = $read('core/fedboard.php');
$api = $read('core/multisite-api.php');
$consumer = $read('sso.php');
$hub = $read('smack-multisite-sso.php');
$multisite = $read('smack-multisite.php');
$mesh = $read('core/mesh-helpers.php');
$pixel = $read('pixel.php');
$css = $read('assets/css/ss-pixel.css');
$js = $read('assets/js/ss-pixel.js');
$help = $read('smack-help.php');
$migration = $read('migrations/migrate-fedboard-sso.sql');
$updater = $read('core/updater.php');

fb_expect(str_contains($constants, "SNAPSMACK_VERSION_SHORT', '0.7.548'"), 'release must be version 0.7.548');
fb_expect(str_contains($constants, "SNAPSMACK_VERSION_CODENAME', 'FEDBOARD'"), 'release codename must be FEDBOARD');
fb_expect(str_contains($migration, 'token_hash') && !str_contains($migration, '`token` VARCHAR'), 'migration must store only ticket hashes');
fb_expect(str_contains(strtolower($migration), "enum('admin','fedboard')"), 'tickets must bind an allowlisted destination');
fb_expect(str_contains($updater, "'migrate-fedboard-sso.sql'"), 'updater must apply the FEDBOARD migration');

fb_expect(str_contains($api, "['admin','fedboard']"), 'SSO API must allowlist destinations');
fb_expect(str_contains($api, "hash('sha256', \$token)"), 'SSO API must hash tickets at rest');
fb_expect(str_contains($api, 'INTERVAL 5 MINUTE'), 'SSO tickets must expire after five minutes');
fb_expect(str_contains($consumer, 'FOR UPDATE') && str_contains($consumer, 'DELETE FROM snap_multisite_sso_tokens'), 'ticket consumption must be atomic and single-use');
fb_expect(str_contains($consumer, "\$destination === 'fedboard' ? 'pixel.php' : 'smack-admin.php'"), 'server-bound destination must control the post-login page');
fb_expect(str_contains($consumer, 'session_regenerate_id(true)'), 'SSO login must rotate the session id');
fb_expect(substr_count($consumer . $hub, "Referrer-Policy: no-referrer") >= 2, 'ticket redirects must suppress referrers');

fb_expect(str_contains($hub, "hash_equals(fb_base_url((string)\$candidate['site_url']), \$fedboard_site)"), 'hub must match FEDBOARD targets against its roster');
fb_expect(str_contains($hub, "role='spoke' AND status='active' AND maintenance_mode=0"), 'hub must refuse inactive or maintenance targets');
fb_expect(str_contains($multisite, "csrf_url('smack-multisite-sso.php?sat="), 'ordinary Remote Login must retain CSRF protection');
fb_expect(str_contains($multisite, 'fedboard_sso_enabled'), 'heartbeat storage must retain spoke FEDBOARD consent');
fb_expect(str_contains($mesh, "'fedboard_sso_enabled'"), 'mesh roster must propagate FEDBOARD consent state');

fb_expect(str_contains($roster, 'strnatcasecmp'), 'FEDBOARD roster must sort names naturally and case-insensitively');
fb_expect(str_contains($roster, "version_compare(\$version, '0.7.548', '>=')"), 'older fleet members must remain visible but unavailable');
fb_expect(str_contains($pixel, 'aria-current="page"') && str_contains($pixel, 'fb-cursor'), 'active site must be identified accessibly and visually');
fb_expect(!str_contains($pixel, 'target="_blank"'), 'FEDBOARD page must keep switching in the same tab');
fb_expect(str_contains($css, 'prefers-reduced-motion: reduce'), 'cursor must respect reduced-motion preferences');
fb_expect(str_contains($js, 'fedboard-help-dismissed'), 'first-use FEDBOARD guidance must be dismissible');
fb_expect(str_contains($help, "\$help_topics['fedboard']") && str_contains($help, 'RETURN TO FEDBOARD'), 'FEDBOARD help must ship with a return path');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "PASS: FEDBOARD regression suite\n";
// ===== SNAPSMACK EOF =====
