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

foreach (['pc_participants', 'pc_hall_of_fame', 'pc_engagement'] as $table) {
    pc_test(str_contains($schema, "CREATE TABLE IF NOT EXISTS `{$table}`"), "{$table} is absent from canonical schema");
}
foreach (['pc_on_follow', 'pc_on_leave', 'pc_record_like', 'pc_record_boost', 'pc_remove_engagement'] as $hook) {
    pc_test(str_contains($sv, $hook), "SMACKVERSE is missing {$hook} integration");
}
pc_test(str_contains($photo, 'SELECT id, week_key'), 'Hall of Fame rows omit the admin toggle id');
pc_test(str_contains($photo, 'tags_json'), 'board does not require structured ActivityPub hashtags');
pc_test(str_contains($photo, '> 5'), 'per-author five-entry cap is missing');
pc_test(str_contains($htaccess, '^board/?$'), 'pretty board route is missing');
pc_test(str_contains($htaccess, '^hall-of-fame/?$'), 'pretty Hall of Fame route is missing');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: Photo Challenge build regression suite\n";
// ===== SNAPSMACK EOF =====
