<?php
/**
 * SNAPSMACK — replay-access-log-stats.php (one-off CLI, 0.7.874D)
 *
 * Rebuild the page hits that the strict runtime never recorded.
 *
 * WHY: index.php loads core/stats-logger.php at the top and calls
 * snapsmack_log_hit() in seven places, every one of them BELOW the strict
 * branch — and the strict branch renders the page and exits. From the day a
 * site was converted to the strict runtime it wrote no rows at all. Nothing
 * was deleted; the visits were simply never written down. 0.7.874D fixes the
 * ordering. This replays what was missed, from the web server's own access
 * log, which recorded every one of those requests at the time.
 *
 * Every column of a snap_stats row is derivable from a combined-format log
 * line, and crucially snapsmack_hash_ip() salts with the DATE alone
 * ("Y-m-d::snapsmack-stats"), so a past day's hashes reproduce exactly and
 * unique-visitor counts come back true rather than approximated.
 *
 * NOT RECOVERABLE, and not faked here:
 *   - dwell_ms (scroll / reading time). The browser beacons that after the
 *     page loads; it never reached the server, so it is in no log. Replayed
 *     days will show views with no reading time, and that is the honest
 *     result. Do not let anyone tell you it can be estimated.
 *   - country, where GeoIP came from a web server variable rather than the
 *     log line. Left null, exactly as an unconfigured GeoIP leaves it.
 *
 * SAFETY:
 *   - Dry run by default. --apply writes.
 *   - Only touches dates that currently have ZERO rows in snap_stats. A day
 *     with any real data is skipped and named, so nothing recorded is
 *     overwritten, doubled or reinterpreted.
 *   - Idempotent: a second run finds those dates populated and does nothing.
 *   - Reads the log; never writes to it or rotates it.
 *
 * USAGE (from a site directory):
 *   php replay-access-log-stats.php --log=/var/log/nginx/site.access.log
 *   php replay-access-log-stats.php --log='/var/log/nginx/site.access.log*' --apply
 *
 *   --log=GLOB     one or more access logs; .gz is read transparently
 *   --host=NAME    only lines for this vhost (for a shared log; default: the
 *                  site's own BASE_URL host)
 *   --from=DATE    earliest date to replay   (default: first date in the log)
 *   --to=DATE      latest date to replay     (default: yesterday; today is
 *                  left alone because it is still being written normally)
 *   --apply        actually insert, then rebuild snap_stats_daily
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$base = __DIR__;
require_once $base . '/core/config.php';
require_once $base . '/core/stats-logger.php';

// ── arguments ───────────────────────────────────────────────────────────────
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) $opts[$m[1]] = $m[2] ?? true;
}
$apply   = !empty($opts['apply']);
$logGlob = (string)($opts['log'] ?? '');
if ($logGlob === '') {
    fwrite(STDERR, "--log=/path/to/access.log is required (a glob is fine; .gz is read)\n");
    exit(2);
}
$logs = glob($logGlob, GLOB_BRACE) ?: [];
if (!$logs) { fwrite(STDERR, "no log files matched: {$logGlob}\n"); exit(2); }
sort($logs);

$host = (string)($opts['host'] ?? parse_url((string)(defined('BASE_URL') ? BASE_URL : ''), PHP_URL_HOST));
$from = (string)($opts['from'] ?? '');
$to   = (string)($opts['to'] ?? date('Y-m-d', strtotime('yesterday')));

// ── the site's own route map, so a path is classified the way the router
//    classifies it rather than by guesswork ──────────────────────────────────
$settings = [];
foreach ($pdo->query("SELECT setting_key, setting_val FROM snap_settings") as $row) {
    $settings[$row['setting_key']] = $row['setting_val'];
}
$manifest = [];
$activeSkin = (string)($settings['active_skin'] ?? '');
$manifestPath = $base . '/skins/' . $activeSkin . '/manifest.json';
if ($activeSkin !== '' && is_file($manifestPath)) {
    $decoded = json_decode((string)file_get_contents($manifestPath), true);
    if (is_array($decoded)) $manifest = $decoded;
}
require_once $base . '/core/public-route-aliases.php';
$aliases = snapsmack_public_route_aliases($settings, $manifest);   // slug => view

// The site's own static pages, so /about is filed as a page and not as a
// photograph that happens to share its name.
$pageSlugs = [];
try {
    foreach ($pdo->query("SELECT slug FROM snap_pages") as $row) {
        $pageSlugs[strtolower((string)$row['slug'])] = true;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "  (could not read snap_pages; bare slugs will be filed as photographs)
");
}

$viewToType = [
    'archive' => 'archive', 'diary' => 'archive', 'albums' => 'archive',
    'collections' => 'archive', 'categories' => 'archive',
    'blogroll' => 'page',
];

/**
 * Classify a request path the way the public router would.
 *
 * $pageSlugs is the site's own set of static page slugs. Without it /about
 * is a bare slug and would be filed as a photograph -- the same confusion
 * between a page and a photograph that broke the static pages in the first
 * place. Asked of the database once, it costs nothing and is exact.
 */
function replay_classify(string $path, array $aliases, array $viewToType, array $pageSlugs = []): ?array
{
    // The endless-scroll batch is "/?pg=wall&format=json&c=2" -- its PATH is
    // "/", so dropping the query first would have counted every scroll as a
    // visit to the front page. 0.7.874D deliberately answers that request
    // before the live counter; the replay must agree with it.
    $query = (string)(parse_url($path, PHP_URL_QUERY) ?? '');
    if ($query !== '') {
        parse_str($query, $q);
        if (($q['format'] ?? '') === 'json' || isset($q['pg'])) return null;
    }
    $path = parse_url($path, PHP_URL_PATH) ?? $path;
    $path = '/' . ltrim($path, '/');

    // Anything that is not a page view. Assets, feeds, the endless-scroll
    // batch, admin and the fediverse plumbing were never counted live and
    // must not be counted now.
    if (preg_match('#\.(?:css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|map|txt|xml|json)$#i', $path)) return null;
    if (preg_match('#^/(?:assets|media_assets|uploads|skins|cdn-cgi|\.well-known)/#i', $path)) return null;
    if (preg_match('#^/(?:smack-|admin|login|logout|api/|inbox|outbox|actor|nodeinfo|feed)#i', $path)) return null;

    if ($path === '/' || $path === '/index.php') return ['landing', null];

    $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
    if (!$segments) return ['landing', null];
    $first = strtolower($segments[0]);

    if ($first === 'archive.php') return ['archive', null];
    if ($first === 'page.php')    return ['page', $segments[1] ?? null];
    if ($first === 'tag')         return ['hashtag', $segments[1] ?? null];
    if ($first === 'search')      return ['search', null];
    if (isset($aliases[$first]))  return [$viewToType[$aliases[$first]] ?? 'page', null];

    // A bare slug is a static page if the site has one by that name, and a
    // photograph otherwise -- which is the overwhelming majority of them.
    if (isset($pageSlugs[$first])) return ['page', $segments[0]];
    return ['image', $segments[0]];
}

/** Combined log format: host? ip - - [date] "VERB path proto" code size "ref" "ua" */
function replay_parse(string $line): ?array
{
    if (!preg_match(
        '/^(?:(?<vhost>\S+)\s+)?(?<ip>\S+)\s+\S+\s+\S+\s+\[(?<ts>[^\]]+)\]\s+'
        . '"(?<verb>[A-Z]+)\s+(?<path>\S+)[^"]*"\s+(?<code>\d{3})\s+\S+'
        . '(?:\s+"(?<ref>[^"]*)"\s+"(?<ua>[^"]*)")?/',
        $line, $m
    )) return null;
    if (($m['verb'] ?? '') !== 'GET') return null;
    if (!in_array((int)($m['code'] ?? 0), [200, 304], true)) return null;
    $when = strtotime(str_replace(':', ' ', substr($m['ts'], 0, 11)) . substr($m['ts'], 11));
    if ($when === false) return null;
    return [
        'vhost' => $m['vhost'] ?? '',
        'ip'    => $m['ip'],
        'when'  => $when,
        'path'  => $m['path'],
        'ref'   => $m['ref'] ?? '',
        'ua'    => $m['ua'] ?? '',
    ];
}

// ── which dates are actually missing ────────────────────────────────────────
$present = [];
foreach ($pdo->query("SELECT DISTINCT DATE(hit_at) d FROM snap_stats") as $row) {
    $present[$row['d']] = true;
}

echo "SnapSmack stats replay\n";
echo "  logs     : " . count($logs) . " file(s)\n";
echo "  host     : " . ($host !== '' ? $host : '(any)') . "\n";
echo "  window   : " . ($from !== '' ? $from : 'start of log') . " .. {$to}\n";
echo "  mode     : " . ($apply ? "APPLY — rows will be written" : "dry run — nothing written") . "\n\n";

$rows = [];      // date => list of hits
$skippedDay = [];
$lines = $parsed = 0;

foreach ($logs as $file) {
    $fh = preg_match('/\.gz$/', $file) ? gzopen($file, 'rb') : fopen($file, 'rb');
    if (!$fh) { fwrite(STDERR, "  cannot read {$file}\n"); continue; }
    $read = preg_match('/\.gz$/', $file) ? 'gzgets' : 'fgets';
    while (($line = $read($fh)) !== false) {
        $lines++;
        $hit = replay_parse(rtrim($line, "\r\n"));
        if (!$hit) continue;
        if ($host !== '' && $hit['vhost'] !== '' && stripos($hit['vhost'], $host) === false) continue;
        $day = date('Y-m-d', $hit['when']);
        if ($from !== '' && $day < $from) continue;
        if ($day > $to) continue;
        if (isset($present[$day])) { $skippedDay[$day] = true; continue; }
        $class = replay_classify($hit['path'], $aliases, $viewToType, $pageSlugs);
        if ($class === null) continue;
        if (($hit['ua'] ?? '') === '') continue;   // the live tracker skips these too
        $hit['page_type'] = $class[0];
        $hit['page_slug'] = $class[1];
        $rows[$day][] = $hit;
        $parsed++;
    }
    preg_match('/\.gz$/', $file) ? gzclose($fh) : fclose($fh);
}

if ($skippedDay) {
    ksort($skippedDay);
    echo "  SKIPPED (already has rows — nothing recorded is touched): "
        . implode(', ', array_keys($skippedDay)) . "\n\n";
}
if (!$rows) {
    echo "  Nothing to replay. Either the log does not reach back that far, or\n"
       . "  every day in the window already has data.\n";
    exit(0);
}

ksort($rows);
printf("  %-12s %8s %8s %8s\n", 'DATE', 'HUMAN', 'BOT', 'UNIQUE');
$totalHuman = $totalBot = 0;
foreach ($rows as $day => $hits) {
    $human = $bot = 0; $ips = [];
    foreach ($hits as $h) {
        if (snapsmack_is_bot($h['ua'])) { $bot++; continue; }
        $human++; $ips[$h['ip']] = true;
    }
    $totalHuman += $human; $totalBot += $bot;
    printf("  %-12s %8d %8d %8d\n", $day, $human, $bot, count($ips));
}
printf("  %-12s %8d %8d\n\n", 'TOTAL', $totalHuman, $totalBot);
printf("  read %d log lines, %d replayable page views\n", $lines, $parsed);

if (!$apply) {
    echo "\n  Dry run. Re-run with --apply to write these rows, then the daily\n"
       . "  rollup is rebuilt automatically.\n";
    exit(0);
}

// ── write ───────────────────────────────────────────────────────────────────
$insert = $pdo->prepare("
    INSERT INTO `snap_stats`
        (`hit_at`, `page_type`, `page_slug`, `image_id`,
         `referrer`, `referrer_host`, `user_agent`,
         `browser`, `os`, `country`, `ip_hash`,
         `is_bot`, `search_term`)
    VALUES (?, ?, ?, NULL, NULL, ?, NULL, ?, ?, NULL, ?, ?, NULL)
");
$imageId = $pdo->prepare("SELECT id FROM snap_images WHERE img_slug = ? LIMIT 1");
$slugCache = [];
$written = 0;

$pdo->beginTransaction();
try {
    foreach ($rows as $day => $hits) {
        // The live hash salts with the date alone, so a past day reproduces
        // exactly and unique visitors are true rather than approximated.
        $salt = $day . '::snapsmack-stats';
        foreach ($hits as $h) {
            $ua = $h['ua'];
            $parsedUa = snapsmack_parse_ua($ua);
            $insert->execute([
                date('Y-m-d H:i:s', $h['when']),
                $h['page_type'],
                $h['page_slug'],
                snapsmack_referrer_host($h['ref']),
                $parsedUa['browser'],
                $parsedUa['os'],
                hash('sha256', $h['ip'] . $salt),
                snapsmack_is_bot($ua) ? 1 : 0,
            ]);
            $written++;
        }
        echo "  wrote " . count($hits) . " rows for {$day}\n";
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\n  FAILED, nothing written: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n  {$written} rows written. Rebuilding snap_stats_daily...\n";
$days = snapsmack_backfill_daily($pdo);
echo "  rebuilt {$days} daily rows.\n";
echo "  Reading time stays empty for these days: the browser beacons it and it\n";
echo "  never reached the server, so it is in no log. Views and visitors are real.\n";

// ===== SNAPSMACK EOF =====
