<?php
/**
 * SNAPSMACK - Traffic Stats Logger
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
 * SNAPSMACK_EOF_HEADER
 *     /**
 * Pseudo-cron: roll up yesterday if not already done.
 * Call once per request from index.php / archive.php after logging the hit.
 * Uses snap_settings key 'stats_last_rollup' to avoid redundant work.
 *
 * @param PDO $pdo
 */
function snapsmack_maybe_rollup($pdo) {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    try {
        $last = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'stats_last_rollup' LIMIT 1")->fetchColumn();
        if ($last >= $yesterday) return; // already done
        snapsmack_rollup_daily($pdo, $yesterday);
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('stats_last_rollup', ?)
                       ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
            ->execute([$yesterday]);
    } catch (PDOException $e) {
        // Silently fail — stats are non-critical
    }
}

/**
 * Backfill snap_stats_daily for all dates present in snap_stats but missing
 * from snap_stats_daily. Returns count of dates processed.
 *
 * @param PDO $pdo
 * @return int
 */
function snapsmack_backfill_daily($pdo) {
    $processed = 0;
    try {
        $dates = $pdo->query("
            SELECT DISTINCT DATE(hit_at) AS d
            FROM snap_stats
            WHERE DATE(hit_at) < CURDATE()
              AND DATE(hit_at) NOT IN (SELECT stat_date FROM snap_stats_daily)
            ORDER BY d ASC
        ")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($dates as $date) {
            snapsmack_rollup_daily($pdo, $date);
            $processed++;
        }
        // Update last rollup marker to yesterday
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('stats_last_rollup', ?)
                       ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
            ->execute([$yesterday]);
    } catch (PDOException $e) {
        // Silently fail
    }
    return $processed;
}



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
 * Resolve the visitor's country to a 2-letter ISO 3166-1 code.
 *
 * PRIVACY: SnapSmack stats are local-only — we never send a visitor IP to a
 * third-party geolocation API (that would contradict the "visits only, no
 * tracking" promise). Instead we read a country code the WEBSERVER already
 * resolved locally and exposed as an environment variable / request header.
 *
 * Supported sources, in priority order:
 *   - mod_maxminddb   → set MaxMindDBEnv MM_COUNTRY_CODE <db>/country/iso_code
 *   - mod_geoip       → GEOIP_COUNTRY_CODE (legacy)
 *   - Cloudflare      → CF-IPCountry header (only if a site is ever fronted by CF)
 *
 * Returns an uppercase ISO code (e.g. "CA", "US", "GB") or null if no local
 * resolver is configured. Bogus/reserved codes (XX, T1, etc.) map to null.
 *
 * One-time Apache setup per vhost (Debian/Proxmox boxes):
 *   apt install libapache2-mod-maxminddb mmdb-bin
 *   a2enmod maxminddb && systemctl reload apache2
 *   # in the vhost:
 *   MaxMindDBEnable On
 *   MaxMindDBFile  COUNTRY_DB /usr/share/GeoIP/GeoLite2-Country.mmdb
 *   MaxMindDBEnv   MM_COUNTRY_CODE COUNTRY_DB/country/iso_code
 *   # keep the DB current with the geoipupdate package (weekly cron).
 *   # behind a reverse proxy, enable mod_remoteip first so the real client IP
 *   # is what mod_maxminddb looks up.
 *
 * @return string|null  Uppercase 2-letter ISO country code, or null.
 */
function snapsmack_geoip_country() {
    $candidates = [
        $_SERVER['MM_COUNTRY_CODE']         ?? null, // mod_maxminddb (preferred)
        $_SERVER['GEOIP_COUNTRY_CODE']      ?? null, // mod_geoip (legacy)
        $_SERVER['HTTP_CF_IPCOUNTRY']       ?? null, // Cloudflare, if ever fronted
        $_SERVER['HTTP_X_GEO_COUNTRY']      ?? null, // generic upstream proxy
    ];

    foreach ($candidates as $code) {
        if (empty($code)) continue;
        $code = strtoupper(trim($code));
        // Valid ISO 3166-1 alpha-2 only; reject reserved/placeholder codes.
        if (preg_match('/^[A-Z]{2}$/', $code)
            && !in_array($code, ['XX', 'T1', 'ZZ', 'A1', 'A2', 'O1'], true)) {
            return $code;
        }
    }

    // Fallback: PHP-side lookup against a LOCAL .mmdb (still no third-party
    // call — the database lives on your box). For servers with no Apache/proxy
    // GeoIP module available (e.g. Debian trixie, which ships php-maxminddb but
    // NOT libapache2-mod-maxminddb). Active only when the maxminddb extension is
    // loaded AND a readable DB is configured via the SNAPSMACK_GEOIP_DB constant
    // (defaults to /usr/share/GeoIP/country.mmdb). Works with GeoLite2 or DB-IP.
    if (class_exists('MaxMind\\Db\\Reader')) {
        $db = defined('SNAPSMACK_GEOIP_DB') ? SNAPSMACK_GEOIP_DB : '/usr/share/GeoIP/country.mmdb';
        if (is_string($db) && is_readable($db)) {
            try {
                $ip = snapsmack_client_ip();
                if ($ip) {
                    static $geoip_reader = null;
                    if ($geoip_reader === null) {
                        $geoip_reader = new \MaxMind\Db\Reader($db);
                    }
                    $rec  = $geoip_reader->get($ip);
                    $code = $rec['country']['iso_code']
                        ?? ($rec['registered_country']['iso_code'] ?? null);
                    if (is_string($code)) {
                        $code = strtoupper($code);
                        if (preg_match('/^[A-Z]{2}$/', $code)
                            && !in_array($code, ['XX', 'T1', 'ZZ', 'A1', 'A2', 'O1'], true)) {
                            return $code;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Bad/locked DB or bad IP — fail quiet; stats never break the page.
            }
        }
    }

    return null;
}

/**
 * Resolve the real visitor IP for local GeoIP lookup, accounting for a reverse
 * proxy. Trusts REMOTE_ADDR when it is already a public address; otherwise
 * takes the first public address from X-Forwarded-For. XFF is spoofable, but
 * this feeds country STATS only (never auth or access control), so best-effort
 * is acceptable — and it never trusts a private/reserved hop.
 *
 * @return string|null
 */
function snapsmack_client_ip() {
    $pub = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote && filter_var($remote, FILTER_VALIDATE_IP, $pub)) {
        return $remote;
    }

    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    foreach (explode(',', $xff) as $hop) {
        $ip = trim($hop);
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP, $pub)) {
            return $ip;
        }
    }

    return $remote !== '' ? $remote : null;
}

/**
 * Count published POSTS and IMAGES for this install, mode-agnostic.
 *
 * Images = every published transmission (snap_images). Posts = each standalone
 * image (post_id NULL — the SMACKONEOUT / imported case, where the image IS the
 * post) PLUS each grouped post once (GRAMOFSMACK carousel/panorama images share
 * a post_id and collapse to a single post). snap_images is the canonical store;
 * solo images are never wrapped in snap_posts, so COUNT(snap_posts) undercounts
 * every non-carousel install.
 *
 * @param  PDO $pdo
 * @return array  ['posts' => int, 'images' => int]
 */
function snapsmack_content_counts($pdo) {
    $posts = 0; $images = 0;
    try {
        $images = (int)$pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published'")->fetchColumn();
        $solo   = (int)$pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published' AND post_id IS NULL")->fetchColumn();
        $groups = (int)$pdo->query("SELECT COUNT(DISTINCT post_id) FROM snap_images WHERE img_status = 'published' AND post_id IS NOT NULL")->fetchColumn();
        $posts  = $solo + $groups;
    } catch (\Throwable $e) { /* tables may be absent on a very fresh install */ }
    return ['posts' => $posts, 'images' => $images];
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
    if (($settings['stats_enabled'] ?? '1') !== '1') return 0;

    // Exclude logged-in admin if configured
    if (($settings['stats_exclude_admin'] ?? '1') === '1') {
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_login'])) {
            return 0;
        }
    }

    $ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Skip empty UA (almost always a bot or scanner)
    if (empty($ua)) return 0;

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
            snapsmack_geoip_country(), // local webserver GeoIP env var; null if unconfigured
            snapsmack_hash_ip($ip),
            $is_bot,
            $meta['search_term'] ?? snapsmack_extract_search_term($referrer),
        ]);
        $hit_id = (int)$pdo->lastInsertId();
        $GLOBALS['snapsmack_hit'] = ['id' => $hit_id, 'page_type' => $meta['page_type'] ?? 'unknown'];
        return $hit_id;
    } catch (PDOException $e) {
        // Silently fail — stats should never break the page
        // Table might not exist yet if migration hasn't run
    }
    return 0;
}

/**
 * Record engaged Scroll Time (ms) against a prior page hit, from the tracker
 * beacon. Set-once, bot-excluded, recent-window-only, and capped — a beacon
 * cannot rewrite old rows or inject absurd durations.
 *
 * @param  PDO $pdo
 * @param  int $hit_id  snap_stats.id returned by snapsmack_log_hit()
 * @param  int $ms      engaged milliseconds reported by the client
 * @return void
 */
function snapsmack_record_dwell($pdo, $hit_id, $ms) {
    $hit_id = (int)$hit_id;
    $ms     = (int)$ms;
    if ($hit_id <= 0 || $ms <= 0) return;
    if ($ms > 1800000) $ms = 1800000; // cap 30 min — above this is noise

    // dwell_ms is guaranteed by the canonical schema sync — NEVER run DDL here.
    // An ALTER TABLE in this per-beacon hot path takes an exclusive metadata lock
    // on snap_stats; a pending exclusive lock queues AHEAD of concurrent hit
    // INSERTs and stalls page-view logging (the "stats stopped incrementing"
    // regression that arrived with the scroll-time beacon). If the column is
    // somehow absent the UPDATE below simply no-ops — hits are never affected.
    try {
        // Set once, humans only, recent hit only (guards replay / tampering).
        $stmt = $pdo->prepare("UPDATE snap_stats SET dwell_ms = ?
                               WHERE id = ? AND is_bot = 0 AND dwell_ms IS NULL
                                 AND hit_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)");
        $stmt->execute([$ms, $hit_id]);
    } catch (PDOException $e) { /* never break the beacon */ }
}

/**
 * Emit the Scroll Time tracker <script> tag when the current page qualifies:
 * a GRAMOFSMACK landing feed, or a SMACKONEOUT (photoblog) archive. Reads the
 * hit id stashed by snapsmack_log_hit(). No-ops on any other page or mode, or
 * when stats produced no row. Config passes via data-* attributes (no inline
 * JS); the tracker logic lives in assets/js/ss-engine-scrolltime.js.
 *
 * @param  array $settings  site settings (reads $settings['site_mode'])
 * @return void
 */
function snapsmack_scrolltime_tag($settings) {
    $hit = $GLOBALS['snapsmack_hit'] ?? null;
    if (!$hit || empty($hit['id'])) return;

    $mode = $settings['site_mode'] ?? 'photoblog';
    $type = $hit['page_type'] ?? '';
    $ok = ($mode === 'carousel'  && $type === 'landing')
       || ($mode === 'photoblog' && $type === 'archive');
    if (!$ok) return;

    $ver  = defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '1';
    $base = defined('BASE_URL') ? BASE_URL : '/';
    printf(
        '<script id="ss-scrolltime" src="%sassets/js/ss-engine-scrolltime.js?v=%s" data-hit="%d" data-endpoint="%sapi.php?route=stats/dwell"></script>' . "\n",
        htmlspecialchars($base, ENT_QUOTES),
        htmlspecialchars((string)$ver, ENT_QUOTES),
        (int)$hit['id'],
        htmlspecialchars($base, ENT_QUOTES)
    );
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
// ===== SNAPSMACK EOF =====
