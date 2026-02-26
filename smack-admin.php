<?php
/**
 * SNAPSMACK - Master administrative dashboard.
 * Provides system vitals, content statistics, and automated task management.
 * Handles admin theme discovery and RSS cron job registration.
 * Git Version Official Alpha 0.5
 */

require_once 'core/auth.php';

// --- ADMIN THEME DISCOVERY ---
// Scans the theme directory for manifests to populate the theme selector.
$admin_themes = [];
$theme_dirs = array_filter(glob('assets/adminthemes/*'), 'is_dir');
foreach ($theme_dirs as $dir) {
    $slug = basename($dir);
    $manifest_path = "{$dir}/{$slug}-manifest.php";
    if (file_exists($manifest_path)) {
        $admin_themes[$slug] = include $manifest_path;
    }
}

$active_admin_slug = $settings['active_theme'] ?? 'midnight-lime';
$current_admin_meta = $admin_themes[$active_admin_slug] ?? [
    'name' => 'Midnight Lime',
    'description' => 'Tactical Interface.',
    'version' => '1.1',
    'author' => 'Sean McCormick'
];

// --- DATA ACQUISITION ---
// Aggregate counts for published posts, pending schedules, and drafts.
$count_pub     = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND img_date <= NOW()")->fetchColumn();
$count_pending = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND img_date > NOW()")->fetchColumn();
$count_drafts  = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='draft'")->fetchColumn();

// Aggregate comment moderation statistics.
$pending_count = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 0")->fetchColumn();
$live_count    = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 1")->fetchColumn();

// Identify the most recent entry for the "Latest Smack" preview.
$latest_img = $pdo->query("SELECT * FROM snap_images ORDER BY img_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// --- ENVIRONMENT & VITALS LOGIC ---
// Gather server-side metrics and resource usage.
$server_soft = $_SERVER['SERVER_SOFTWARE'] ?? 'N/A';
$php_ver     = phpversion();
$mem_limit   = ini_get('memory_limit');
$load        = sys_getloadavg() ?: [0,0,0]; 
$disk_free   = disk_free_space("/") ?: 0;
$disk_total  = disk_total_space("/") ?: 1;
$disk_used_pct = ($disk_total > 0) ? round((($disk_total - $disk_free) / $disk_total) * 100, 1) : 0;

if (!function_exists('formatBytes')) {
    /**
     * Helper to convert raw bytes into human-readable strings.
     */
    function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow   = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

// Resolve the thumbnail path for the latest image preview.
$display_thumb = "";
if ($latest_img) {
    $path_parts = pathinfo($latest_img['img_file']);
    $display_thumb = $path_parts['dirname'] . '/thumbs/t_' . $path_parts['basename'];
}

// --- BLOGROLL NETWORK STATS ---
$blogroll_enabled = true;
try {
    $net_peers    = $pdo->query("SELECT COUNT(*) FROM snap_blogroll")->fetchColumn();
    $net_cats     = $pdo->query("SELECT COUNT(*) FROM snap_blogroll_cats")->fetchColumn();
    $net_rss      = $pdo->query("SELECT COUNT(*) FROM snap_blogroll WHERE peer_rss IS NOT NULL AND peer_rss != ''")->fetchColumn();
    $net_fetched  = $pdo->query("SELECT MAX(rss_last_fetched) FROM snap_blogroll WHERE rss_last_fetched IS NOT NULL")->fetchColumn();
} catch (PDOException $e) {
    $blogroll_enabled = false;
}

// --- CRON MANAGEMENT ---
// Handles the registration and removal of the RSS fetcher in the system crontab.
$cron_msg = '';
if ($cron_supported && isset($_POST['cron_action'])) {
    $script_path = realpath(__DIR__ . '/cron-rss-fetch.php');
    $cron_line   = "0 * * * * {$php_cli_path} {$script_path} >> /dev/null 2>&1";
    $tag         = '# snapsmack-rss-fetch';
    $full_entry  = "{$cron_line} {$tag}";

    exec('crontab -l 2>&1', $current_cron, $rc);
    $current_cron_str = ($rc === 0) ? implode("\n", $current_cron) : '';

    if ($_POST['cron_action'] === 'register') {
        if (strpos($current_cron_str, $tag) === false) {
            $new_cron = trim($current_cron_str) . "\n" . $full_entry . "\n";
            $tmp = tempnam(sys_get_temp_dir(), 'ssck');
            file_put_contents($tmp, $new_cron);
            exec("crontab {$tmp} 2>&1", $out, $ret);
            unlink($tmp);
            $cron_msg = ($ret === 0) ? 'RSS FETCH JOB REGISTERED. RUNS HOURLY.' : 'FAILED TO REGISTER: ' . implode(' ', $out);
        } else {
            $cron_msg = 'JOB ALREADY REGISTERED.';
        }
    } elseif ($_POST['cron_action'] === 'remove') {
        $cleaned = preg_replace('/.*' . preg_quote($tag, '/') . '.*\n?/', '', $current_cron_str);
        $tmp = tempnam(sys_get_temp_dir(), 'ssck');
        file_put_contents($tmp, trim($cleaned) . "\n");
        exec("crontab {$tmp} 2>&1", $out, $ret);
        unlink($tmp);
        $cron_msg = ($ret === 0) ? 'RSS FETCH JOB REMOVED.' : 'FAILED TO REMOVE: ' . implode(' ', $out);
    }
}

// Check current cron registration status for UI display.
$rss_job_registered = false;
if ($cron_supported) {
    exec('crontab -l 2>&1', $check_cron, $check_rc);
    $rss_job_registered = ($check_rc === 0 && strpos(implode("\n", $check_cron), '# snapsmack-rss-fetch') !== false);
}

$page_title = "System Dashboard";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>SYSTEM DASHBOARD</h2>

    <div class="post-layout-grid">
        <div class="box">
            <h3>QUICK STRIKE</h3>
            <div class="quick-strike-grid">
                <a href="smack-post.php"><button class="btn-smack">NEW POST</button></a>
                <a href="smack-backup.php"><button class="btn-smack btn-backup">BACKUP</button></a>
                <a href="smack-config.php"><button class="btn-smack btn-settings">SETTINGS</button></a>
                <a href="index.php" target="_blank"><button class="btn-smack btn-live">LIVE SITE</button></a>
            </div>
        </div>

        <div class="box">
            <h3>LATEST SMACK</h3>
            <?php if ($latest_img): ?>
                <div class="recent-item">
                    <div class="item-details">
                        <img src="<?php echo $display_thumb; ?>" 
                             class="archive-thumb" 
                             onerror="this.src='<?php echo $latest_img['img_file']; ?>';">
                        <div class="item-text">
                            <strong><?php echo htmlspecialchars($latest_img['img_title']); ?></strong>
                            <span class="dim"><?php echo date('M j, Y', strtotime($latest_img['img_date'])); ?></span>
                            <div class="item-actions mt-15" style="justify-content: flex-start;">
                                <a href="smack-edit.php?id=<?php echo $latest_img['id']; ?>" class="action-edit">EDIT ENTRY</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-grid mt-30">
        <div class="box">
            <h3>LIBRARY & TRANSMISSIONS</h3>
            <label>PHOTOGRAPHY</label>
            <div class="stat-row"><span class="label">PUBLISHED:</span><span class="value"><?php echo $count_pub; ?></span></div>
            <div class="stat-row"><span class="label">PENDING:</span><span class="value"><?php echo $count_pending; ?></span></div>
            <div class="stat-row"><span class="label">DRAFTS:</span><span class="value"><?php echo $count_drafts; ?></span></div>
            <label class="mt-20">SIGNALS (COMMENTS)</label>
            <div class="stat-row"><span class="label">INCOMING:</span><span class="value highlight-green"><?php echo $pending_count; ?></span></div>
            <div class="stat-row"><span class="label">BROADCASTING:</span><span class="value"><?php echo $live_count; ?></span></div>
            <a href="smack-comments.php"><button class="btn-smack master-update-btn mt-25">MANAGE SIGNALS</button></a>
        </div>

        <div class="box">
            <h3>ENVIRONMENT</h3>
            <label>SERVER SOFTWARE</label>
            <div class="read-only-display"><?php echo $server_soft; ?></div>
            <label class="mt-30">PHP VERSION</label>
            <div class="read-only-display"><?php echo $php_ver; ?></div>
            <label class="mt-30">MEMORY LIMIT</label>
            <div class="read-only-display"><?php echo $mem_limit; ?></div>
        </div>

        <div class="box">
            <h3>NETWORK STATUS</h3>
            <?php if ($blogroll_enabled): ?>
                <label>PEERS</label>
                <div class="stat-row"><span class="label">IN NETWORK:</span><span class="value"><?php echo $net_peers; ?></span></div>
                <div class="stat-row"><span class="label">CATEGORIES:</span><span class="value"><?php echo $net_cats; ?></span></div>
                <label class="mt-20">RSS FEEDS</label>
                <div class="stat-row"><span class="label">REGISTERED:</span><span class="value"><?php echo $net_rss; ?></span></div>
                <div class="stat-row"><span class="label">LAST FETCH:</span><span class="value"><?php echo $net_fetched ? date('M j, Y g:ia', strtotime($net_fetched)) : 'NEVER'; ?></span></div>
                <a href="smack-blogroll.php"><button class="btn-smack master-update-btn mt-25">MANAGE NETWORK</button></a>
            <?php else: ?>
                <p class="dim mt-20" style="font-size:0.8rem;">BLOGROLL NOT INSTALLED.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="post-layout-grid mt-30">
        <div class="box">
            <h3>SYSTEM VITALS</h3>
            <label>LOAD AVERAGE</label>
            <div class="read-only-display">
                <span class="highlight-green"><?php echo $load[0]; ?></span> / <?php echo $load[1]; ?> / <?php echo $load[2]; ?>
            </div>
            <label class="mt-30">DISK USAGE (<?php echo $disk_used_pct; ?>%)</label>
            <div class="read-only-display"><?php echo formatBytes($disk_total - $disk_free); ?> of <?php echo formatBytes($disk_total); ?></div>
            <div class="progress-container mt-20" style="display: block;">
                <div class="progress-bar" style="width: <?php echo $disk_used_pct; ?>%;"></div>
            </div>
        </div>

        <div class="box">
            <h3>CRON STATUS</h3>
            <?php if ($cron_msg): ?>
                <div class="alert alert-success mb-25">&gt; <?php echo htmlspecialchars($cron_msg); ?></div>
            <?php endif; ?>
            <?php if ($cron_supported): ?>
                <label>CRON ENGINE</label>
                <div class="read-only-display highlight-green">SUPPORTED</div>
                <label class="mt-30">PHP CLI PATH</label>
                <div class="read-only-display"><?php echo htmlspecialchars($php_cli_path ?: 'NOT FOUND'); ?></div>
                <label class="mt-30">RSS FEED FETCHER</label>
                <div class="read-only-display"><?php echo $rss_job_registered ? 'REGISTERED — RUNS HOURLY' : 'NOT REGISTERED'; ?></div>
                <form method="POST" class="mt-25">
                    <div class="action-grid-dual">
                        <button type="submit" name="cron_action" value="register" class="btn-smack" <?php echo $rss_job_registered ? 'disabled' : ''; ?>>REGISTER RSS JOB</button>
                        <button type="submit" name="cron_action" value="remove" class="btn-smack" <?php echo !$rss_job_registered ? 'disabled' : ''; ?>>REMOVE RSS JOB</button>
                    </div>
                </form>
            <?php else: ?>
                <label>CRON ENGINE</label>
                <div class="read-only-display">NOT SUPPORTED ON THIS HOST</div>
                <p class="dim mt-20" style="font-size:0.8rem;">RSS last-updated features are unavailable. Cron access is required.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>