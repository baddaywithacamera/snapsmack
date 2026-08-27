<?php
/**
 * Static security and architecture guards for the directory feed poller + feed.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
$root = dirname(__DIR__);
$cron = file_get_contents($root . '/cron-directory-feeds.php');
$feed = file_get_contents($root . '/feed.php');
$api = file_get_contents($root . '/directory-api.php');
$payload = file_get_contents($root . '/core/photoblogs-directory.php');
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
// Feed model: up to 20 real posts per blog, last four weeks (NOT one-per-day).
$expect(str_contains($cron, 'PBDIR_MAX_PER_BLOG') && str_contains($cron, 'OFFSET " . PBDIR_MAX_PER_BLOG'),
    'cache must retain the last 20 real posts per blog');
$expect(str_contains($cron, "DROP INDEX uq_listing_day"), 'poller must migrate off the old one-per-day unique key');
$expect(str_contains($cron, 'PBDIR_WINDOW_DAYS') && str_contains($cron, 'INTERVAL " . PBDIR_WINDOW_DAYS . " DAY'),
    'cache must prune anything older than the four-week window');
$expect(str_contains($cron, '$out[$post_url] = $candidate'), 'poller must keep one entry per post, not per day');
$expect(str_contains($feed, '>= 20'), 'public feed must show up to 20 posts per blog');
$expect(str_contains($feed, 'INTERVAL 28 DAY'), 'public feed must limit to the last four weeks');
$expect(str_contains($cron, "feed_status=IF(feed_failures+1>=?,'dead','error')"), 'poller must age repeated failures into dead state');
$expect(str_contains($feed, "\$item['post_url']"), 'feed tiles must link to RSS post permalinks');
$expect(!str_contains($feed, "\$item['site_url']"), 'feed must never fall back to a site landing page');
// Feed presentation: square tiles, title on hover, opens origin in a new tab.
$expect(str_contains($feed, 'repeat(3,1fr)'), 'feed must be a three-across grid');
$expect(str_contains($feed, 'aspect-ratio:1/1'), 'feed tiles must be square');
$expect(str_contains($feed, 'target="_blank"'), 'feed tiles must open the origin post in a new tab');
$expect(str_contains($feed, ':hover .cap') || str_contains($feed, ':hover,.tile:focus-visible'), 'post title must reveal on hover');
$expect(str_contains($feed, 'mt_rand') && str_contains($feed, 'De-clump'), 'feed order must jitter recency and de-clump blogs');

if ($fail) { fwrite(STDERR, implode(PHP_EOL, $fail) . PHP_EOL); exit(1); }
echo "directory feed regression: ok\n";
// ===== SNAPSMACK EOF =====
