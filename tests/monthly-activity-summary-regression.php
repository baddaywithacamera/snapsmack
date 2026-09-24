<?php
/**
 * Regression checks for the monthly activity summary's pure behaviour.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

require_once dirname(__DIR__) . '/core/monthly-activity-summary.php';

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

[$period, $from, $until, $label] = snap_monthly_period(new DateTimeImmutable('2026-09-24 12:00:00'));
$expect($period === '2026-08', 'period is the completed calendar month');
$expect($from === '2026-08-01' && $until === '2026-09-01', 'period boundaries are exact');
$expect($label === 'August 2026', 'period has a readable label');

$html = snap_monthly_email_html('August 2026', [[
    'name'=>'One & Only', 'url'=>'https://example.com/?a=1&b=2', 'views'=>12, 'visitors'=>7,
    'bots'=>3, 'posts'=>4, 'images'=>9, 'new_posts'=>2, 'new_images'=>5, 'status'=>'online',
], [
    'name'=>'Offline <site>', 'url'=>'https://offline.example', 'views'=>0, 'visitors'=>0,
    'bots'=>0, 'posts'=>1, 'images'=>2, 'new_posts'=>null, 'new_images'=>null, 'status'=>'offline',
]]);
$expect(str_contains($html, '12</strong> human views'), 'fleet totals are rendered');
$expect(str_contains($html, '2</strong> new posts'), 'new content totals are rendered');
$expect(str_contains($html, 'One &amp; Only'), 'site names are escaped');
$expect(str_contains($html, 'a=1&amp;b=2'), 'site URLs are escaped');
$expect(str_contains($html, '(unavailable)'), 'offline sites are identified');

$source = file_get_contents(dirname(__DIR__) . '/core/monthly-activity-summary.php');
$expect(str_contains($source, "if (\$role === 'spoke')"), 'spokes delegate instead of mailing');
$expect(str_contains($source, "'monthly_activity_period', \$period"), 'successful period is persisted for deduplication');
$expect(str_contains($source, "WHERE role='spoke'"), 'hub includes connected spokes');
$expect(str_contains($source, 'period_start='), 'hub requests exact monthly content counts from spokes');

if ($failures) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "monthly activity summary regression: ok\n";
// ===== SNAPSMACK EOF =====
