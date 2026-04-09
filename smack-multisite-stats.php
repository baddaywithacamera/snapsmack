<?php
/**
 * SNAPSMACK - Multisite Stats Rollup
 * Alpha v0.7.9b
 *
 * Hub-only page. Fetches daily stats from all active satellites via the
 * multisite/stats/daily API endpoint, aggregates them into a fleet-wide
 * view, and presents traffic trends, per-satellite breakdowns, and top
 * referrers across the entire network.
 */

require_once 'core/auth.php';

// --- HUB GUARD ---
$multisite_role = $settings['multisite_role'] ?? '';
if ($multisite_role !== 'hub') {
    header('Location: smack-multisite.php');
    exit;
}

// --- ACTIVE SATELLITES ---
$satellites = $pdo->query("
    SELECT id, site_url, site_name, api_key_local, post_count, image_count, status
    FROM snap_multisite_nodes
    WHERE role = 'satellite'
    ORDER BY site_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// --- PERIOD SELECTOR ---
$period = in_array((int)($_GET['days'] ?? 30), [7, 30, 90]) ? (int)$_GET['days'] : 30;

// ─────────────────────────────────────────────────────────────────────────────
// FETCH daily stats from each active satellite
// ─────────────────────────────────────────────────────────────────────────────
$sat_stats    = [];   // keyed by node_id → ['site_name' => ..., 'rows' => [...]]
$fetch_errors = [];
$fleet_daily  = [];   // date → ['views' => N, 'unique' => N]

foreach ($satellites as $sat) {
    if ($sat['status'] !== 'active') continue;

    $url = rtrim($sat['site_url'], '/') . '/api.php?route=multisite/stats/daily&days=' . $period;
    $ch  = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $sat['api_key_local'],
            'Accept: application/json',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw || $code !== 200) {
        $fetch_errors[] = $sat['site_name'];
        $sat_stats[$sat['id']] = ['site_name' => $sat['site_name'], 'rows' => [], 'total_views' => 0, 'total_unique' => 0];
        continue;
    }

    $resp = json_decode($raw, true);
    $rows = ($resp['ok'] ?? false) ? ($resp['stats'] ?? []) : [];

    $sat_total_views  = array_sum(array_column($rows, 'total_views'));
    $sat_total_unique = array_sum(array_column($rows, 'unique_visitors'));

    $sat_stats[$sat['id']] = [
        'site_name'    => $sat['site_name'],
        'site_url'     => $sat['site_url'],
        'rows'         => $rows,
        'total_views'  => $sat_total_views,
        'total_unique' => $sat_total_unique,
        'post_count'   => $sat['post_count'],
    ];

    // Merge into fleet daily totals
    foreach ($rows as $row) {
        $date = $row['stat_date'] ?? '';
        if (!$date) continue;
        if (!isset($fleet_daily[$date])) {
            $fleet_daily[$date] = ['views' => 0, 'unique' => 0, 'referrers' => []];
        }
        $fleet_daily[$date]['views']  += (int)($row['total_views']      ?? 0);
        $fleet_daily[$date]['unique'] += (int)($row['unique_visitors']   ?? 0);
        if (!empty($row['top_referrer'])) {
            $ref = parse_url($row['top_referrer'], PHP_URL_HOST) ?: $row['top_referrer'];
            $fleet_daily[$date]['referrers'][$ref] = ($fleet_daily[$date]['referrers'][$ref] ?? 0) + (int)($row['total_views'] ?? 1);
        }
    }
}

// Sort fleet daily by date ascending for the chart
ksort($fleet_daily);

// Fleet totals
$fleet_total_views  = array_sum(array_column($fleet_daily, 'views'));
$fleet_total_unique = array_sum(array_column($fleet_daily, 'unique'));

// Top referrers across the fleet
$all_referrers = [];
foreach ($fleet_daily as $day) {
    foreach ($day['referrers'] as $ref => $count) {
        $all_referrers[$ref] = ($all_referrers[$ref] ?? 0) + $count;
    }
}
arsort($all_referrers);
$top_referrers = array_slice($all_referrers, 0, 10, true);

// Satellite ranking by views
uasort($sat_stats, fn($a, $b) => $b['total_views'] - $a['total_views']);

// Sparkline max for scaling
$max_daily_views = $fleet_daily ? max(array_column(array_values($fleet_daily), 'views')) : 0;

$page_title = "Fleet Stats";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>FLEET STATS ROLLUP</h2>
        <div class="header-actions">
            <div class="status-pill status-online">
                <?php echo $period; ?>-DAY WINDOW
            </div>
        </div>
    </div>

    <!-- QUICK NAV -->
    <div class="signal-control-header" style="margin-bottom:20px;">
        <div class="signal-nav-group">
            <a href="smack-multisite.php"          class="btn-clear">DASHBOARD</a>
            <a href="smack-multisite-comments.php" class="btn-clear">SIGNALS</a>
            <a href="smack-multisite-posts.php"    class="btn-clear">POSTS</a>
            <a href="smack-multisite-backup.php"   class="btn-clear">BACKUP DOCK</a>
            <a href="smack-multisite-stats.php"       class="btn-clear active">STATS</a>
            <a href="smack-multisite-crosspost.php"   class="btn-clear">CROSS-POST</a>
                <a href="smack-multisite-blogroll.php"    class="btn-clear">BLOGROLL</a>
            <span class="sep">|</span>
            <a href="?days=7"  class="btn-clear <?php echo $period === 7  ? 'active' : ''; ?>">7D</a>
            <a href="?days=30" class="btn-clear <?php echo $period === 30 ? 'active' : ''; ?>">30D</a>
            <a href="?days=90" class="btn-clear <?php echo $period === 90 ? 'active' : ''; ?>">90D</a>
        </div>
    </div>

    <?php if (!empty($fetch_errors)): ?>
        <div class="alert alert-error">> OFFLINE SATELLITES (no stats): <?php echo htmlspecialchars(implode(', ', $fetch_errors)); ?></div>
    <?php endif; ?>

    <?php if (empty($satellites)): ?>
        <div class="box">
            <p style="color:var(--text-muted,#888);">No satellites connected. <a href="smack-multisite.php" style="color:var(--accent,#aaa);">Register a satellite</a> first.</p>
        </div>
    <?php else: ?>

    <!-- FLEET TOTALS -->
    <div class="box">
        <h3>FLEET TOTALS — LAST <?php echo $period; ?> DAYS</h3>

        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:15px; margin-bottom:25px;">
            <?php
                $fleet_posts  = array_sum(array_column($sat_stats, 'post_count'));
                $active_sats  = count(array_filter($satellites, fn($s) => $s['status'] === 'active'));
            ?>
            <div style="padding:20px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo number_format($fleet_total_views); ?></div>
                <div style="font-size:0.75rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">TOTAL VIEWS</div>
            </div>
            <div style="padding:20px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo number_format($fleet_total_unique); ?></div>
                <div style="font-size:0.75rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">UNIQUE VISITORS</div>
            </div>
            <div style="padding:20px; border:1px solid var(--border,#333); background:var(--input-bg,#111); text-align:center;">
                <div style="font-size:2rem; font-weight:900; color:var(--text,#eee);"><?php echo $active_sats; ?> / <?php echo count($satellites); ?></div>
                <div style="font-size:0.75rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">SITES REPORTING</div>
            </div>
        </div>

        <!-- FLEET SPARKLINE -->
        <?php if (!empty($fleet_daily) && $max_daily_views > 0): ?>
            <div style="margin-top:10px;">
                <div style="font-size:0.75rem; color:var(--text-muted,#888); letter-spacing:1px; margin-bottom:8px;">
                    DAILY FLEET TRAFFIC
                </div>
                <div class="stats-sparkline" style="height:80px; display:flex; align-items:flex-end; gap:2px; width:100%;">
                    <?php
                        $bar_width = 100 / max(count($fleet_daily), 1);
                        foreach ($fleet_daily as $date => $day):
                            $pct = max(round(($day['views'] / $max_daily_views) * 100), 1);
                    ?>
                        <div class="stats-spark-bar"
                             style="height:<?php echo $pct; ?>%; width:<?php echo $bar_width; ?>%; min-width:2px; flex-shrink:0;"
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

    <!-- PER-SATELLITE BREAKDOWN -->
    <div class="box">
        <h3>SATELLITE BREAKDOWN</h3>

        <?php if (!empty($sat_stats)):
            $max_sat_views = max(1, max(array_column($sat_stats, 'total_views')));
        ?>
            <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border,#333);">
                        <th style="text-align:left;   padding:10px; color:var(--text-muted,#888);">SATELLITE</th>
                        <th style="text-align:center; padding:10px; color:var(--text-muted,#888);">VIEWS</th>
                        <th style="text-align:center; padding:10px; color:var(--text-muted,#888);">UNIQUE</th>
                        <th style="text-align:center; padding:10px; color:var(--text-muted,#888);">AVG/DAY</th>
                        <th style="text-align:left;   padding:10px; color:var(--text-muted,#888); width:35%;">SHARE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sat_stats as $node_id => $sd):
                        $share_pct = $fleet_total_views > 0 ? ($sd['total_views'] / $fleet_total_views) * 100 : 0;
                        $avg_day   = $period > 0 ? round($sd['total_views'] / $period) : 0;
                    ?>
                        <tr style="border-bottom:1px solid var(--border,#333);">
                            <td style="padding:10px;">
                                <strong><?php echo htmlspecialchars($sd['site_name']); ?></strong>
                                <?php if (in_array($node_id, array_column(array_filter($satellites, fn($s) => $s['status'] !== 'active'), 'id'))): ?>
                                    <span style="font-size:0.75rem; color:#f44336; margin-left:5px;">OFFLINE</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px; text-align:center; font-weight:700;">
                                <?php echo number_format($sd['total_views']); ?>
                            </td>
                            <td style="padding:10px; text-align:center; color:var(--text-muted,#888);">
                                <?php echo number_format($sd['total_unique']); ?>
                            </td>
                            <td style="padding:10px; text-align:center; color:var(--text-muted,#888);">
                                <?php echo number_format($avg_day); ?>
                            </td>
                            <td style="padding:10px;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <div style="flex:1; height:8px; background:var(--border,#333); border-radius:4px; overflow:hidden;">
                                        <div style="height:100%; width:<?php echo round($share_pct); ?>%; background:var(--accent-primary, #aaa); border-radius:4px;"></div>
                                    </div>
                                    <span style="font-size:0.8rem; color:var(--text-muted,#888); min-width:38px; text-align:right;">
                                        <?php echo round($share_pct, 1); ?>%
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:var(--text-muted,#888);">No stats returned from any satellite for this period.</p>
        <?php endif; ?>
    </div>

    <!-- TOP REFERRERS ACROSS FLEET -->
    <?php if (!empty($top_referrers)): ?>
    <div class="box">
        <h3>TOP REFERRERS — FLEET WIDE</h3>
        <div style="display:grid; gap:8px;">
            <?php
                $max_ref = max($top_referrers);
                foreach ($top_referrers as $ref => $count):
                    $ref_pct = round(($count / $max_ref) * 100);
            ?>
                <div style="display:flex; align-items:center; gap:12px; font-size:0.85rem;">
                    <div style="min-width:180px; color:var(--text-muted,#888); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                         title="<?php echo htmlspecialchars($ref); ?>">
                        <?php echo htmlspecialchars($ref ?: 'Direct / Unknown'); ?>
                    </div>
                    <div style="flex:1; height:6px; background:var(--border,#333); border-radius:3px; overflow:hidden;">
                        <div style="height:100%; width:<?php echo $ref_pct; ?>%; background:var(--accent-primary,#aaa);"></div>
                    </div>
                    <div style="min-width:50px; text-align:right; color:var(--text-muted,#888);">
                        <?php echo number_format($count); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
