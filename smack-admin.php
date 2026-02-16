<?php
/**
 * SnapSmack - Master Dashboard
 * Version: 3.7 - Integrated Trinity + Appearance Controller Recovery
 */

require_once 'core/auth.php';

// --- 1. ADMIN THEME DISCOVERY (Recovered from Header) ---
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
    'name' => $active_admin_slug, 
    'description' => 'Theme manifest missing.', 
    'version' => '1.0', 
    'author' => 'System'
];

// --- 2. DATA ACQUISITION (Feb 7th Golden Master) ---
$count_pub     = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND img_date <= NOW()")->fetchColumn();
$count_pending = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND img_date > NOW()")->fetchColumn();
$count_drafts  = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='draft'")->fetchColumn();

$pending_count = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 0")->fetchColumn();
$live_count    = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 1")->fetchColumn();

$latest_img = $pdo->query("SELECT * FROM snap_images ORDER BY img_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

$load = sys_getloadavg(); 
$disk_free  = disk_free_space("/");
$disk_total = disk_total_space("/");
$disk_used_pct = ($disk_total > 0) ? round((($disk_total - $disk_free) / $disk_total) * 100, 1) : 0;

if (!function_exists('formatBytes')) {
    function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow   = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

$display_thumb = "";
if ($latest_img) {
    $path_parts = pathinfo($latest_img['img_file']);
    $display_thumb = $path_parts['dirname'] . '/thumbs/t_' . $path_parts['basename'];
}

$page_title = "SYSTEM DASHBOARD";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>SYSTEM DASHBOARD</h2>

    <div class="box">
        <div class="skin-meta-wrap">
            <h3 style="border: none; margin-bottom: 5px;"><?php echo strtoupper($current_admin_meta['name']); ?> <span class="v-tag" style="font-size: 0.7rem; opacity: 0.5;">v<?php echo $current_admin_meta['version']; ?></span></h3>
            <p class="maint-desc" style="font-size: 0.9rem; margin-bottom: 15px;"><?php echo $current_admin_meta['description']; ?></p>
            <div class="credits-admin">
                BY <?php echo strtoupper($current_admin_meta['author']); ?> | 
                <a href="mailto:<?php echo $current_admin_meta['support']; ?>" style="color: #39FF14; text-decoration: none;">SUPPORT</a>
            </div>
        </div>
    </div>

    <div class="dash-grid">
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
                <div class="recent-item" style="border: none; padding: 0;">
                    <div class="item-details">
                        <img src="<?php echo $display_thumb; ?>" 
                             class="archive-thumb" 
                             style="width: 150px; height: 150px;"
                             onerror="this.src='<?php echo $latest_img['img_file']; ?>';">
                        <div class="item-text" style="padding-left: 20px;">
                            <strong style="color: #39FF14; font-size: 1.1rem;"><?php echo htmlspecialchars($latest_img['img_title']); ?></strong>
                            <span class="dim"><?php echo date('M j, Y', strtotime($latest_img['img_date'])); ?></span>
                            <div style="margin-top: 15px;">
                                <a href="smack-edit.php?id=<?php echo $latest_img['id']; ?>" class="action-edit">[ EDIT ENTRY ]</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-grid" style="margin-top: 30px;">
        <div class="box">
            <h3>LIBRARY & TRANSMISSIONS</h3>
            <label>PHOTOGRAPHY</label>
            <div class="stat-row"><span class="label">PUBLISHED: </span><span class="value"><?php echo $count_pub; ?></span></div>
            <div class="stat-row"><span class="label">PENDING: </span><span class="value"><?php echo $count_pending; ?></span></div>
            <div class="stat-row"><span class="label">DRAFTS: </span><span class="value"><?php echo $count_drafts; ?></span></div>
            
            <label style="margin-top: 15px;">SIGNALS (COMMENTS)</label>
            <div class="stat-row"><span class="label">INCOMING: </span><span class="value highlight-green"><?php echo $pending_count; ?></span></div>
            <div class="stat-row"><span class="label">BROADCASTING: </span><span class="value"><?php echo $live_count; ?></span></div>
            
            <div style="margin-top: 15px;">
                <a href="smack-comments.php"><button class="btn-smack btn-block">MANAGE SIGNALS</button></a>
            </div>
        </div>

        <div class="box">
            <h3>ENVIRONMENT</h3>
            <label>SERVER SOFTWARE</label>
            <div class="read-only-display"><?php echo $server_soft; ?></div>
            <br>
            <label>PHP VERSION</label>
            <div class="read-only-display"><?php echo $php_ver; ?></div>
            <br>
            <label>MEMORY LIMIT</label>
            <div class="read-only-display"><?php echo $mem_limit; ?></div>
        </div>

        <div class="box">
            <h3>SYSTEM VITALS</h3>
            <label>LOAD AVERAGE</label>
            <div class="read-only-display">
                <span style="color: #39FF14;"><?php echo $load[0]; ?></span> 
                <span style="color: #444;">/ <?php echo $load[1]; ?> / <?php echo $load[2]; ?></span>
            </div>
            <br>
            <label>DISK USAGE (<?php echo $disk_used_pct; ?>%)</label>
            <div class="read-only-display">
                <?php echo formatBytes($disk_total - $disk_free); ?> of <?php echo formatBytes($disk_total); ?>
            </div>
            <div class="progress-container" style="display: block; margin-top: 15px;">
                <div class="progress-bar" style="width: <?php echo $disk_used_pct; ?>%;"></div>
            </div>
        </div>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>