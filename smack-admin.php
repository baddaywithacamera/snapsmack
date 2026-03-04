<?php
/**
 * SNAPSMACK - System dashboard and administrative hub
 * Alpha v0.7
 *
 * Displays content statistics, system vitals, and provides centralized access
 * to administrative tools. Manages cron job registration for RSS fetching.
 */

require_once 'core/auth.php';

// --- EARLY CRON DETECTION ---
// Must run before any POST handlers that depend on $cron_supported.
// admin-header.php also sets these, but it loads after the handlers.
if (!isset($cron_supported)) {
    $cron_supported = false;
    $php_cli_path   = '';
    if (function_exists('exec')) {
        exec('crontab -l 2>&1', $_ct_out, $_ct_code);
        $cron_supported = ($_ct_code === 0);
        $php_cli_path   = trim(exec('which php 2>&1'));
        if (strpos($php_cli_path, '/') !== 0) $php_cli_path = '';
    }
}

// --- UPDATE NOTIFICATION CHECK ---
// Load cached update check result for dashboard notifications.
// If no cached result exists OR cache is older than 24 hours, trigger a live
// check (lightweight fallback when cron is not configured).
$_update_notifications = null;
$_update_total = 0;
try {
    $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'update_check_result'");
    $stmt->execute();
    $_update_json = $stmt->fetchColumn();
    if ($_update_json) {
        $_update_notifications = json_decode($_update_json, true);
        $_update_total = $_update_notifications['total_notifications'] ?? 0;
    }

    // Check cache age — trigger live check if stale (>24h) and updater is available
    $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'last_update_check'");
    $stmt->execute();
    $_last_check = $stmt->fetchColumn();
    $_cache_stale = (!$_last_check || (time() - strtotime($_last_check)) > 86400);

    if ($_cache_stale && file_exists('core/updater.php')) {
        require_once 'core/updater.php';
        $_release = updater_fetch_release_info();
        $_skin_info = updater_check_skin_registry($pdo);
        $_core_status = updater_check_status(SNAPSMACK_VERSION_SHORT ?? '0.0', $_release);

        $_core_update = null;
        if ($_core_status === 'update_available') {
            $_core_update = [
                'version'      => $_release['version'] ?? '',
                'version_full' => $_release['version_full'] ?? '',
            ];
        }

        $_update_notifications = [
            'checked_at'          => date('c'),
            'core_status'         => $_core_status,
            'core_update'         => $_core_update,
            'new_skins'           => $_skin_info['new_skins'],
            'updated_skins'       => $_skin_info['updated_skins'],
            'skin_notifications'  => $_skin_info['total_notifications'],
            'total_notifications' => ($_core_update ? 1 : 0) + $_skin_info['total_notifications'],
        ];
        $_update_total = $_update_notifications['total_notifications'];

        // Cache the result
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('update_check_result', ?)
                               ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)");
        $stmt->execute([json_encode($_update_notifications, JSON_UNESCAPED_SLASHES)]);

        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('last_update_check', ?)
                               ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)");
        $stmt->execute([date('Y-m-d H:i:s')]);
    }
} catch (PDOException $e) {
    // Silent — dashboard should never break because of update checks
}

// --- ADMIN THEME DISCOVERY ---
// Loads available admin themes from the theme directory to allow users to customize
// the admin panel interface.
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

// --- CONTENT STATISTICS ---
// Tallies published, scheduled, and draft posts to show content status at a glance.
$count_pub     = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND img_date <= NOW()")->fetchColumn();
$count_pending = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND img_date > NOW()")->fetchColumn();
$count_drafts  = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='draft'")->fetchColumn();

// Tallies pending and approved comments for the moderation queue.
$pending_count = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 0")->fetchColumn();
$live_count    = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 1")->fetchColumn();

// Fetches the most recent post for the dashboard preview.
$latest_img = $pdo->query("SELECT * FROM snap_images ORDER BY img_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// --- SYSTEM ENVIRONMENT ---
// Collects server metrics: PHP version, memory limits, CPU load, and disk usage.
$server_soft = $_SERVER['SERVER_SOFTWARE'] ?? 'N/A';
$php_ver     = phpversion();
$mem_limit   = ini_get('memory_limit');
$load        = sys_getloadavg() ?: [0,0,0];
$disk_free   = disk_free_space("/") ?: 0;
$disk_total  = disk_total_space("/") ?: 1;
$disk_used_pct = ($disk_total > 0) ? round((($disk_total - $disk_free) / $disk_total) * 100, 1) : 0;

if (!function_exists('formatBytes')) {
    // Converts raw byte counts to human-readable format (KB, MB, GB, etc.)
    function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow   = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

// Builds the thumbnail path for the latest post preview image.
$display_thumb = "";
if ($latest_img) {
    $path_parts = pathinfo($latest_img['img_file']);
    $display_thumb = $path_parts['dirname'] . '/thumbs/t_' . $path_parts['basename'];
}

// --- BLOGROLL NETWORK STATS ---
// Loads peer network statistics; disabled if the blogroll module is not available.
$blogroll_enabled = true;
try {
    $net_peers    = $pdo->query("SELECT COUNT(*) FROM snap_blogroll")->fetchColumn();
    $net_cats     = $pdo->query("SELECT COUNT(*) FROM snap_blogroll_cats")->fetchColumn();
    $net_rss      = $pdo->query("SELECT COUNT(*) FROM snap_blogroll WHERE peer_rss IS NOT NULL AND peer_rss != ''")->fetchColumn();
    $net_fetched  = $pdo->query("SELECT MAX(rss_last_fetched) FROM snap_blogroll WHERE rss_last_fetched IS NOT NULL")->fetchColumn();
} catch (PDOException $e) {
    $blogroll_enabled = false;
}

// --- CRON JOB MANAGEMENT ---
// Allows the user to register or remove the RSS fetcher from the system crontab.
// The job runs hourly to automatically fetch updates from peer feeds.
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

// Checks whether the RSS fetcher job is currently registered in the crontab.
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

    <?php if ($_update_total > 0): ?>
    <div class="alert-update">
        <div>
            <?php
            $_notices = [];
            if (!empty($_update_notifications['core_update'])) {
                $_notices[] = 'Core update available: v' . htmlspecialchars($_update_notifications['core_update']['version']);
            }
            $_new_count = count($_update_notifications['new_skins'] ?? []);
            $_upd_count = count($_update_notifications['updated_skins'] ?? []);
            if ($_new_count > 0) $_notices[] = "{$_new_count} new skin" . ($_new_count > 1 ? 's' : '') . " available";
            if ($_upd_count > 0) $_notices[] = "{$_upd_count} skin update" . ($_upd_count > 1 ? 's' : '') . " available";
            echo strtoupper(implode(' — ', $_notices));
            ?>
        </div>
        <a href="smack-update.php" class="btn-smack">VIEW UPDATES</a>
    </div>
    <?php endif; ?>

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
                            <div class="item-actions mt-15 item-actions-left">
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
                <p class="dim mt-20 text-sm">BLOGROLL NOT INSTALLED.</p>
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
                <p class="dim mt-20 text-sm">RSS last-updated features are unavailable. Cron access is required.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>