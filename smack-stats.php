<?php
/**
 * SNAPSMACK - Traffic Stats Dashboard
 *
 * Three-tier analytics dashboard for SnapSmack photoblogs.
 *
 * Tier 1 — The Basics: Total views, uniques, recent visits, top referrers,
 *          browser/OS breakdown.
 * Tier 2 — SnapSmack-Specific: Most viewed images (with thumbnails), views by
 *          category, archive search terms, direct-link vs browsed-to ratio.
 * Tier 3 — Nice-to-Haves: Sparkline traffic trends, peak hours heatmap,
 *          geographic breakdown, visitor flow analysis.
 */

require_once 'core/auth.php';
require_once 'core/stats-logger.php';

// --- HANDLE POST ACTIONS ---
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'rollup') {
        // Roll up yesterday's stats (or a specific date)
        $rollup_date = $_POST['rollup_date'] ?? null;
        snapsmack_rollup_daily($pdo, $rollup_date);
        header('Location: smack-stats.php?msg=rollup_ok');
        exit;
    }
    if ($_POST['action'] === 'purge') {
        $days = (int)($settings['stats_retention_days'] ?? 365);
        $deleted = snapsmack_purge_old_stats($pdo, $days);
        header('Location: smack-stats.php?msg=purge_ok&n=' . $deleted);
        exit;
    }
    if ($_POST['action'] === 'toggle_stats') {
        $new_val = ($_POST['enabled'] ?? '1') === '1' ? '1' : '0';
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('stats_enabled', ?)
                       ON DUPLICATE KEY UPDATE setting_val = ?")->execute([$new_val, $new_val]);
        header('Location: smack-stats.php');
        exit;
    }
}

// --- LOAD SETTINGS ---
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$stats_enabled = ($settings['stats_enabled'] ?? '1') === '1';

// --- DATE RANGE ---
$range = $_GET['range'] ?? '30';
$valid_ranges = ['today' => 0, '7' => 7, '30' => 30, '90' => 90, '365' => 365, 'all' => 99999];
if (!isset($valid_ranges[$range])) $range = '30';

if ($range === 'today') {
    $date_from = date('Y-m-d 00:00:00');
} elseif ($range === 'all') {
    $date_from = '2000-01-01 00:00:00';
} else {
    $date_from = date('Y-m-d 00:00:00', strtotime("-{$range} days"));
}
$date_to = date('Y-m-d 23:59:59');

// --- CHECK TABLE EXISTS ---
$table_exists = false;
try {
    $pdo->query("SELECT 1 FROM snap_stats LIMIT 1");
    $table_exists = true;
} catch (PDOException $e) {
    // Table doesn't exist yet
}

// ═══════════════════════════════════════════════════════════════
// TIER 1: THE BASICS
// ═══════════════════════════════════════════════════════════════

$t1_total_views = $t1_unique_visitors = $t1_views_today = $t1_bot_views = 0;
$t1_recent_visits = $t1_top_referrers = $t1_browsers = $t1_os = [];

if ($table_exists) {
    // Total views (no bots) in range
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_stats WHERE hit_at >= ? AND hit_at <= ? AND is_bot = 0");
    $stmt->execute([$date_from, $date_to]);
    $t1_total_views = (int)$stmt->fetchColumn();

    // Unique visitors in range
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT ip_hash) FROM snap_stats WHERE hit_at >= ? AND hit_at <= ? AND is_bot = 0");
    $stmt->execute([$date_from, $date_to]);
    $t1_unique_visitors = (int)$stmt->fetchColumn();

    // Views today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_stats WHERE DATE(hit_at) = CURDATE() AND is_bot = 0");
    $stmt->execute();
    $t1_views_today = (int)$stmt->fetchColumn();

    // Bot views in range
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_stats WHERE hit_at >= ? AND hit_at <= ? AND is_bot = 1");
    $stmt->execute([$date_from, $date_to]);
    $t1_bot_views = (int)$stmt->fetchColumn();

    // Recent visits (last 50)
    $stmt = $pdo->prepare("
        SELECT s.hit_at, s.page_type, s.page_slug, s.image_id,
               s.referrer_host, s.browser, s.os, s.country, s.is_bot,
               i.img_title, i.img_file
        FROM snap_stats s
        LEFT JOIN snap_images i ON s.image_id = i.id
        WHERE s.is_bot = 0
        ORDER BY s.hit_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $t1_recent_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top referrers
    $stmt = $pdo->prepare("
        SELECT referrer_host, COUNT(*) as hits
        FROM snap_stats
        WHERE hit_at >= ? AND hit_at <= ? AND is_bot = 0
              AND referrer_host IS NOT NULL AND referrer_host != ''
        GROUP BY referrer_host
        ORDER BY hits DESC
        LIMIT 15
    ");
    $stmt->execute([$date_from, $date_to]);
    $t1_top_referrers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Browser breakdown
    $stmt = $pdo->prepare("
        SELECT browser, COUNT(*) as hits
        FROM snap_stats
        WHERE hit_at >= ? AND hit_at <= ? AND is_bot = 0
        GROUP BY browser
        ORDER BY hits DESC
    ");
    $stmt->execute([$date_from, $date_to]);
    $t1_browsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // OS breakdown
    $stmt = $pdo->prepare("
        SELECT os, COUNT(*) as hits
        FROM snap_stats
        WHERE hit_at >= ? AND hit_at <= ? AND is_bot = 0
        GROUP BY os
        ORDER BY hits DESC
    ");
    $stmt->execute([$date_from, $date_to]);
    $t1_os = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ═══════════════════════════════════════════════════════════════
// TIER 2: SNAPSMACK-SPECIFIC INSIGHTS
// ═══════════════════════════════════════════════════════════════

$t2_top_images = $t2_category_views = $t2_search_terms = [];
$t2_direct_hits = $t2_browsed_hits = 0;

if ($table_exists) {
    // Most viewed images (with thumbnails)
    $stmt = $pdo->prepare("
        SELECT s.image_id, i.img_title, i.img_file, i.img_slug, COUNT(*) as views
        FROM snap_stats s
        JOIN snap_images i ON s.image_id = i.id
        WHERE s.hit_at >= ? AND s.hit_at <= ? AND s.is_bot = 0
              AND s.image_id IS NOT NULL
        GROUP BY s.image_id, i.img_title, i.img_file, i.img_slug
        ORDER BY views DESC
        LIMIT 20
    ");
    $stmt->execute([$date_from, $date_to]);
    $t2_top_images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Views by category
    $stmt = $pdo->prepare("
        SELECT c.cat_name, COUNT(*) as views
        FROM snap_stats s
        JOIN snap_image_cat_map m ON s.image_id = m.image_id
        JOIN snap_categories c ON m.cat_id = c.id
        WHERE s.hit_at >= ? AND s.hit_at <= ? AND s.is_bot = 0
              AND s.image_id IS NOT NULL
        GROUP BY c.id, c.cat_name
        ORDER BY views DESC
        LIMIT 15
    ");
    $stmt->execute([$date_from, $date_to]);
    $t2_category_views = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Archive search terms (from ?search= usage on archive pages)
    $stmt = $pdo->prepare("
        SELECT search_term, COUNT(*) as uses
        FROM snap_stats
        WHERE hit_at >= ? AND hit_at <= ? AND is_bot = 0
              AND search_term IS NOT NULL AND search_term != ''
        GROUP BY search_term
        ORDER BY uses DESC
        LIMIT 20
    ");
    $stmt->execute([$date_from, $date_to]);
    $t2_search_terms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Direct link vs browsed-to ratio
    // Direct = referrer is empty or from external; Browsed = referrer is own site
    $site_host = snapsmack_referrer_host($settings['site_url'] ?? '');
    if ($site_host) {
        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN referrer_host IS NULL OR referrer_host != ? THEN 1 ELSE 0 END) as direct_hits,
                SUM(CASE WHEN referrer_host = ? THEN 1 ELSE 0 END) as browsed_hits
            FROM snap_stats
            WHERE hit_at >= ? AND hit_at <= ? AND is_bot = 0 AND page_type = 'image'
        ");
        $stmt->execute([$site_host, $site_host, $date_from, $date_to]);
        $ratio = $stmt->fetch(PDO::FETCH_ASSOC);
        $t2_direct_hits  = (int)($ratio['direct_hits'] ?? 0);
        $t2_browsed_hits = (int)($ratio['browsed_hits'] ?? 0);
    }
}

// ═══════════════════════════════════════════════════════════════
// TIER 3: SPARKLINES, PEAK HOURS, GEOGRAPHIC, FLOW
// ═══════════════════════════════════════════════════════════════

$t3_daily_views = $t3_hourly_heatmap = $t3_countries = $t3_flow = [];

if ($table_exists) {
    // Daily view counts for sparkline (last 30 days always)
    $stmt = $pdo->prepare("
        SELECT DATE(hit_at) as day, COUNT(*) as views
        FROM snap_stats
        WHERE hit_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND is_bot = 0
        GROUP BY DATE(hit_at)
        ORDER BY day ASC
    ");
    $stmt->execute();
    $t3_daily_views = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Peak hours heatmap (hour of day × day of week)
    $stmt = $pdo->prepare("
        SELECT DAYOFWEEK(hit_at) as dow, HOUR(hit_at) as hour, COUNT(*) as hits
        FROM snap_stats
        WHERE hit_at >= ? AND hit_at <= ? AND is_bot = 0
        GROUP BY DAYOFWEEK(hit_at), HOUR(hit_at)
    ");
    $stmt->execute([$date_from, $date_to]);
    $raw_heatmap = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build 7×24 grid
    for ($d = 1; $d <= 7; $d++) {
        for ($h = 0; $h < 24; $h++) {
            $t3_hourly_heatmap[$d][$h] = 0;
        }
    }
    foreach ($raw_heatmap as $r) {
        $t3_hourly_heatmap[(int)$r['dow']][(int)$r['hour']] = (int)$r['hits'];
    }

    // Geographic breakdown (by country)
    $stmt = $pdo->prepare("
        SELECT country, COUNT(*) as hits
        FROM snap_stats
        WHERE hit_at >= ? AND hit_at <= ? AND is_bot = 0
              AND country IS NOT NULL AND country != ''
        GROUP BY country
        ORDER BY hits DESC
        LIMIT 20
    ");
    $stmt->execute([$date_from, $date_to]);
    $t3_countries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Visitor flow: where did they go next?
    // For image pages, what page_type did the next hit from the same ip_hash go to?
    $stmt = $pdo->prepare("
        SELECT
            s1.page_type as from_type,
            s2.page_type as to_type,
            COUNT(*) as transitions
        FROM snap_stats s1
        JOIN snap_stats s2 ON s1.ip_hash = s2.ip_hash
            AND s2.hit_at > s1.hit_at
            AND s2.hit_at <= DATE_ADD(s1.hit_at, INTERVAL 30 MINUTE)
            AND s2.id = (
                SELECT MIN(s3.id) FROM snap_stats s3
                WHERE s3.ip_hash = s1.ip_hash AND s3.hit_at > s1.hit_at
                  AND s3.hit_at <= DATE_ADD(s1.hit_at, INTERVAL 30 MINUTE)
            )
        WHERE s1.hit_at >= ? AND s1.hit_at <= ? AND s1.is_bot = 0
        GROUP BY s1.page_type, s2.page_type
        ORDER BY transitions DESC
        LIMIT 20
    ");
    try {
        $stmt->execute([$date_from, $date_to]);
        $t3_flow = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Flow query is expensive — gracefully degrade on large datasets
        $t3_flow = [];
    }
}

// ═══════════════════════════════════════════════════════════════
// RENDER
// ═══════════════════════════════════════════════════════════════

$page_title = 'Traffic Stats';
include 'core/admin-header.php';
include 'core/sidebar.php';

$range_labels = [
    'today' => 'Today',
    '7'     => 'Last 7 Days',
    '30'    => 'Last 30 Days',
    '90'    => 'Last 90 Days',
    '365'   => 'Last Year',
    'all'   => 'All Time',
];
$msg = $_GET['msg'] ?? '';
?>

<div class="main">
    <h2>TRAFFIC STATS</h2>

    <?php if ($msg === 'rollup_ok'): ?>
        <div class="notice notice-success">Daily rollup complete.</div>
    <?php elseif ($msg === 'purge_ok'): ?>
        <div class="notice notice-success">Purged <?php echo (int)($_GET['n'] ?? 0); ?> old records.</div>
    <?php endif; ?>

    <?php if (!$table_exists): ?>
        <div class="notice notice-warning">
            The stats tables haven't been created yet. Run <code>migrate-077.sql</code> to get started.
        </div>
    <?php else: ?>

    <!-- RANGE SELECTOR -->
    <div class="stats-range-bar">
        <?php foreach ($range_labels as $rk => $rl): ?>
            <a href="smack-stats.php?range=<?php echo $rk; ?>"
               class="stats-range-pill<?php echo ($range === $rk) ? ' active' : ''; ?>"><?php echo $rl; ?></a>
        <?php endforeach; ?>

        <span class="stats-range-spacer"></span>

        <form method="post" class="stats-action-inline">
            <input type="hidden" name="action" value="toggle_stats">
            <input type="hidden" name="enabled" value="<?php echo $stats_enabled ? '0' : '1'; ?>">
            <button type="submit" class="btn btn-small <?php echo $stats_enabled ? 'btn-danger' : 'btn-success'; ?>">
                <?php echo $stats_enabled ? 'Disable Tracking' : 'Enable Tracking'; ?>
            </button>
        </form>
    </div>

    <!-- ═══════════════════════════════════════════════ -->
    <!-- TIER 1: THE BASICS                             -->
    <!-- ═══════════════════════════════════════════════ -->

    <div class="stats-scorecard-row">
        <div class="stats-scorecard">
            <div class="stats-scorecard-value"><?php echo number_format($t1_total_views); ?></div>
            <div class="stats-scorecard-label">Total Views</div>
        </div>
        <div class="stats-scorecard">
            <div class="stats-scorecard-value"><?php echo number_format($t1_unique_visitors); ?></div>
            <div class="stats-scorecard-label">Unique Visitors</div>
        </div>
        <div class="stats-scorecard">
            <div class="stats-scorecard-value"><?php echo number_format($t1_views_today); ?></div>
            <div class="stats-scorecard-label">Views Today</div>
        </div>
        <div class="stats-scorecard">
            <div class="stats-scorecard-value"><?php echo number_format($t1_bot_views); ?></div>
            <div class="stats-scorecard-label">Bot Hits</div>
        </div>
    </div>

    <!-- SPARKLINE: 30-Day Trend -->
    <?php if (!empty($t3_daily_views)): ?>
    <div class="stats-section">
        <h3>30-Day Traffic</h3>
        <div class="stats-sparkline-container">
            <?php
            $max_views = max(array_column($t3_daily_views, 'views'));
            $spark_width  = 100 / max(count($t3_daily_views), 1);
            ?>
            <div class="stats-sparkline" title="30-day traffic trend">
                <?php foreach ($t3_daily_views as $dv): ?>
                    <?php $pct = $max_views > 0 ? round(($dv['views'] / $max_views) * 100) : 0; ?>
                    <div class="stats-spark-bar"
                         style="height: <?php echo max($pct, 2); ?>%; width: <?php echo $spark_width; ?>%;"
                         title="<?php echo $dv['day']; ?>: <?php echo $dv['views']; ?> views"></div>
                <?php endforeach; ?>
            </div>
            <div class="stats-sparkline-labels">
                <span><?php echo $t3_daily_views[0]['day'] ?? ''; ?></span>
                <span><?php echo end($t3_daily_views)['day'] ?? ''; ?></span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- RECENT VISITS -->
    <div class="stats-section">
        <h3>Recent Visits</h3>
        <?php if (empty($t1_recent_visits)): ?>
            <p class="dim">No visits recorded yet.</p>
        <?php else: ?>
        <div class="stats-table-wrap">
            <table class="stats-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Page</th>
                        <th>Referrer</th>
                        <th>Browser</th>
                        <th>OS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($t1_recent_visits as $v): ?>
                    <tr>
                        <td class="stats-nowrap"><?php echo date('M j, g:ia', strtotime($v['hit_at'])); ?></td>
                        <td>
                            <?php if ($v['page_type'] === 'image' && $v['img_title']): ?>
                                <span class="stats-page-type stats-type-image">IMG</span>
                                <?php echo htmlspecialchars($v['img_title']); ?>
                            <?php elseif ($v['page_slug']): ?>
                                <span class="stats-page-type stats-type-<?php echo htmlspecialchars($v['page_type']); ?>"><?php echo strtoupper(substr($v['page_type'], 0, 3)); ?></span>
                                /<?php echo htmlspecialchars($v['page_slug']); ?>
                            <?php else: ?>
                                <span class="stats-page-type stats-type-<?php echo htmlspecialchars($v['page_type']); ?>"><?php echo strtoupper(substr($v['page_type'], 0, 3)); ?></span>
                                <?php echo htmlspecialchars($v['page_type']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($v['referrer_host'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($v['browser'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($v['os'] ?? ''); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- TWO-COLUMN: Referrers + Browsers/OS -->
    <div class="stats-columns">
        <div class="stats-column">
            <h3>Top Referrers</h3>
            <?php if (empty($t1_top_referrers)): ?>
                <p class="dim">No referrer data yet.</p>
            <?php else: ?>
                <?php $max_ref = $t1_top_referrers[0]['hits'] ?? 1; ?>
                <div class="stats-bar-list">
                    <?php foreach ($t1_top_referrers as $ref): ?>
                    <div class="stats-bar-item">
                        <div class="stats-bar-fill" style="width: <?php echo round(($ref['hits'] / $max_ref) * 100); ?>%;"></div>
                        <span class="stats-bar-label"><?php echo htmlspecialchars($ref['referrer_host']); ?></span>
                        <span class="stats-bar-count"><?php echo number_format($ref['hits']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="stats-column">
            <h3>Browsers</h3>
            <?php if (!empty($t1_browsers)): ?>
                <?php $max_br = $t1_browsers[0]['hits'] ?? 1; ?>
                <div class="stats-bar-list">
                    <?php foreach ($t1_browsers as $br): ?>
                    <div class="stats-bar-item">
                        <div class="stats-bar-fill stats-bar-alt" style="width: <?php echo round(($br['hits'] / $max_br) * 100); ?>%;"></div>
                        <span class="stats-bar-label"><?php echo htmlspecialchars($br['browser']); ?></span>
                        <span class="stats-bar-count"><?php echo number_format($br['hits']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3 style="margin-top: 20px;">Platforms</h3>
            <?php if (!empty($t1_os)): ?>
                <?php $max_os = $t1_os[0]['hits'] ?? 1; ?>
                <div class="stats-bar-list">
                    <?php foreach ($t1_os as $o): ?>
                    <div class="stats-bar-item">
                        <div class="stats-bar-fill stats-bar-alt2" style="width: <?php echo round(($o['hits'] / $max_os) * 100); ?>%;"></div>
                        <span class="stats-bar-label"><?php echo htmlspecialchars($o['os']); ?></span>
                        <span class="stats-bar-count"><?php echo number_format($o['hits']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════ -->
    <!-- TIER 2: SNAPSMACK-SPECIFIC                     -->
    <!-- ═══════════════════════════════════════════════ -->

    <!-- MOST VIEWED IMAGES -->
    <?php if (!empty($t2_top_images)): ?>
    <div class="stats-section">
        <h3>Most Viewed Images</h3>
        <div class="stats-image-grid">
            <?php foreach ($t2_top_images as $ti): ?>
            <div class="stats-image-card">
                <?php if (!empty($ti['img_file'])): ?>
                <a href="<?php echo htmlspecialchars($ti['img_slug']); ?>">
                    <img src="/<?php echo ltrim(htmlspecialchars($ti['img_file']), '/'); ?>"
                         alt="<?php echo htmlspecialchars($ti['img_title'] ?? ''); ?>"
                         loading="lazy">
                </a>
                <?php endif; ?>
                <div class="stats-image-meta">
                    <span class="stats-image-title"><?php echo htmlspecialchars($ti['img_title'] ?? $ti['img_slug']); ?></span>
                    <span class="stats-image-views"><?php echo number_format($ti['views']); ?> views</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- TWO-COLUMN: Categories + Search Terms -->
    <div class="stats-columns">
        <div class="stats-column">
            <h3>Views by Category</h3>
            <?php if (empty($t2_category_views)): ?>
                <p class="dim">No category data yet.</p>
            <?php else: ?>
                <?php $max_cat = $t2_category_views[0]['views'] ?? 1; ?>
                <div class="stats-bar-list">
                    <?php foreach ($t2_category_views as $cv): ?>
                    <div class="stats-bar-item">
                        <div class="stats-bar-fill stats-bar-cat" style="width: <?php echo round(($cv['views'] / $max_cat) * 100); ?>%;"></div>
                        <span class="stats-bar-label"><?php echo htmlspecialchars($cv['cat_name']); ?></span>
                        <span class="stats-bar-count"><?php echo number_format($cv['views']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="stats-column">
            <h3>Search Terms</h3>
            <?php if (empty($t2_search_terms)): ?>
                <p class="dim">No search queries yet.</p>
            <?php else: ?>
                <div class="stats-bar-list">
                    <?php foreach ($t2_search_terms as $st): ?>
                    <div class="stats-bar-item">
                        <span class="stats-bar-label">"<?php echo htmlspecialchars($st['search_term']); ?>"</span>
                        <span class="stats-bar-count"><?php echo number_format($st['uses']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- DIRECT VS BROWSED -->
            <?php if ($t2_direct_hits + $t2_browsed_hits > 0): ?>
            <h3 style="margin-top: 20px;">How They Found Images</h3>
            <div class="stats-ratio-bar">
                <?php
                $total_ratio = $t2_direct_hits + $t2_browsed_hits;
                $direct_pct = round(($t2_direct_hits / $total_ratio) * 100);
                $browse_pct = 100 - $direct_pct;
                ?>
                <div class="stats-ratio-segment stats-ratio-direct" style="width: <?php echo $direct_pct; ?>%;">
                    <?php if ($direct_pct > 15): ?>Direct <?php echo $direct_pct; ?>%<?php endif; ?>
                </div>
                <div class="stats-ratio-segment stats-ratio-browsed" style="width: <?php echo $browse_pct; ?>%;">
                    <?php if ($browse_pct > 15): ?>Browsed <?php echo $browse_pct; ?>%<?php endif; ?>
                </div>
            </div>
            <div class="stats-ratio-legend">
                <span><span class="stats-dot stats-dot-direct"></span> Direct / External (<?php echo number_format($t2_direct_hits); ?>)</span>
                <span><span class="stats-dot stats-dot-browsed"></span> Browsed from site (<?php echo number_format($t2_browsed_hits); ?>)</span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════ -->
    <!-- TIER 3: PEAK HOURS HEATMAP                     -->
    <!-- ═══════════════════════════════════════════════ -->

    <?php
    $heatmap_max = 1;
    foreach ($t3_hourly_heatmap as $day_data) {
        $day_max = max($day_data);
        if ($day_max > $heatmap_max) $heatmap_max = $day_max;
    }
    $day_names = [1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat'];
    ?>
    <?php if ($heatmap_max > 1): ?>
    <div class="stats-section">
        <h3>Peak Hours</h3>
        <div class="stats-heatmap-wrap">
            <table class="stats-heatmap">
                <thead>
                    <tr>
                        <th></th>
                        <?php for ($h = 0; $h < 24; $h++): ?>
                        <th><?php echo $h; ?></th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($day_names as $dn => $dl): ?>
                    <tr>
                        <td class="stats-heatmap-day"><?php echo $dl; ?></td>
                        <?php for ($h = 0; $h < 24; $h++): ?>
                            <?php $intensity = round(($t3_hourly_heatmap[$dn][$h] / $heatmap_max) * 100); ?>
                            <td class="stats-heatmap-cell"
                                style="--heat: <?php echo $intensity; ?>;"
                                title="<?php echo $dl; ?> <?php echo $h; ?>:00 — <?php echo $t3_hourly_heatmap[$dn][$h]; ?> hits">
                            </td>
                        <?php endfor; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- GEOGRAPHIC BREAKDOWN -->
    <?php if (!empty($t3_countries)): ?>
    <div class="stats-section">
        <h3>Countries</h3>
        <?php $max_country = $t3_countries[0]['hits'] ?? 1; ?>
        <div class="stats-bar-list stats-bar-list-narrow">
            <?php foreach ($t3_countries as $c): ?>
            <div class="stats-bar-item">
                <div class="stats-bar-fill stats-bar-geo" style="width: <?php echo round(($c['hits'] / $max_country) * 100); ?>%;"></div>
                <span class="stats-bar-label"><?php echo htmlspecialchars(strtoupper($c['country'])); ?></span>
                <span class="stats-bar-count"><?php echo number_format($c['hits']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- VISITOR FLOW -->
    <?php if (!empty($t3_flow)): ?>
    <div class="stats-section">
        <h3>Where They Go Next</h3>
        <div class="stats-flow-grid">
            <?php foreach ($t3_flow as $f): ?>
            <div class="stats-flow-item">
                <span class="stats-flow-from"><?php echo htmlspecialchars($f['from_type']); ?></span>
                <span class="stats-flow-arrow">&rarr;</span>
                <span class="stats-flow-to"><?php echo htmlspecialchars($f['to_type']); ?></span>
                <span class="stats-flow-count"><?php echo number_format($f['transitions']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- MAINTENANCE -->
    <div class="stats-section stats-maintenance">
        <h3>Maintenance</h3>
        <div class="stats-maintenance-actions">
            <form method="post" class="stats-action-inline">
                <input type="hidden" name="action" value="rollup">
                <button type="submit" class="btn btn-small">Run Daily Rollup</button>
            </form>
            <form method="post" class="stats-action-inline"
                  onsubmit="return confirm('Purge stats older than <?php echo (int)($settings['stats_retention_days'] ?? 365); ?> days?');">
                <input type="hidden" name="action" value="purge">
                <button type="submit" class="btn btn-small btn-danger">Purge Old Data</button>
            </form>
        </div>
    </div>

    <?php endif; // table_exists ?>
</div>

<?php include 'core/admin-footer.php'; ?>
