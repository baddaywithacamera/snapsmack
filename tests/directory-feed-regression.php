<?php
/** Static security and architecture guards for the directory feed poller. */
$root = dirname(__DIR__);
$cron = file_get_contents($root . '/cron-directory-feeds.php');
$feed = file_get_contents($root . '/feed.php');
$shortcode_feed = file_get_contents($root . '/core/photoblogs-feed.php');
$api = file_get_contents($root . '/directory-api.php');
$payload = file_get_contents($root . '/core/photoblogs-directory.php');
$rss = file_get_contents($root . '/rss.php');
$directory_view = file_get_contents($root . '/core/photoblogs-directory-view.php');
$fail = [];
$expect = function (bool $ok, string $message) use (&$fail): void { if (!$ok) $fail[] = $message; };

$expect(str_contains($payload, "'feed_url'"), 'directory registration must advertise RSS');
$expect(str_contains($api, 'last_post_at'), 'hub must store publishing activity');
$expect(str_contains($cron, "GET_LOCK('photoblogs_directory_feeds'"), 'poller must prevent overlapping runs');
$expect(str_contains($cron, 'FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE'), 'poller must block private/reserved targets');
$expect(str_contains($cron, 'CURLOPT_RESOLVE'), 'poller must pin validated DNS results');
$expect(str_contains($cron, 'CURLOPT_FOLLOWLOCATION => false'), 'poller must not follow unvalidated redirects');
$expect(str_contains($cron, 'PBDIR_MAX_FEED_BYTES'), 'poller must cap response size');
$expect(str_contains($cron, 'LIBXML_NONET'), 'XML parsing must disable network access');
$expect(str_contains($cron, 'uq_listing_day'), 'cache must enforce one item per blog per day');
$expect(str_contains($cron, 'OFFSET 10'), 'cache must retain only the last ten daily posts per blog');
$expect(str_contains($cron, 'OFFSET 10'), 'public feed cache must cap each blog at ten posts');
$expect(str_contains($rss, 'snapsmack:postCount'), 'member RSS must advertise the real published-post count');
$expect(str_contains($cron, "local-name()=\"postCount\""), 'directory poller must read the published-post count');
$expect(str_contains($directory_view, 'pbf-directory-table'), 'directory must render a compact semantic table');
$expect(str_contains($cron, "feed_status=IF(feed_failures+1>=?,'dead','error')"), 'poller must age repeated failures into dead state');
$expect(str_contains($feed, '/page.php?slug=feed'), 'legacy feed route must redirect to the skinned CMS page');
$expect(str_contains($shortcode_feed, "\$r['post_url']"), 'feed images must link to RSS post permalinks');
$expect(str_contains($shortcode_feed, 'FROM snap_directory_feed_items f'), 'CMS feed shortcode must read the current directory RSS cache');
$expect(str_contains($shortcode_feed, "l.state = 'active' AND l.feed_status <> 'dead'"), 'CMS feed shortcode must exclude inactive and dead blogs');

if ($fail) { fwrite(STDERR, implode(PHP_EOL, $fail) . PHP_EOL); exit(1); }
echo "directory feed regression: ok\n";
