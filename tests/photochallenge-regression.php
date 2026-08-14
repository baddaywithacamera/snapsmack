<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

require_once __DIR__ . '/../core/photochallenge.php';

$failures = [];
function pc_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

$settings = [
    'photochallenge_enabled' => '1',
    'photochallenge_tag' => '#PhotoFri!',
    'photochallenge_tz' => 'UTC',
];
pc_test(pc_enabled($settings), 'enabled profile was reported disabled');
pc_test(pc_tag($settings) === 'photofri', 'challenge tag normalization failed');

$open = pc_window($settings, strtotime('2026-09-04 12:00:00 UTC'));
$closed = pc_window($settings, strtotime('2026-09-06 02:00:00 UTC'));
pc_test($open['open'] === true, 'Friday challenge window was not open');
pc_test($open['start'] === '2026-09-03 10:00:00', 'global window start was incorrect');
pc_test($open['end'] === '2026-09-05 12:00:00', 'global window end was incorrect');
$closed = pc_window($settings, strtotime('2026-09-05 12:00:00 UTC'));
pc_test($closed['open'] === false, 'window remained open at its exclusive end');

$photo = file_get_contents(__DIR__ . '/../core/photochallenge.php');
$sv = file_get_contents(__DIR__ . '/../core/smackverse.php');
$schema = file_get_contents(__DIR__ . '/../database/schema/snapsmack_canonical.sql');
$htaccess = file_get_contents(__DIR__ . '/../core/htaccess-template');
$admin = file_get_contents(__DIR__ . '/../smack-photochallenge.php');
$sidebar = file_get_contents(__DIR__ . '/../core/sidebar-photochallenge.php');
$admin_header = file_get_contents(__DIR__ . '/../core/admin-header.php');
$installer = file_get_contents(__DIR__ . '/../install.php');
$fedup = file_get_contents(__DIR__ . '/../fedup.php');
$packager = file_get_contents(__DIR__ . '/../smack-central/sc-release.php');

foreach (['pc_participants', 'pc_hall_of_fame', 'pc_engagement', 'pc_outbound_boosts'] as $table) {
    pc_test(str_contains($schema, "CREATE TABLE IF NOT EXISTS `{$table}`"), "{$table} is absent from canonical schema");
}
foreach (['pc_on_follow', 'pc_on_leave', 'pc_record_like', 'pc_record_boost', 'pc_remove_engagement'] as $hook) {
    pc_test(str_contains($sv, $hook), "SMACKVERSE is missing {$hook} integration");
}
pc_test(str_contains($photo, 'SELECT id, week_key'), 'Hall of Fame rows omit the admin toggle id');
pc_test(str_contains($photo, 'tags_json'), 'board does not require structured ActivityPub hashtags');
pc_test(str_contains($photo, '> 5'), 'per-author five-entry cap is missing');
pc_test(str_contains($photo, 'ORDER BY t.published ASC'),
    'entry cap must retain an author\'s first five valid entries');
pc_test(str_contains($photo, "JOIN pc_participants p ON p.actor_url = t.actor_url")
    && str_contains($photo, "p.state = 'active'"),
    'board admission is not restricted to active participants');
pc_test(str_contains($photo, 'AND t.is_boost = 0'), 'board admits boosted posts as entries');
pc_test(str_contains($photo, 'count($media) !== 1'), 'board does not enforce exactly one image');
pc_test(str_contains($photo, 'sv_boost_remote(')
    && str_contains($sv, 'pc_maybe_boost_entry'),
    'qualified original entries are not automatically boosted');
pc_test(str_contains($admin, 'Thursday 10:00 UTC through Saturday 12:00 UTC'),
    'admin describes a non-canonical challenge window');
foreach (['THE GOOD SHIT', 'FEDIVERSE', 'CHALLENGE ME', 'THE BORING SHIT'] as $heading) {
    pc_test(str_contains($sidebar, $heading), "photo challenge sidebar is missing {$heading}");
}
foreach (['Categories', 'Albums', 'Collections', 'Blogroll', 'User Manual',
          'Community Forum', 'Big Wheel', 'Pimpmobile'] as $excluded) {
    pc_test(!str_contains($sidebar, $excluded), "photo challenge sidebar exposes {$excluded}");
}
pc_test(str_contains($sidebar, 'Static Pages'), 'photo challenge sidebar is missing static pages');
pc_test(str_contains($admin_header, "\$_admin_is_photochallenge")
    && str_contains($admin_header, "? 'midnight-lime'"),
    'photo challenge admin does not force the Midnight Lime theme');
pc_test(str_contains($installer, "'photo-challenge', 'daily-photo', 'smackcast'"),
    'FEDISTRUCTURE installer profiles are missing');
pc_test(str_contains($fedup, 'latest-fedistructure.json')
    && str_contains($fedup, "FEDUP_RELEASE_PUBKEY"),
    'fedup.php is not bound to the signed FEDISTRUCTURE manifest');
pc_test(str_contains($packager, 'snapsmack-fedistructure-')
    && str_contains($packager, 'latest-fedistructure.json'),
    'Release Packager does not publish the FEDISTRUCTURE sibling artifact');
pc_test(str_contains($htaccess, '^board/?$'), 'pretty board route is missing');
pc_test(str_contains($htaccess, '^hall-of-fame/?$'), 'pretty Hall of Fame route is missing');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: Photo Challenge build regression suite\n";
// ===== SNAPSMACK EOF =====
