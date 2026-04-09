<?php
/**
 * SNAPSMACK - Traffic Stats Logger
 * Alpha v0.7.9
 *
 * Lightweight hit tracker included at the top of public-facing controllers
 * (index.php, archive.php, page.php, blog.php). Logs one row per page view
 * to snap_stats. Excludes bots by default. Hashes IPs with a daily rotating
 * salt for unique visitor counting without storing PII.
 *
 * Usage:  require_once __DIR__ . '/core/stats-logger.php';
 *         snapsmack_log_hit($pdo, $settings, [
 *             'page_type' => 'image',
 *             'page_slug' => $img['img_slug'],
 *             'image_id'  => $img['id'],
 *         ]);
 */

/**
 * Parse browser and OS from a user-agent string.
 * Intentionally simple — covers the big five browsers and major OSes.
 *
 * @param  string $ua  Raw User-Agent header
 * @return array       ['browser' => string, 'os' => string]
 */
function snapsmack_parse_ua($ua) {
    $browser = 'Other';
    $os      = 'Other';

    // --- BROWSER ---
    if (preg_match('/Edg[e\/]/i', $ua))            $browser = 'Edge';
    elseif (preg_match('/OPR|Opera/i', $ua))        $browser = 'Opera';
    elseif (preg_match('/Vivaldi/i', $ua))           $browser = 'Vivaldi';
    elseif (preg_match('/Brave/i', $ua))             $browser = 'Brave';
    elseif (preg_match('/Firefox/i', $ua))           $browser = 'Firefox';
    elseif (preg_match('/Chrome/i', $ua))            $browser = 'Chrome';
    elseif (preg_match('/Safari/i', $ua))            $browser = 'Safari';
    elseif (preg_match('/MSIE|Trident/i', $ua))      $browser = 'IE';

    // --- OS ---
    if (preg_match('/Windows NT 10/i', $ua))         $os = 'Windows 10+';
    elseif (preg_match('/Windows/i', $ua))            $os = 'Windows';
    elseif (preg_match('/Macintosh|Mac OS/i', $ua))   $os = 'macOS';
    elseif (preg_match('/iPhone/i', $ua))             $os = 'iOS';
    elseif (preg_match('/iPad/i', $ua))               $os = 'iPadOS';
    elseif (preg_match('/Android/i', $ua))            $os = 'Android';
    elseif (preg_match('/Linux/i', $ua))              $os = 'Linux';
    elseif (preg_match('/CrOS/i', $ua))               $os = 'ChromeOS';

    return ['browser' => $browser, 'os' => $os];
}

/**
 * Detect known bot/spider user agents.
 *
 * @param  string $ua  Raw User-Agent header
 * @return bool
 */
function snapsmack_is_bot($ua) {
    $bots = [
        'bot', 'crawl', 'spider', 'slurp', 'wget', 'curl',
        'fetch', 'scraper', 'archive', 'mediapartners',
        'google', 'bing', 'yandex', 'baidu', 'duckduck',
        'facebook', 'twitter', 'linkedin', 'whatsapp',
        'semrush', 'ahrefs', 'mj12bot', 'dotbot',
        'bytespider', 'amazonbot', 'claudebot', 'gptbot',
        'applebot', 'petalbot',
    ];
    $ua_lower = strtolower($ua);
    foreach ($bots as $bot) {
        if (strpos($ua_lower, $bot) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Extract the hostname from a referrer URL.
 *
 * @param  string|null $ref
 * @return string|null
 */
function snapsmack_referrer_host($ref) {
    if (empty($ref)) return null;
    $host = parse_url($ref, PHP_URL_HOST);
    if (!$host) return null;
    // Strip www.
    return preg_replace('/^www\./i', '', strtolower($host));
}

/**
 * Extract search term from referrer if it came from a search engine.
 * Works with Google, Bing, DuckDuckGo, Yahoo, Yandex.
 *
 * @param  string|null $ref
 * @return string|null
 */
function snapsmack_extract_search_term($ref) {
    if (empty($ref)) return null;
    $query = parse_url($ref, PHP_URL_QUERY);
    if (!$query) return null;
    parse_str($query, $params);

    // Google, Bing, Yahoo, most engines
    if (!empty($params['q'])) return substr($params['q'], 0, 255);
    // Yandex
    if (!empty($params['text'])) return substr($params['text'], 0, 255);

    return null;
}

/**
 * Hash an IP address with a daily rotating salt for privacy.
 * Same IP on the same day produces the same hash (for uniques counting)
 * but different hashes across days (no long-term tracking).
 *
 * @param  string $ip
 * @return string  64-char hex string (SHA-256)
 */
function snapsmack_hash_ip($ip) {
    $daily_salt = date('Y-m-d') . '::snapsmack-stats';
    return hash('sha256', $ip . $daily_salt);
}

/**
 * Log a single page hit to snap_stats.
 *
 * @param  PDO   $pdo       Database connection
 * @param  array $settings  Site settings (from snap_settings)
 * @param  array $meta      Hit metadata:
 *                            - page_type  (string) image|archive|landing|page|blog|hashtag
 *                            - page_slug  (string|null)
 *                            - image_id   (int|null)
 * @return void
 */
function snapsmack_log_hit($pdo, $settings, $meta = []) {
    // Bail if stats are disabled
    if (($settings['stats_enabled'] ?? '1') !== '1') return;

    // Exclude logged-in admin if configured
    if (($settings['stats_exclude_admin'] ?? '1') === '1') {
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_login'])) {
            return;
        }
    }

    $ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Skip empty UA (almost always a bot or scanner)
    if (empty($ua)) return;

    $is_bot = snapsmack_is_bot($ua) ? 1 : 0;
    $parsed = snapsmack_parse_ua($ua);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO `snap_stats`
                (`hit_at`, `page_type`, `page_slug`, `image_id`,
                 `referrer`, `referrer_host`, `user_agent`,
                 `browser`, `os`, `country`, `ip_hash`,
                 `is_bot`, `search_term`)
            VALUES
                (NOW(), ?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?)
        ");
        $stmt->execute([
            $meta['page_type'] ?? 'unknown',
            $meta['page_slug'] ?? null,
            $meta['image_id']  ?? null,
            $referrer ?: null,
            snapsmack_referrer_host($referrer),
            substr($ua, 0, 500),
            $parsed['browser'],
            $parsed['os'],
            null, // country — populated by GeoIP if available (future)
            snapsmack_hash_ip($ip),
            $is_bot,
            $meta['search_term'] ?? snapsmack_extract_search_term($referrer),
        ]);
    } catch (PDOException $e) {
        // Silently fail — stats should never break the page
        // Table might not exist yet if migration hasn't run
    }
}

/**
 * Roll up yesterday's raw stats into snap_stats_daily.
 * Called by cron or manually from the stats admin page.
 *
 * @param  PDO    $pdo
 * @param  string $date  YYYY-MM-DD (defaults to yesterday)
 * @return void
 */
function snapsmack_rollup_daily($pdo, $date = null) {
    if (!$date) $date = date('Y-m-d', strtotime('-1 day'));

    try {
        // Total views (excluding bots)
        $views = $pdo->prepare("SELECT COUNT(*) FROM snap_stats WHERE DATE(hit_at) = ? AND is_bot = 0");
        $views->execute([$date]);
        $total_views = (int)$views->fetchColumn();

        // Unique visitors (distinct IP hashes, excluding bots)
        $uniq = $pdo->prepare("SELECT COUNT(DISTINCT ip_hash) FROM snap_stats WHERE DATE(hit_at) = ? AND is_bot = 0");
        $uniq->execute([$date]);
        $unique_visitors = (int)$uniq->fetchColumn();

        // Bot views
        $bots = $pdo->prepare("SELECT COUNT(*) FROM snap_stats WHERE DATE(hit_at) = ? AND is_bot = 1");
        $bots->execute([$date]);
        $bot_views = (int)$bots->fetchColumn();

        // Top image
        $top_img = $pdo->prepare("
            SELECT image_id, COUNT(*) as cnt FROM snap_stats
            WHERE DATE(hit_at) = ? AND is_bot = 0 AND image_id IS NOT NULL
            GROUP BY image_id ORDER BY cnt DESC LIMIT 1
        ");
        $top_img->execute([$date]);
        $top_image = $top_img->fetch(PDO::FETCH_ASSOC);

        // Top referrer
        $top_ref = $pdo->prepare("
            SELECT referrer_host, COUNT(*) as cnt FROM snap_stats
            WHERE DATE(hit_at) = ? AND is_bot = 0 AND referrer_host IS NOT NULL
            GROUP BY referrer_host ORDER BY cnt DESC LIMIT 1
        ");
        $top_ref->execute([$date]);
        $top_referrer = $top_ref->fetch(PDO::FETCH_ASSOC);

        // Upsert
        $stmt = $pdo->prepare("
            INSERT INTO snap_stats_daily
                (stat_date, total_views, unique_visitors, bot_views, top_image_id, top_referrer)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                total_views     = VALUES(total_views),
                unique_visitors = VALUES(unique_visitors),
                bot_views       = VALUES(bot_views),
                top_image_id    = VALUES(top_image_id),
                top_referrer    = VALUES(top_referrer)
        ");
        $stmt->execute([
            $date,
            $total_views,
            $unique_visitors,
            $bot_views,
            $top_image['image_id'] ?? null,
            $top_referrer['referrer_host'] ?? null,
        ]);
    } catch (PDOException $e) {
        // Silently fail
    }
}

/**
 * Purge old stats beyond the retention window.
 *
 * @param  PDO $pdo
 * @param  int $days  Retention period in days
 * @return int        Number of rows deleted
 */
function snapsmack_purge_old_stats($pdo, $days = 365) {
    try {
        $stmt = $pdo->prepare("DELETE FROM snap_stats WHERE hit_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        return 0;
    }
}
