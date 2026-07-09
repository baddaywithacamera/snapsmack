<?php
/**
 * SNAPSMACK - Multisite Stats Rollup
 *
 * Hub-only page. Fetches daily stats from all active spokes via the
 * multisite/stats/daily API endpoint (with enriched=1), aggregates them
 * into a fleet-wide view: totals, sparkline, top images, per-spoke breakdown,
 * bot traffic, top referrers.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

// --- SSRF GUARD (mirrors smack-multisite.php) ---
// Returns true if the URL resolves to a private/loopback/reserved address.
if (!function_exists('snap_is_private_url')) {
    function snap_is_private_url(string $url): bool {
        $h = parse_url($url, PHP_URL_HOST);
        if (!$h) return true;
        if (in_array($h, ['localhost', 'ip6-localhost', 'ip6-loopback', '::1', '0.0.0.0'], true)) return true;
        $ip = filter_var($h, FILTER_VALIDATE_IP) ? $h : gethostbyname($h);
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}

// --- HUB GUARD ---
$multisite_role = $settings['multisite_role'] ?? '';
if ($multisite_role !== 'hub') {
    header('Location: smack-multisite.php');
    exit;
}

// --- ACTIVE SPOKES ---
$spokes = $pdo->query("
    SELECT id, site_url, site_name, api_key_local, post_count, image_count, status
    FROM snap_multisite_nodes
    WHERE role = 'spoke'
    ORDER BY site_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// --- PERIOD SELECTOR ---
$period = in_array((int)($_GET['days'] ?? 30), [7, 30, 90, 180, 365, 0]) ? (int)($_GET['days'] ?? 30) : 30;
$period_label = match($period) {
    7   => '7-DAY WINDOW',
    30  => '30-DAY WINDOW',
    90  => '90-DAY WINDOW',
    180 => '6-MONTH WINDOW',
    365 => '1-YEAR WINDOW',
    0   => 'ALL TIME',
    default => '30-DAY WINDOW',
};

// ─────────────────────────────────────────────────────────────────────────────
// FETCH enriched stats from each active spoke
// ─────────────────────────────────────────────────────────────────────────────
$spoke_stats  = [];   // keyed by node_id
$fetch_errors = [];
$fleet_daily  = [];   // date → [views, unique, referrers]
$fleet_top_images = []; // cross-fleet image objects

foreach ($spokes as $spoke) {
    if ($spoke['status'] !== 'active') continue;

    $url = rtrim($spoke['site_url'], '/') . '/api.php?route=multisite/stats/daily&days=' . $period . '&enriched=1';

    // SSRF guard — reject private/loopback addresses even if stored in DB
    if (snap_is_private_url($url)) {
        $fetch_errors[] = $spoke['site_name'] . ' (blocked: private address)';
        $spoke_stats[$spoke['id']] = ['site_name' => $spoke['site_name'], 'site_url' => $spoke['site_url'], 'rows' => [], 'total_views' => 0, 'total_unique' => 0, 'bot_total' => 0, 'top_day' => null, 'top_image' => null, 'post_count' => $spoke['post_count']];
        continue;
    }

    $ch  = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $spoke['api_key_local'],
            'Accept: application/json',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw || $code !== 200) {
        $fetch_errors[] = $spoke['site_name'];
        $spoke_stats[$spoke['id']] = [
            'site_name'    => $spoke['site_name'],
            'site_url'     => $spoke['site_url'],
            'rows'         => [],
            'total_views'  => 0,
            'total_unique' => 0,
            'bot_total'    => 0,
            'top_image'    => null,
            'post_count'   => $spoke['post_count'],
        ];
        continue;
    }

    $resp = json_decode($raw, true);
    $rows = ($resp['ok'] ?? false) ? ($resp['stats'] ?? []) : [];

    $spoke_total_views  = array_sum(array_column($rows, 'total_views'));
    $spoke_total_unique = array_sum(array_column($rows, 'unique_visitors'));
    $spoke_bot_total    = (int)($resp['bot_total'] ?? 0);
    $spoke_top_day      = $resp['top_day']  ?? ['date' => null, 'views' => 0];
    $spoke_top_images   = $resp['top_images'] ?? [];

    // F-02: constrain thumb/page URLs to the spoke's trusted DB origin.
    // A compromised spoke could return arbitrary URLs; strip thumb_url if it
    // doesn't begin with the spoke's registered site_url.
    $trusted_origin = rtrim($spoke['site_url'], '/');
    foreach ($spoke_top_images as &$img) {
        if (!empty($img['thumb_url']) && !str_starts_with($img['thumb_url'], $trusted_origin)) {
            $img['thumb_url'] = '';
        }
        if (!empty($img['page_url']) && !str_starts_with($img['page_url'], $trusted_origin)) {
            $img['page_url'] = '#';
        }
    }
    unset($img);

    $spoke_stats[$spoke['id']] = [
        'site_name'    => $spoke['site_name'],
        'site_url'     => $spoke['site_url'],
        'rows'         => $rows,
        'total_views'  => $spoke_total_views,
        'total_unique' => $spoke_total_unique,
        'bot_total'    => $spoke_bot_total,
        'top_day'      => $spoke_top_day,
        'top_image'    => $spoke_top_images[0] ?? null,
        'post_count'   => isset($resp['post_count'])  ? (int)$resp['post_count']  : (int)$spoke['post_count'],
        'image_count'  => isset($resp['image_count']) ? (int)$resp['image_count'] : (int)($spoke['image_count'] ?? 0),
        'counts_live'  => isset($resp['post_count']),
        'browsers'     => $resp['browsers']     ?? [],
        'os'           => $resp['os']           ?? [],
        'categories'   => $resp['categories']   ?? [],
        'search_terms' => $resp['search_terms'] ?? [],
        'peak_hours'   => $resp['peak_hours']   ?? [],
        'countries'    => $resp['countries']    ?? [],
        'scroll_time_avg_ms' => $resp['scroll_time_avg_ms'] ?? 0,
        'scroll_time_n'      => $resp['scroll_time_n']      ?? 0,
    ];

    // Attach site info to each image and add to fleet pool
    foreach ($spoke_top_images as $img) {
        $img['_site_name'] = $spoke['site_name'];
        $img['_site_url']  = $spoke['site_url'];
        $fleet_top_images[] = $img;
    }

    // Merge into fleet daily totals
    foreach ($rows as $row) {
        $date = $row['stat_date'] ?? '';
        if (!$date) continue;
        if (!isset($fleet_daily[$date])) {
            $fleet_daily[$date] = ['views' => 0, 'unique' => 0, 'referrers' => []];
        }
        $fleet_daily[$date]['views']  += (int)($row['total_views']    ?? 0);
        $fleet_daily[$date]['unique'] += (int)($row['unique_visitors'] ?? 0);
        if (!empty($row['top_referrer'])) {
            $ref = parse_url($row['top_referrer'], PHP_URL_HOST) ?: $row['top_referrer'];
            $fleet_daily[$date]['referrers'][$ref] = ($fleet_daily[$date]['referrers'][$ref] ?? 0) + (int)($row['total_views'] ?? 1);
        }
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// HUB's own stats — pulled directly from local DB
// ─────────────────────────────────────────────────────────────────────────────
$hub_name = $settings['site_name'] ?? 'Hub';

// Hub content inventory — posts and images counted SEPARATELY. A GRAMOFSMACK
// carousel/panorama is one post but many images; SMACKONEOUT is 1:1.
require_once __DIR__ . '/core/stats-logger.php';
$cc_hub = snapsmack_content_counts($pdo);
$hub_post_count  = $cc_hub['posts'];
$hub_image_count = $cc_hub['images'];

// Hub engaged Scroll Time (avg ms) on GRAM landing + SMACKONEOUT archive.
// dwell_ms is populated by the Scroll Time tracker; column/data may be absent
// until that feature is deployed — stays null-safe (renders as "—") if so.
$hub_scroll_avg = null; $hub_scroll_n = 0;
try {
    $sd_sub = $period > 0 ? "AND hit_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)" : '';
    // Count only ENGAGED reads (10s+): a landing-feed average over every hit is
    // dominated by drive-by bounces (and the short frozen samples from before the
    // tracker was fixed), which pins it near ~12-14s no matter the real reads.
    // The >= 10000ms floor drops bounces + NULLs and reflects actual reading time.
    $row = $pdo->query("SELECT AVG(dwell_ms) AS a, COUNT(*) AS n FROM snap_stats
                        WHERE is_bot = 0 AND dwell_ms >= 10000
                          AND page_type IN ('landing','archive') {$sd_sub}")->fetch(PDO::FETCH_ASSOC);
    if ($row && (int)$row['n'] > 0) { $hub_scroll_avg = (float)$row['a']; $hub_scroll_n = (int)$row['n']; }
} catch (\Exception $e) {}

// Daily rows for chart
$hub_data = [];
try {
    if ($period === 0) {
        $hub_data = $pdo->query("
            SELECT stat_date, total_views, unique_visitors, bot_views, top_referrer
            FROM snap_stats_daily
            ORDER BY stat_date ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $hub_stmt = $pdo->prepare("
            SELECT stat_date, total_views, unique_visitors, bot_views, top_referrer
            FROM snap_stats_daily
            WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY stat_date ASC
        ");
        $hub_stmt->execute([$period]);
        $hub_data = $hub_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { /* table may not exist on older installs */ }

$hub_total_views  = array_sum(array_column($hub_data, 'total_views'));
$hub_total_unique = array_sum(array_column($hub_data, 'unique_visitors'));
$hub_bot_total    = array_sum(array_column($hub_data, 'bot_views'));

// Hub top day
$hub_top_day = ['date' => null, 'views' => 0];
foreach ($hub_data as $row) {
    if ((int)$row['total_views'] > $hub_top_day['views']) {
        $hub_top_day = ['date' => $row['stat_date'], 'views' => (int)$row['total_views']];
    }
}

// Hub top images
$hub_top_images = [];
try {
    if ($period === 0) {
        $hi_stmt = $pdo->query("
            SELECT s.image_id, i.img_title, i.img_slug, i.img_file,
                   i.img_thumb_aspect, i.img_thumb_square, COUNT(*) AS view_count
            FROM snap_stats s
            JOIN snap_images i ON i.id = s.image_id
            WHERE s.is_bot = 0 AND s.image_id IS NOT NULL
            GROUP BY s.image_id ORDER BY view_count DESC LIMIT 30
        ");
    } else {
        $hi_stmt = $pdo->prepare("
            SELECT s.image_id, i.img_title, i.img_slug, i.img_file,
                   i.img_thumb_aspect, i.img_thumb_square, COUNT(*) AS view_count
            FROM snap_stats s
            JOIN snap_images i ON i.id = s.image_id
            WHERE s.is_bot = 0 AND s.image_id IS NOT NULL
              AND s.hit_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY s.image_id ORDER BY view_count DESC LIMIT 30
        ");
        $hi_stmt->execute([$period]);
    }
    $base_url = rtrim(BASE_URL, '/');
    foreach ($hi_stmt->fetchAll(PDO::FETCH_ASSOC) as $img) {
        if (!empty($img['img_thumb_aspect'])) {
            $thumb = $base_url . '/' . ltrim($img['img_thumb_aspect'], '/');
        } elseif (!empty($img['img_thumb_square'])) {
            $thumb = $base_url . '/' . ltrim($img['img_thumb_square'], '/');
        } else {
            $file  = ltrim($img['img_file'], '/');
            $thumb = $base_url . '/' . dirname($file) . '/thumbs/t_' . basename($file);
        }
        $entry = [
            'id'        => (int)$img['image_id'],
            'title'     => $img['img_title'],
            'slug'      => $img['img_slug'],
            'thumb_url' => $thumb,
            'page_url'  => $base_url . '/' . ltrim($img['img_slug'], '/'),
            'views'     => (int)$img['view_count'],
            '_site_name'=> $hub_name . ' (Hub)',
            '_site_url' => $base_url,
        ];
        $hub_top_images[] = $entry;
        $fleet_top_images[] = $entry;
    }
} catch (\Exception $e) { /* snap_stats may be empty */ }

// Hub browsers, OS, categories, search terms, peak hours, countries
$hub_browsers = $hub_os = $hub_categories = $hub_search_terms = $hub_peak_hours = $hub_countries = [];
try {
    $date_sub = $period > 0 ? "AND hit_at >= DATE_SUB(NOW(), INTERVAL {$period} DAY)" : '';
    $hub_browsers     = $pdo->query("SELECT browser, COUNT(*) AS hits FROM snap_stats WHERE is_bot = 0 AND browser IS NOT NULL AND browser != '' {$date_sub} GROUP BY browser ORDER BY hits DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    $hub_os           = $pdo->query("SELECT os, COUNT(*) AS hits FROM snap_stats WHERE is_bot = 0 AND os IS NOT NULL AND os != '' {$date_sub} GROUP BY os ORDER BY hits DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    $hub_categories   = $pdo->query("SELECT c.cat_name, COUNT(*) AS views FROM snap_stats s JOIN snap_image_cat_map m ON s.image_id = m.image_id JOIN snap_categories c ON m.cat_id = c.id WHERE s.is_bot = 0 AND s.image_id IS NOT NULL {$date_sub} GROUP BY c.id, c.cat_name ORDER BY views DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    $hub_search_terms = $pdo->query("SELECT search_term, COUNT(*) AS uses FROM snap_stats WHERE is_bot = 0 AND search_term IS NOT NULL AND search_term != '' {$date_sub} GROUP BY search_term ORDER BY uses DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    $hub_peak_hours   = $pdo->query("SELECT DAYOFWEEK(hit_at) AS dow, HOUR(hit_at) AS hour, COUNT(*) AS hits FROM snap_stats WHERE is_bot = 0 {$date_sub} GROUP BY DAYOFWEEK(hit_at), HOUR(hit_at)")->fetchAll(PDO::FETCH_ASSOC);
    $hub_countries    = $pdo->query("SELECT country, COUNT(*) AS hits FROM snap_stats WHERE is_bot = 0 AND country IS NOT NULL AND country != '' {$date_sub} GROUP BY country ORDER BY hits DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) { /* snap_stats may not exist */ }

// Add hub as synthetic entry for the breakdown table
$spoke_stats['__hub__'] = [
    'site_name'    => $hub_name . ' (Hub)',
    'site_url'     => '',
    'rows'         => $hub_data,
    'total_views'  => $hub_total_views,
    'total_unique' => $hub_total_unique,
    'bot_total'    => $hub_bot_total,
    'top_day'      => $hub_top_day,
    'top_image'    => $hub_top_images[0] ?? null,
    'post_count'   => $hub_post_count,
    'image_count'  => $hub_image_count,
    'counts_live'  => true,
    'is_hub'       => true,
    'browsers'     => $hub_browsers,
    'os'           => $hub_os,
    'categories'   => $hub_categories,
    'search_terms' => $hub_search_terms,
    'peak_hours'   => $hub_peak_hours,
    'countries'    => $hub_countries,
];

// Merge hub daily rows into fleet totals
foreach ($hub_data as $row) {
    $date = $row['stat_date'] ?? '';
    if (!$date) continue;
    if (!isset($fleet_daily[$date])) {
        $fleet_daily[$date] = ['views' => 0, 'unique' => 0, 'referrers' => []];
    }
    $fleet_daily[$date]['views']  += (int)($row['total_views']    ?? 0);
    $fleet_daily[$date]['unique'] += (int)($row['unique_visitors'] ?? 0);
    if (!empty($row['top_referrer'])) {
        $ref = parse_url($row['top_referrer'], PHP_URL_HOST) ?: $row['top_referrer'];
        $fleet_daily[$date]['referrers'][$ref] = ($fleet_daily[$date]['referrers'][$ref] ?? 0) + (int)($row['total_views'] ?? 1);
    }
}

// Sort fleet daily by date ascending for the chart
ksort($fleet_daily);

// Fleet totals
$fleet_total_views  = array_sum(array_column($fleet_daily, 'views'));
$fleet_total_unique = array_sum(array_column($fleet_daily, 'unique'));
$fleet_bot_total    = array_sum(array_column($spoke_stats, 'bot_total'));
$fleet_bot_pct      = $fleet_total_views > 0 ? round(($fleet_bot_total / ($fleet_total_views + $fleet_bot_total)) * 100, 1) : 0;

// Fleet content inventory: posts + images across all registered spokes + hub.
$fleet_post_count  = $hub_post_count;
$fleet_image_count = $hub_image_count;
foreach ($spokes as $sp) {
    $ss = $spoke_stats[$sp['id']] ?? [];
    // Prefer the live count fetched this load; fall back to the last stored
    // heartbeat value for a spoke that's offline or not yet on the new endpoint.
    $fleet_post_count  += (int)($ss['post_count']  ?? $sp['post_count']  ?? 0);
    $fleet_image_count += (int)($ss['image_count'] ?? $sp['image_count'] ?? 0);
}

// Fleet engaged Scroll Time — sample-count-weighted average across hub + spokes.
// Spokes surface scroll_time_avg_ms / scroll_time_n via the enriched stats API
// once the Scroll Time feature ships fleet-wide; absent values are skipped.
$scroll_sum = 0.0; $scroll_n = 0;
if ($hub_scroll_n > 0) { $scroll_sum += $hub_scroll_avg * $hub_scroll_n; $scroll_n += $hub_scroll_n; }
foreach ($spoke_stats as $sd) {
    $n = (int)($sd['scroll_time_n'] ?? 0);
    if ($n > 0) { $scroll_sum += (float)($sd['scroll_time_avg_ms'] ?? 0) * $n; $scroll_n += $n; }
}
$fleet_scroll_avg_ms = $scroll_n > 0 ? $scroll_sum / $scroll_n : null;
$fleet_scroll_label  = $fleet_scroll_avg_ms === null ? '—' : (function ($ms) {
    $s = (int) round($ms / 1000);
    return $s >= 60 ? intdiv($s, 60) . 'm ' . ($s % 60) . 's' : $s . 's';
})($fleet_scroll_avg_ms);

// CUMULATIVE engaged Scroll Time across the whole fleet — the TOTAL time real
// visitors spent reading, summed over hub + every spoke ($scroll_sum is already
// Σ(avg_i × n_i) = total engaged ms). This is the headline number; the average
// above is retained as a secondary line.
$fleet_scroll_total_ms    = $scroll_sum;
$fleet_scroll_total_label = ($scroll_n <= 0) ? '—' : (function ($ms) {
    $s = (int) round($ms / 1000);
    if ($s >= 86400) { return intdiv($s, 86400) . 'd ' . intdiv($s % 86400, 3600) . 'h'; }
    if ($s >= 3600)  { return intdiv($s, 3600)  . 'h ' . intdiv($s % 3600, 60)   . 'm'; }
    if ($s >= 60)    { return intdiv($s, 60)    . 'm ' . ($s % 60)                . 's'; }
    return $s . 's';
})($fleet_scroll_total_ms);

// Fleet top day
$fleet_top_day = ['date' => null, 'views' => 0];
foreach ($fleet_daily as $date => $day) {
    if ($day['views'] > $fleet_top_day['views']) {
        $fleet_top_day = ['date' => $date, 'views' => $day['views']];
    }
}

// Fleet avg daily (non-zero days only)
$non_zero_days = count(array_filter(array_column($fleet_daily, 'views'), fn($v) => $v > 0));
$fleet_avg_daily = $non_zero_days > 0 ? round($fleet_total_views / $non_zero_days) : 0;

// Cross-fleet top images — sort by views DESC, take top 30
usort($fleet_top_images, fn($a, $b) => $b['views'] - $a['views']);
$fleet_top_images = array_slice($fleet_top_images, 0, 30);

// Top referrers across the fleet
$all_referrers = [];
foreach ($fleet_daily as $day) {
    foreach ($day['referrers'] as $ref => $count) {
        $all_referrers[$ref] = ($all_referrers[$ref] ?? 0) + $count;
    }
}
arsort($all_referrers);
$top_referrers = array_slice($all_referrers, 0, 10, true);

// Spoke ranking by views
uasort($spoke_stats, fn($a, $b) => $b['total_views'] - $a['total_views']);

// ── FLEET AGGREGATION: browsers, OS, categories, search terms, peak hours, countries ──
$fleet_browsers = $fleet_os = $fleet_categories = $fleet_search_terms = $fleet_countries = [];
$fleet_peak_hours = [];
for ($d = 1; $d <= 7; $d++) {
    for ($h = 0; $h < 24; $h++) {
        $fleet_peak_hours[$d][$h] = 0;
    }
}
foreach ($spoke_stats as $sd) {
    foreach ($sd['browsers'] ?? [] as $b) {
        $k = $b['browser'] ?? ''; if ($k === '') continue;
        $fleet_browsers[$k] = ($fleet_browsers[$k] ?? 0) + (int)$b['hits'];
    }
    foreach ($sd['os'] ?? [] as $o) {
        $k = $o['os'] ?? ''; if ($k === '') continue;
        $fleet_os[$k] = ($fleet_os[$k] ?? 0) + (int)$o['hits'];
    }
    foreach ($sd['categories'] ?? [] as $c) {
        $k = $c['cat_name'] ?? ''; if ($k === '') continue;
        $fleet_categories[$k] = ($fleet_categories[$k] ?? 0) + (int)$c['views'];
    }
    foreach ($sd['search_terms'] ?? [] as $st) {
        $k = $st['search_term'] ?? ''; if ($k === '') continue;
        $fleet_search_terms[$k] = ($fleet_search_terms[$k] ?? 0) + (int)$st['uses'];
    }
    foreach ($sd['peak_hours'] ?? [] as $ph) {
        $d = (int)($ph['dow']  ?? 0);
        $h = (int)($ph['hour'] ?? 0);
        if ($d >= 1 && $d <= 7 && $h >= 0 && $h < 24) {
            $fleet_peak_hours[$d][$h] += (int)$ph['hits'];
        }
    }
    foreach ($sd['countries'] ?? [] as $c) {
        $k = $c['country'] ?? ''; if ($k === '') continue;
        $fleet_countries[$k] = ($fleet_countries[$k] ?? 0) + (int)$c['hits'];
    }
}
arsort($fleet_browsers);    $fleet_browsers    = array_slice($fleet_browsers,    0, 15, true);
arsort($fleet_os);          $fleet_os          = array_slice($fleet_os,          0, 15, true);
arsort($fleet_categories);  $fleet_categories  = array_slice($fleet_categories,  0, 15, true);
arsort($fleet_search_terms);$fleet_search_terms= array_slice($fleet_search_terms,0, 20, true);
arsort($fleet_countries);   $fleet_countries   = array_slice($fleet_countries,   0, 20, true);
$fleet_peak_max = 1; // avoid division by zero
foreach ($fleet_peak_hours as $row) { foreach ($row as $v) { if ($v > $fleet_peak_max) $fleet_peak_max = $v; } }

// Sparkline max for scaling
$max_daily_views = $fleet_daily ? max(array_column(array_values($fleet_daily), 'views')) : 0;

$page_title = "Fleet Stats";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>FLEET STATS ROLLUP</h2>
    </div>

    <!-- QUICK NAV -->
    <div class="signal-control-header" style="margin-bottom:20px;">
        <div class="signal-nav-group">
            <a href="smack-multisite.php"          class="btn-clear">DASHBOARD</a>
            <a href="smack-multisite-comments.php" class="btn-clear">SIGNALS</a>
            <a href="smack-multisite-posts.php"    class="btn-clear">POSTS</a>
            <a href="smack-multisite-backup.php"   class="btn-clear">BACKUP DOCK</a>
            <a href="smack-multisite-stats.php"    class="btn-clear active">STATS</a>
            <a href="smack-multisite-crosspost.php" class="btn-clear">CROSS-POST</a>
            <a href="smack-multisite-blogroll.php"  class="btn-clear">BLOGROLL</a>
            <a href="smack-multisite-settings.php"  class="btn-clear">SETTINGS</a>
            <a href="smack-push-it.php"              class="btn-clear">PUSH IT</a>
            <span class="sep">|</span>
            <a href="?days=7"   class="btn-clear <?php echo $period === 7   ? 'active' : ''; ?>">7D</a>
            <a href="?days=30"  class="btn-clear <?php echo $period === 30  ? 'active' : ''; ?>">30D</a>
            <a href="?days=90"  class="btn-clear <?php echo $period === 90  ? 'active' : ''; ?>">90D</a>
            <a href="?days=180" class="btn-clear <?php echo $period === 180 ? 'active' : ''; ?>">6M</a>
            <a href="?days=365" class="btn-clear <?php echo $period === 365 ? 'active' : ''; ?>">1YR</a>
            <a href="?days=0"   class="btn-clear <?php echo $period === 0   ? 'active' : ''; ?>">ALL</a>
        </div>
    </div>

    <?php if (!empty($fetch_errors)): ?>
        <div class="alert alert-error">OFFLINE SPOKES (no stats): <?php echo htmlspecialchars(implode(', ', $fetch_errors)); ?></div>
    <?php endif; ?>

    <?php if (empty($spokes)): ?>
        <div class="box">
            <p style="color:var(--text-muted,#888);">No spokes connected. <a href="smack-multisite.php" style="color:var(--accent,#aaa);">Register a spoke</a> first.</p>
        </div>
    <?php else: ?>

    <!-- FLEET TOTALS ─────────────────────────────────────────────────────── -->
    <div class="box">
        <h3>FLEET TOTALS<?php echo $period ? ' — ' . $period_label : ' — ALL TIME'; ?><?php if ($period === 0 && !empty($fleet_daily)): ?> <span style="font-size:0.72rem; font-weight:400; color:var(--text-muted,#888); letter-spacing:1px; margin-left:8px;">(since <?php echo htmlspecialchars(array_key_first($fleet_daily)); ?> &ndash; <?php echo htmlspecialchars(array_key_last($fleet_daily)); ?>)</span><?php endif; ?></h3>

        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:25px;">
            <?php
                $active_spokes = count(array_filter($spokes, fn($s) => $s['status'] === 'active'));

                // Fediverse rollup — hub's own live count + each spoke's cached
                // heartbeat count (snap_multisite_nodes.smackverse_followers).
                $fleet_fedi_followers = 0; $fleet_fedi_sites = 0;
                try {
                    if (($settings['smackverse_enabled'] ?? '0') === '1') {
                        $fleet_fedi_followers += (int)$pdo->query("SELECT COUNT(*) FROM snap_ap_followers WHERE is_active = 1")->fetchColumn();
                        $fleet_fedi_sites++;
                    }
                    $_fr = $pdo->query("SELECT COALESCE(SUM(smackverse_followers),0) AS f, COALESCE(SUM(smackverse_enabled),0) AS s FROM snap_multisite_nodes WHERE role = 'spoke'")->fetch(PDO::FETCH_ASSOC);
                    $fleet_fedi_followers += (int)($_fr['f'] ?? 0);
                    $fleet_fedi_sites     += (int)($_fr['s'] ?? 0);
                } catch (Exception $e) { /* federation cols not present yet */ }

                // SMACKVERSE engagement (hub) — following, inbound likes/boosts/
                // replies (typed in snap_ap_notifications), and views referred by an
                // instance we federate with or a common fediverse platform pattern.
                $fedi_following = 0; $fedi_likes = 0; $fedi_boosts = 0; $fedi_replies = 0;
                try {
                    if (($settings['smackverse_enabled'] ?? '0') === '1') {
                        $fedi_following = (int)$pdo->query("SELECT COUNT(*) FROM snap_ap_following WHERE state = 'accepted'")->fetchColumn();
                        $_nt = $pdo->query("SELECT ntype, COUNT(*) c FROM snap_ap_notifications GROUP BY ntype")->fetchAll(PDO::FETCH_KEY_PAIR);
                        $fedi_likes = (int)($_nt['like']  ?? 0);
                        $fedi_boosts   = (int)($_nt['boost'] ?? 0);
                        $fedi_replies  = (int)($_nt['reply'] ?? 0) + (int)($_nt['mention'] ?? 0);
                    }
                    // Fleet rollup (0.7.391): add each spoke's cached heartbeat
                    // engagement on top of the hub-local counts above, mirroring the
                    // FEDIVERSE FOLLOWERS rollup. Without this the tiles only ever
                    // showed the hub site's own numbers, so a fleet of spokes full of
                    // follows and Pixelfed likes still read 0. Unconditional — spokes
                    // federate even when the hub site itself doesn't.
                    $_fe = $pdo->query(
                        "SELECT COALESCE(SUM(smackverse_following),0) fo,
                                COALESCE(SUM(smackverse_likes),0)     li,
                                COALESCE(SUM(smackverse_boosts),0)    bo,
                                COALESCE(SUM(smackverse_replies),0)   re
                         FROM snap_multisite_nodes WHERE role = 'spoke'"
                    )->fetch(PDO::FETCH_ASSOC);
                    $fedi_following += (int)($_fe['fo'] ?? 0);
                    $fedi_likes     += (int)($_fe['li'] ?? 0);
                    $fedi_boosts    += (int)($_fe['bo'] ?? 0);
                    $fedi_replies   += (int)($_fe['re'] ?? 0);
                } catch (Exception $e) { /* fedi tables absent */ }
            ?>
            <div style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo number_format($fleet_total_views); ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">TOTAL VIEWS</div>
            </div>
            <div style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo number_format($fleet_total_unique); ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">UNIQUE VISITORS</div>
            </div>
            <div style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo $active_spokes + 1; ?> / <?php echo count($spokes) + 1; ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">SITES REPORTING</div>
            </div>
            <div style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo number_format($fleet_bot_total); ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">BOT VIEWS <span style="color:var(--text-muted,#666)">(<?php echo $fleet_bot_pct; ?>%)</span></div>
            </div>
            <div style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo number_format($fleet_avg_daily); ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">AVG VIEWS / DAY</div>
            </div>
            <div style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <?php if ($fleet_top_day['date']): ?>
                    <div style="font-size:1.4rem; font-weight:900; color:var(--text,#eee);"><?php echo htmlspecialchars($fleet_top_day['date']); ?></div>
                    <div style="font-size:0.85rem; font-weight:700; color:var(--text-muted,#aaa);"><?php echo number_format($fleet_top_day['views']); ?> views</div>
                <?php else: ?>
                    <div style="font-size:1.4rem; font-weight:900; color:var(--text-muted,#555);">—</div>
                <?php endif; ?>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">PEAK DAY</div>
            </div>
            <a href="#network-breakdown" title="See posts per blog below" style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center; text-decoration:none; display:block;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo number_format($fleet_post_count); ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">TOTAL POSTS &#9662;</div>
            </a>
            <a href="#network-breakdown" title="See images per blog below" style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center; text-decoration:none; display:block;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo number_format($fleet_image_count); ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">TOTAL IMAGES &#9662;</div>
            </a>
            <div style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo htmlspecialchars($fleet_scroll_total_label); ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">SCROLL TIME (TOTAL)</div>
                <div style="font-size:0.64rem; color:var(--text-muted,#666); margin-top:6px;"><?php echo htmlspecialchars($fleet_scroll_label); ?> avg &middot; <?php echo number_format($scroll_n); ?> reads</div>
            </div>
            <div style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo number_format($fleet_fedi_followers); ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">FEDIVERSE FOLLOWERS <span style="color:var(--text-muted,#666)">(<?php echo (int)$fleet_fedi_sites; ?> federating)</span></div>
            </div>
            <div style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo number_format($fedi_following); ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">FEDIVERSE FOLLOWING</div>
            </div>
            <div style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo number_format($fedi_likes); ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">LIKES RECEIVED</div>
            </div>
            <div style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo number_format($fedi_boosts); ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">BOOSTS</div>
            </div>
            <div style="padding:18px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo number_format($fedi_replies); ?></div>
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">REPLIES &amp; MENTIONS</div>
            </div>
        </div>

        <!-- FLEET SPARKLINE -->
        <?php if (!empty($fleet_daily) && $max_daily_views > 0): ?>
            <div style="margin-top:10px;">
                <div style="font-size:0.72rem; color:var(--text-muted,#888); letter-spacing:1px; margin-bottom:8px;">DAILY FLEET TRAFFIC</div>
                <div class="stats-sparkline" style="height:80px; display:flex; align-items:flex-end; gap:2px; width:100%; overflow:hidden;">
                    <?php
                        $bar_width = 100 / max(count($fleet_daily), 1);
                        foreach ($fleet_daily as $date => $day):
                            $pct = max(round(($day['views'] / $max_daily_views) * 100), 1);
                    ?>
                        <div class="stats-spark-bar"
                             style="height:<?php echo $pct; ?>%; flex:1 1 0; min-width:2px;"
                             title="<?php echo htmlspecialchars($date); ?>: <?php echo number_format($day['views']); ?> views, <?php echo number_format($day['unique']); ?> unique"></div>
                    <?php endforeach; ?>
                </div>
                <div class="stats-sparkline-labels">
                    <span><?php echo array_key_first($fleet_daily); ?></span>
                    <span><?php echo array_key_last($fleet_daily); ?></span>
                </div>
            </div>
        <?php else: ?>
            <p style="color:var(--text-muted,#666); font-size:0.85rem;">No traffic data in this period.</p>
        <?php endif; ?>
    </div>

    <!-- FLEET TOP IMAGES ─────────────────────────────────────────────────── -->
    <?php if (!empty($fleet_top_images)): ?>
    <div class="box">
        <h3>MOST VIEWED — FLEET WIDE</h3>
        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:12px; margin-top:8px;">
            <?php foreach ($fleet_top_images as $fi):
                $fi_title = htmlspecialchars($fi['title'] ?? 'Untitled');
                $fi_site  = htmlspecialchars($fi['_site_name'] ?? '');
                $fi_thumb = htmlspecialchars($fi['thumb_url'] ?? '');
                $fi_url   = htmlspecialchars($fi['page_url'] ?? '#');
                $fi_views = number_format((int)($fi['views'] ?? 0));
            ?>
                <a href="<?php echo $fi_url; ?>" target="_blank" rel="noopener"
                   style="display:block; border:1px solid var(--border,#333); background:var(--input-bg,#111);
                          text-decoration:none; overflow:hidden; transition:border-color 0.15s;"
                   onmouseover="this.style.borderColor='var(--text,#eee)'" onmouseout="this.style.borderColor='var(--border,#333)'">
                    <?php if ($fi_thumb): ?>
                        <div style="width:100%; aspect-ratio:1; overflow:hidden; background:#000;">
                            <img src="<?php echo $fi_thumb; ?>" alt="<?php echo $fi_title; ?>"
                                 loading="lazy" style="width:100%; height:100%; object-fit:cover; display:block;">
                        </div>
                    <?php else: ?>
                        <div style="width:100%; aspect-ratio:1; background:var(--border,#333); display:flex; align-items:center; justify-content:center;">
                            <span style="color:var(--text-muted,#666); font-size:0.75rem;">NO THUMB</span>
                        </div>
                    <?php endif; ?>
                    <div style="padding:8px 10px;">
                        <div style="font-size:0.78rem; font-weight:700; color:var(--text,#eee); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:3px;"
                             title="<?php echo $fi_title; ?>"><?php echo $fi_title; ?></div>
                        <div style="font-size:0.68rem; color:var(--text-muted,#888); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?php echo $fi_site; ?></div>
                        <div style="font-size:0.72rem; font-weight:900; color:var(--text,#eee); margin-top:4px; letter-spacing:1px;"><?php echo $fi_views; ?> views</div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- NETWORK BREAKDOWN ───────────────────────────────────────────────── -->
    <div class="box" id="network-breakdown">
        <h3>NETWORK BREAKDOWN</h3>
        <p style="font-size:0.72rem; color:var(--text-muted,#888); margin:-4px 0 10px;">POSTS &amp; IMAGES are live per blog. A <span style="color:var(--accent-primary,#c66);">*</span> marks a last-stored value &mdash; that spoke is offline or not yet on 0.7.340, so it isn't reporting fresh counts.</p>

        <?php if (!empty($spoke_stats)):
            $max_spoke_views = max(1, max(array_column($spoke_stats, 'total_views')));
        ?>
            <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border,#333);">
                        <th style="text-align:left;   padding:10px 8px; color:var(--text-muted,#888);">SITE</th>
                        <th style="text-align:center; padding:10px 8px; color:var(--text-muted,#888);">VIEWS</th>
                        <th style="text-align:center; padding:10px 8px; color:var(--text-muted,#888);">UNIQUE</th>
                        <th style="text-align:center; padding:10px 8px; color:var(--text-muted,#888);">POSTS</th>
                        <th style="text-align:center; padding:10px 8px; color:var(--text-muted,#888);">IMAGES</th>
                        <th style="text-align:center; padding:10px 8px; color:var(--text-muted,#888);">AVG/DAY</th>
                        <th style="text-align:center; padding:10px 8px; color:var(--text-muted,#888);">BOTS</th>
                        <th style="text-align:left;   padding:10px 8px; color:var(--text-muted,#888); min-width:120px;">TOP IMAGE</th>
                        <th style="text-align:left;   padding:10px 8px; color:var(--text-muted,#888); width:25%;">SHARE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($spoke_stats as $node_id => $sd):
                        $share_pct  = $fleet_total_views > 0 ? ($sd['total_views'] / $fleet_total_views) * 100 : 0;
                        // ALL TIME ($period === 0) must divide by the active-day span,
                        // not 0 — otherwise every row's AVG/DAY reads 0. Uses the same
                        // $non_zero_days denominator as the fleet headline AVG VIEWS/DAY.
                        $avg_day    = $period > 0
                            ? round($sd['total_views'] / $period)
                            : ($non_zero_days > 0 ? round($sd['total_views'] / $non_zero_days) : 0);
                        $is_hub_row = !empty($sd['is_hub']);
                        $bot_pct    = ($sd['total_views'] + $sd['bot_total']) > 0
                            ? round(($sd['bot_total'] / ($sd['total_views'] + $sd['bot_total'])) * 100, 1)
                            : 0;
                        $top_img    = $sd['top_image'] ?? null;
                    ?>
                        <tr style="border-bottom:1px solid var(--border,#333);<?php echo $is_hub_row ? ' background:var(--input-bg,#111);' : ''; ?>">
                            <td style="padding:10px 8px;">
                                <strong><?php echo htmlspecialchars($sd['site_name']); ?></strong>
                                <?php if ($is_hub_row): ?>
                                    <span style="font-size:0.7rem; color:var(--text-muted,#888); margin-left:5px; letter-spacing:1px;">LOCAL</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 8px; text-align:center; font-weight:700;"><?php echo number_format($sd['total_views']); ?></td>
                            <td style="padding:10px 8px; text-align:center; color:var(--text-muted,#888);"><?php echo number_format($sd['total_unique']); ?></td>
                            <td style="padding:10px 8px; text-align:center; color:var(--text-muted,#888);"><?php echo number_format((int)($sd['post_count'] ?? 0)); if (empty($sd['counts_live']) && empty($sd['is_hub'])): ?><span title="last stored value — spoke offline or not yet on 0.7.340, not reporting fresh counts" style="color:var(--accent-primary,#c66);">&nbsp;*</span><?php endif; ?></td>
                            <td style="padding:10px 8px; text-align:center; color:var(--text-muted,#888);"><?php echo number_format((int)($sd['image_count'] ?? 0)); ?></td>
                            <td style="padding:10px 8px; text-align:center; color:var(--text-muted,#888);"><?php echo number_format($avg_day); ?></td>
                            <td style="padding:10px 8px; text-align:center; color:var(--text-muted,#666); font-size:0.8rem;"><?php echo $bot_pct; ?>%</td>
                            <td style="padding:10px 8px;">
                                <?php if ($top_img): ?>
                                    <a href="<?php echo htmlspecialchars($top_img['page_url'] ?? '#'); ?>" target="_blank" rel="noopener"
                                       style="display:flex; align-items:center; gap:8px; text-decoration:none; color:inherit;">
                                        <?php if (!empty($top_img['thumb_url'])): ?>
                                            <img src="<?php echo htmlspecialchars($top_img['thumb_url']); ?>"
                                                 alt="" loading="lazy"
                                                 style="width:36px; height:36px; object-fit:cover; border:1px solid var(--border,#333); flex-shrink:0;">
                                        <?php endif; ?>
                                        <span style="font-size:0.78rem; color:var(--text-muted,#aaa); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:120px;"
                                              title="<?php echo htmlspecialchars($top_img['title'] ?? ''); ?>"><?php echo htmlspecialchars($top_img['title'] ?? '—'); ?></span>
                                    </a>
                                <?php else: ?>
                                    <span style="color:var(--text-muted,#555); font-size:0.78rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 8px;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <div style="flex:1; height:8px; background:var(--border,#333); border-radius:4px; overflow:hidden;">
                                        <div style="height:100%; width:<?php echo round($share_pct); ?>%; background:var(--accent-primary,#aaa); border-radius:4px;"></div>
                                    </div>
                                    <span style="font-size:0.8rem; color:var(--text-muted,#888); min-width:38px; text-align:right;"><?php echo round($share_pct, 1); ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:var(--text-muted,#888);">No stats returned from any spoke for this period.</p>
        <?php endif; ?>
    </div>

    <!-- TOP REFERRERS -->
    <?php if (!empty($top_referrers)): ?>
    <div class="box">
        <h3>TOP REFERRERS — FLEET WIDE</h3>
        <div style="display:grid; gap:8px;">
            <?php
                $max_ref = max($top_referrers);
                foreach ($top_referrers as $ref => $count):
                    $share_pct = round(($count / $max_ref) * 100);
            ?>
                <div style="display:flex; align-items:center; gap:12px; font-size:0.85rem;">
                    <div style="min-width:180px; color:var(--text-muted,#888); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                         title="<?php echo htmlspecialchars($ref); ?>">
                        <?php echo htmlspecialchars($ref ?: 'Direct / Unknown'); ?>
                    </div>
                    <div style="flex:1; height:6px; background:var(--border,#333); border-radius:4px; overflow:hidden;">
                        <div style="height:100%; width:<?php echo $share_pct; ?>%; background:var(--accent-primary,#aaa); border-radius:4px;"></div>
                    </div>
                    <div style="min-width:40px; text-align:right; font-size:0.8rem; color:var(--text-muted,#888);"><?php echo number_format($count); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- BROWSERS + OS -->
    <?php if (!empty($fleet_browsers) || !empty($fleet_os)): ?>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">

        <?php if (!empty($fleet_browsers)): ?>
        <div class="box">
            <h3>BROWSERS — FLEET WIDE</h3>
            <?php $max_b = max($fleet_browsers); ?>
            <div style="display:grid; gap:8px; margin-top:8px;">
                <?php foreach ($fleet_browsers as $browser => $hits):
                    $pct = round(($hits / $max_b) * 100); ?>
                <div style="display:flex; align-items:center; gap:10px; font-size:0.85rem;">
                    <div style="min-width:110px; color:var(--text-muted,#888); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo htmlspecialchars($browser ?: 'Unknown'); ?></div>
                    <div style="flex:1; height:6px; background:var(--border,#333); border-radius:4px; overflow:hidden;">
                        <div style="height:100%; width:<?php echo $pct; ?>%; background:var(--accent-primary,#aaa); border-radius:4px;"></div>
                    </div>
                    <div style="min-width:36px; text-align:right; font-size:0.78rem; color:var(--text-muted,#888);"><?php echo number_format($hits); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($fleet_os)): ?>
        <div class="box">
            <h3>OPERATING SYSTEMS — FLEET WIDE</h3>
            <?php $max_os = max($fleet_os); ?>
            <div style="display:grid; gap:8px; margin-top:8px;">
                <?php foreach ($fleet_os as $os => $hits):
                    $pct = round(($hits / $max_os) * 100); ?>
                <div style="display:flex; align-items:center; gap:10px; font-size:0.85rem;">
                    <div style="min-width:110px; color:var(--text-muted,#888); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo htmlspecialchars($os ?: 'Unknown'); ?></div>
                    <div style="flex:1; height:6px; background:var(--border,#333); border-radius:4px; overflow:hidden;">
                        <div style="height:100%; width:<?php echo $pct; ?>%; background:var(--accent-primary,#aaa); border-radius:4px;"></div>
                    </div>
                    <div style="min-width:36px; text-align:right; font-size:0.78rem; color:var(--text-muted,#888);"><?php echo number_format($hits); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <!-- CATEGORIES + SEARCH TERMS -->
    <?php if (!empty($fleet_categories) || !empty($fleet_search_terms)): ?>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">

        <?php if (!empty($fleet_categories)): ?>
        <div class="box">
            <h3>VIEWS BY CATEGORY — FLEET WIDE</h3>
            <?php $max_cat = max($fleet_categories); ?>
            <div style="display:grid; gap:8px; margin-top:8px;">
                <?php foreach ($fleet_categories as $cat => $views):
                    $pct = round(($views / $max_cat) * 100); ?>
                <div style="display:flex; align-items:center; gap:10px; font-size:0.85rem;">
                    <div style="min-width:110px; color:var(--text-muted,#888); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo htmlspecialchars($cat); ?></div>
                    <div style="flex:1; height:6px; background:var(--border,#333); border-radius:4px; overflow:hidden;">
                        <div style="height:100%; width:<?php echo $pct; ?>%; background:var(--accent-primary,#aaa); border-radius:4px;"></div>
                    </div>
                    <div style="min-width:36px; text-align:right; font-size:0.78rem; color:var(--text-muted,#888);"><?php echo number_format($views); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($fleet_search_terms)): ?>
        <div class="box">
            <h3>SEARCH TERMS — FLEET WIDE</h3>
            <?php $max_st = max($fleet_search_terms); ?>
            <div style="display:grid; gap:8px; margin-top:8px;">
                <?php foreach ($fleet_search_terms as $term => $uses):
                    $pct = round(($uses / $max_st) * 100); ?>
                <div style="display:flex; align-items:center; gap:10px; font-size:0.85rem;">
                    <div style="min-width:110px; color:var(--text-muted,#888); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo htmlspecialchars($term); ?></div>
                    <div style="flex:1; height:6px; background:var(--border,#333); border-radius:4px; overflow:hidden;">
                        <div style="height:100%; width:<?php echo $pct; ?>%; background:var(--accent-primary,#aaa); border-radius:4px;"></div>
                    </div>
                    <div style="min-width:36px; text-align:right; font-size:0.78rem; color:var(--text-muted,#888);"><?php echo number_format($uses); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <!-- PEAK HOURS HEATMAP -->
    <?php
    $peak_has_data = false;
    foreach ($fleet_peak_hours as $row) { foreach ($row as $v) { if ($v > 0) { $peak_has_data = true; break 2; } } }
    ?>
    <?php if ($peak_has_data): ?>
    <div class="box">
        <h3>PEAK HOURS — FLEET WIDE</h3>
        <?php $days_of_week = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']; ?>
        <div style="overflow-x:auto; margin-top:8px;">
            <table style="border-collapse:collapse; font-size:0.72rem; width:100%;">
                <thead>
                    <tr>
                        <th style="padding:4px 6px; color:var(--text-muted,#888); text-align:left;"></th>
                        <?php for ($h = 0; $h < 24; $h++): ?>
                        <th style="padding:4px 3px; color:var(--text-muted,#888); text-align:center; font-weight:400;"><?php echo $h; ?></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($d = 1; $d <= 7; $d++): ?>
                    <tr>
                        <td style="padding:3px 6px; color:var(--text-muted,#888); white-space:nowrap;"><?php echo $days_of_week[$d - 1]; ?></td>
                        <?php for ($h = 0; $h < 24; $h++):
                            $v   = $fleet_peak_hours[$d][$h];
                            $int = $v > 0 ? min(1, round(($v / $fleet_peak_max), 2)) : 0;
                            $bg  = $v > 0 ? 'rgba(170,170,170,' . $int . ')' : 'transparent';
                        ?>
                        <td style="padding:2px;" title="<?php echo $days_of_week[$d-1]; ?> <?php echo $h; ?>:00 — <?php echo number_format($v); ?> hits">
                            <div style="width:100%; height:18px; background:<?php echo $bg; ?>; border-radius:2px;"></div>
                        </td>
                        <?php endfor; ?>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- COUNTRIES -->
    <?php if (!empty($fleet_countries)): ?>
    <div class="box">
        <h3>COUNTRIES — FLEET WIDE</h3>
        <?php $max_co = max($fleet_countries); ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:8px; margin-top:8px;">
            <?php foreach ($fleet_countries as $country => $hits):
                $pct = round(($hits / $max_co) * 100); ?>
            <div style="display:flex; align-items:center; gap:10px; font-size:0.85rem;">
                <div style="min-width:130px; color:var(--text-muted,#888); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo htmlspecialchars($country ?: 'Unknown'); ?></div>
                <div style="flex:1; height:6px; background:var(--border,#333); border-radius:4px; overflow:hidden;">
                    <div style="height:100%; width:<?php echo $pct; ?>%; background:var(--accent-primary,#aaa); border-radius:4px;"></div>
                </div>
                <div style="min-width:36px; text-align:right; font-size:0.78rem; color:var(--text-muted,#888);"><?php echo number_format($hits); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // empty($spokes) ?>

</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
