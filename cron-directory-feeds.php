<?php
/**
 * PHOTOBLOGS.FYI — secure directory RSS aggregator
 *
 * Polls approved directory members, records publishing activity and feed
 * health, and caches at most one real photo-post per blog per calendar day.
 * Run hourly from the photoblogs.fyi host.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only.\n"); }

define('SNAPSMACK_CRON', true);
require_once __DIR__ . '/core/db.php';

const PBDIR_MAX_FEED_BYTES = 2097152;
const PBDIR_DEAD_FAILURES   = 168; // seven days at the recommended hourly cadence

function pbfeed_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?,?)
                   ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)")->execute([$key, $value]);
}

function pbfeed_schema(PDO $pdo): void {
    foreach ([
        "ADD COLUMN IF NOT EXISTS feed_url VARCHAR(500) NOT NULL DEFAULT '' AFTER avatar_url",
        "ADD COLUMN IF NOT EXISTS last_post_at DATETIME NULL AFTER feed_url",
        "ADD COLUMN IF NOT EXISTS last_checked_at DATETIME NULL AFTER last_post_at",
        "ADD COLUMN IF NOT EXISTS last_success_at DATETIME NULL AFTER last_checked_at",
        "ADD COLUMN IF NOT EXISTS feed_status VARCHAR(20) NOT NULL DEFAULT 'unknown' AFTER last_success_at",
        "ADD COLUMN IF NOT EXISTS feed_failures INT UNSIGNED NOT NULL DEFAULT 0 AFTER feed_status",
        "ADD COLUMN IF NOT EXISTS feed_etag VARCHAR(255) NOT NULL DEFAULT '' AFTER feed_failures",
        "ADD COLUMN IF NOT EXISTS feed_last_modified VARCHAR(255) NOT NULL DEFAULT '' AFTER feed_etag",
        "ADD COLUMN IF NOT EXISTS post_count INT UNSIGNED NULL AFTER feed_last_modified",
    ] as $alter) $pdo->exec("ALTER TABLE snap_directory_listings {$alter}");

    $pdo->exec("CREATE TABLE IF NOT EXISTS snap_directory_feed_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        listing_id INT NOT NULL,
        post_url VARCHAR(700) NOT NULL,
        image_url VARCHAR(700) NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        alt_text VARCHAR(500) NOT NULL DEFAULT '',
        published_at DATETIME NOT NULL,
        post_day DATE NOT NULL,
        discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_listing_day (listing_id, post_day),
        UNIQUE KEY uq_post_url (post_url(191)),
        KEY idx_published (published_at),
        KEY idx_listing (listing_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Resolve once and pin cURL to a public address, preventing DNS rebinding. */
function pbfeed_safe_target(string $url): ?array {
    $p = parse_url($url);
    if (!$p || !isset($p['scheme'], $p['host'])) return null;
    $scheme = strtolower((string)$p['scheme']);
    if (!in_array($scheme, ['http', 'https'], true) || isset($p['user']) || isset($p['pass'])) return null;
    $port = isset($p['port']) ? (int)$p['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($port, [80, 443], true)) return null;
    $host = strtolower(rtrim((string)$p['host'], '.'));
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips[] = $host;
    else {
        foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $r) {
            if (!empty($r['ip'])) $ips[] = $r['ip'];
            if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
    }
    foreach (array_unique($ips) as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return [$host, $port, $ip];
        }
    }
    return null;
}

function pbfeed_same_origin_url(string $candidate, string $site_url, bool $post = false): string {
    $url = filter_var(trim($candidate), FILTER_VALIDATE_URL) ?: '';
    if ($url === '') return '';
    if (strcasecmp((string)parse_url($url, PHP_URL_HOST), (string)parse_url($site_url, PHP_URL_HOST)) !== 0) return '';
    if ($post) {
        $path = rtrim((string)parse_url($url, PHP_URL_PATH), '/');
        $query = (string)parse_url($url, PHP_URL_QUERY);
        if ($path === '' && !preg_match('~(?:^|&)s=[^&]+~', $query)) return '';
        if (preg_match('~/(?:page|archive|albums?|blogroll)\.php$~i', $path)) return '';
    }
    return $url;
}

function pbfeed_fetch(array $listing): array {
    $feed_url = trim((string)$listing['feed_url']);
    $target = pbfeed_safe_target($feed_url);
    if (!$target || strcasecmp((string)parse_url($feed_url, PHP_URL_HOST), (string)parse_url($listing['site_url'], PHP_URL_HOST)) !== 0) {
        return [false, 0, '', [], 'unsafe feed URL'];
    }
    [$host, $port, $ip] = $target;
    $body = '';
    $headers = [];
    $request_headers = ['Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9'];
    if ($listing['feed_etag'] !== '') $request_headers[] = 'If-None-Match: ' . str_replace(["\r", "\n"], '', $listing['feed_etag']);
    if ($listing['feed_last_modified'] !== '') $request_headers[] = 'If-Modified-Since: ' . str_replace(["\r", "\n"], '', $listing['feed_last_modified']);

    $ch = curl_init($feed_url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => 0,
        CURLOPT_USERAGENT => 'photoblogs.fyi feed poller/1.0',
        CURLOPT_HTTPHEADER => $request_headers,
        CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $ip)],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
            $n = strlen($line);
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
            return $n;
        },
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > PBDIR_MAX_FEED_BYTES) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($code === 304) return [true, 304, '', $headers, 'not modified'];
    if ($ok === false || $code !== 200 || $body === '') return [false, $code, '', $headers, $err ?: 'HTTP ' . $code];
    return [true, $code, $body, $headers, 'ok'];
}

/** @return array|null Null means invalid XML/feed; an empty array is a healthy empty feed. */
function pbfeed_items(string $xml_raw, array $listing, ?int &$post_count = null): ?array {
    $prior = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML($xml_raw, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prior);
    if (!$loaded) return null;
    $xp = new DOMXPath($doc);
    if (!$xp->query('/*[local-name()="rss" or local-name()="feed"]')->length) return null;
    $reported_count = trim((string)$xp->evaluate('string(/*[local-name()="rss"]/*[local-name()="channel"]/*[local-name()="postCount"][1])'));
    $post_count = preg_match('/^\d+$/', $reported_count) ? (int)$reported_count : null;
    $nodes = $xp->query('//*[local-name()="item" or local-name()="entry"]');
    $out = [];
    foreach ($nodes ?: [] as $node) {
        $text = function (string $expr) use ($xp, $node): string {
            return trim((string)$xp->evaluate('string(' . $expr . ')', $node));
        };
        $post_url = $text('./*[local-name()="link" and not(@rel) or @rel="alternate"][1]/@href');
        if ($post_url === '') $post_url = $text('./*[local-name()="link"][1]');
        if ($post_url === '') $post_url = $text('./*[local-name()="guid" and (@isPermaLink="true" or not(@isPermaLink))][1]');
        $post_url = pbfeed_same_origin_url($post_url, (string)$listing['site_url'], true);
        if ($post_url === '') continue;

        $date_raw = $text('./*[local-name()="pubDate" or local-name()="published" or local-name()="updated"][1]');
        try { $published = new DateTimeImmutable($date_raw); }
        catch (Throwable $e) { continue; }
        $ts = $published->getTimestamp();
        if ($ts > time() + 300) continue;

        $image_url = $text('./*[local-name()="enclosure" and starts-with(@type,"image/")][1]/@url');
        if ($image_url === '') $image_url = $text('./*[local-name()="content" and starts-with(@type,"image/")][1]/@url');
        if ($image_url === '') {
            $html = $text('./*[local-name()="description" or local-name()="content" or local-name()="summary"][1]');
            if ($html !== '') {
                $fragment = new DOMDocument();
                @$fragment->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                $img = $fragment->getElementsByTagName('img')->item(0);
                if ($img) $image_url = (string)$img->getAttribute('src');
            }
        }
        $image_url = pbfeed_same_origin_url($image_url, (string)$listing['site_url']);
        if ($image_url === '') continue;
        // Preserve the publisher's calendar day from the RSS timestamp while
        // storing the sortable timestamp itself in UTC.
        $day = $published->format('Y-m-d');
        $candidate = [
            'post_url' => $post_url,
            'image_url' => $image_url,
            'title' => mb_substr($text('./*[local-name()="title"][1]'), 0, 255),
            'alt_text' => mb_substr($text('./*[local-name()="title"][1]'), 0, 500),
            'published_at' => gmdate('Y-m-d H:i:s', $ts),
            'post_day' => $day,
        ];
        if (!isset($out[$day]) || $candidate['published_at'] > $out[$day]['published_at']) $out[$day] = $candidate;
    }
    return array_values($out);
}

pbfeed_schema($pdo);
if ((int)$pdo->query("SELECT GET_LOCK('photoblogs_directory_feeds', 0)")->fetchColumn() !== 1) exit("Already running.\n");

$ok_count = $fail_count = $item_count = 0;
try {
    $listings = $pdo->query("SELECT * FROM snap_directory_listings WHERE state='active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $save = $pdo->prepare("INSERT INTO snap_directory_feed_items
        (listing_id,post_url,image_url,title,alt_text,published_at,post_day)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          post_url=IF(VALUES(published_at)>=published_at,VALUES(post_url),post_url),
          image_url=IF(VALUES(published_at)>=published_at,VALUES(image_url),image_url),
          title=IF(VALUES(published_at)>=published_at,VALUES(title),title),
          alt_text=IF(VALUES(published_at)>=published_at,VALUES(alt_text),alt_text),
          published_at=GREATEST(published_at,VALUES(published_at))");
    foreach ($listings as $listing) {
        $feed_url = trim((string)$listing['feed_url']);
        if ($feed_url === '') $feed_url = rtrim((string)$listing['site_url'], '/') . '/rss.php';
        $listing['feed_url'] = $feed_url;
        [$fetched, $code, $xml, $headers, $detail] = pbfeed_fetch($listing);
        if (!$fetched) {
            $fail_count++;
            $pdo->prepare("UPDATE snap_directory_listings SET feed_url=?,last_checked_at=NOW(),
                feed_failures=feed_failures+1,
                feed_status=IF(feed_failures+1>=?,'dead','error') WHERE id=?")
                ->execute([$feed_url, PBDIR_DEAD_FAILURES, $listing['id']]);
            continue;
        }
        $reported_post_count = null;
        $items = $code === 304 ? [] : pbfeed_items($xml, $listing, $reported_post_count);
        if ($code !== 304 && $items === null) {
            $fail_count++;
            $pdo->prepare("UPDATE snap_directory_listings SET feed_url=?,last_checked_at=NOW(),
                feed_failures=feed_failures+1,feed_status='error' WHERE id=?")
                ->execute([$feed_url, $listing['id']]);
            continue;
        }
        foreach ($items ?: [] as $item) {
            $save->execute([$listing['id'],$item['post_url'],$item['image_url'],$item['title'],$item['alt_text'],$item['published_at'],$item['post_day']]);
            $item_count++;
        }
        // The public feed promises the last ten daily selections per blog.
        // Prune after each successful refresh so storage remains bounded too.
        $old_ids = $pdo->prepare("SELECT id FROM snap_directory_feed_items
                                  WHERE listing_id=? ORDER BY published_at DESC, id DESC LIMIT 18446744073709551615 OFFSET 10");
        $old_ids->execute([$listing['id']]);
        $old_ids = array_map('intval', $old_ids->fetchAll(PDO::FETCH_COLUMN));
        if ($old_ids) $pdo->exec("DELETE FROM snap_directory_feed_items WHERE id IN (" . implode(',', $old_ids) . ")");
        $latest = $items ? max(array_column($items, 'published_at')) : null;
        $pdo->prepare("UPDATE snap_directory_listings SET feed_url=?,last_checked_at=NOW(),last_success_at=NOW(),
            last_post_at=CASE WHEN ? IS NULL THEN last_post_at
                              WHEN last_post_at IS NULL OR ? > last_post_at THEN ? ELSE last_post_at END,
            feed_status='ok',feed_failures=0,
            post_count=COALESCE(?,post_count),
            feed_etag=?,feed_last_modified=? WHERE id=?")
            ->execute([$feed_url,$latest,$latest,$latest,$reported_post_count,mb_substr((string)($headers['etag'] ?? $listing['feed_etag']),0,255),
                mb_substr((string)($headers['last-modified'] ?? $listing['feed_last_modified']),0,255),$listing['id']]);
        $ok_count++;
    }
    $status = $fail_count ? ($ok_count ? 'partial' : 'failed') : 'ok';
    pbfeed_setting($pdo, 'directory_feed_last_run', gmdate('Y-m-d H:i:s'));
    pbfeed_setting($pdo, 'directory_feed_last_status', $status);
    pbfeed_setting($pdo, 'directory_feed_last_detail', "ok={$ok_count} failed={$fail_count} items={$item_count}");
    echo "Directory feeds: {$status}; ok={$ok_count}; failed={$fail_count}; items={$item_count}\n";
} catch (Throwable $e) {
    pbfeed_setting($pdo, 'directory_feed_last_run', gmdate('Y-m-d H:i:s'));
    pbfeed_setting($pdo, 'directory_feed_last_status', 'failed');
    pbfeed_setting($pdo, 'directory_feed_last_detail', mb_substr($e->getMessage(), 0, 500));
    fwrite(STDERR, "Directory feed failure: " . $e->getMessage() . "\n");
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('photoblogs_directory_feeds')");
}

// ===== SNAPSMACK EOF =====
