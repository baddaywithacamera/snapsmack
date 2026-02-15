<?php
/**
 * SnapSmack - Master Dashboard
 * Version: 2.2 - External CSS Refactor
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'core/auth.php';

// --- LOGOUT HANDLER ---
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// --- DATA ACQUISITION ---
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

    <?php if (isset($_GET['err']) && $_GET['err'] == 'unauthorized'): ?>
        <div class="alert alert-error">> ACCESS DENIED: Administrator privileges required for that section.</div>
    <?php endif; ?>

    <div class="dash-grid">
        <div class="box">
            <h3>QUICK STRIKE</h3>
            <div class="quick-strike-grid">
                <a href="smack-post.php"><button>NEW POST</button></a>
                <a href="smack-backup.php"><button class="btn-backup">BACKUP</button></a>
                <a href="smack-config.php"><button class="btn-settings">SETTINGS</button></a>
                <a href="index.php" target="_blank"><button class="btn-live">LIVE SITE</button></a>
            </div>
        </div>

        <div class="box">
            <h3>LATEST SMACK</h3>
            <?php if ($latest_img): ?>
                <div class="item-details">
                    <img src="<?php echo $display_thumb; ?>" 
                         class="archive-thumb latest-item-thumb"
                         onerror="this.src='<?php echo $latest_img['img_file']; ?>';">
                    <div class="item-text">
                        <strong class="highlight-green"><?php echo htmlspecialchars($latest_img['img_title']); ?></strong>
                        <span class="dim"><?php echo date('M j, Y', strtotime($latest_img['img_date'])); ?></span>
                        <div class="mt-20">
                            <a href="smack-edit.php?id=<?php echo $latest_img['id']; ?>" class="action-edit">[ EDIT ENTRY ]</a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <p class="dim">No photos uploaded yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="dash-grid">
        <div class="box">
            <h3>LIBRARY & TRANSMISSIONS</h3>
            <div class="console-rows">
                <label>PHOTOGRAPHY</label>
                <div class="stat-row"><span class="label">PUBLISHED: </span><span class="value"><?php echo $count_pub; ?></span></div>
                <div class="stat-row"><span class="label">PENDING: </span><span class="value"><?php echo $count_pending; ?></span></div>
                <div class="stat-row"><span class="label">DRAFTS: </span><span class="value"><?php echo $count_drafts; ?></span></div>
                
                <label class="mt-30">SIGNALS (COMMENTS)</label>
                <div class="stat-row"><span class="label">INCOMING: </span><span class="value highlight-green"><?php echo $pending_count; ?></span></div>
                <div class="stat-row"><span class="label">BROADCASTING: </span><span class="value"><?php echo $live_count; ?></span></div>
                
                <div class="mt-30">
                    <a href="smack-comments.php"><button class="btn-smack">MANAGE SIGNALS</button></a>
                </div>
            </div>
        </div>

        <div class="box">
            <h3>ENVIRONMENT</h3>
            <label>Server Software</label>
            <div class="signal-body font-mono"><?php echo $_SERVER['SERVER_SOFTWARE']; ?></div>
            
            <label class="mt-20">PHP Version</label>
            <div class="signal-body font-mono"><?php echo phpversion(); ?></div>

            <label class="mt-20">Memory Limit</label>
            <div class="signal-body font-mono"><?php echo ini_get('memory_limit'); ?></div>
        </div>

        <div class="box">
            <h3>SYSTEM VITALS</h3>
            <label>Load Average</label>
            <div class="signal-body font-mono">
                <span class="highlight-green"><?php echo $load[0]; ?></span> 
                <span class="dim">/ <?php echo $load[1]; ?> / <?php echo $load[2]; ?></span>
            </div>

            <label class="mt-20">Disk Usage (<?php echo $disk_used_pct; ?>%)</label>
            <div class="signal-body font-mono">
                <?php echo formatBytes($disk_total - $disk_free); ?> <span class="dim">of</span> <?php echo formatBytes($disk_total); ?>
            </div>
            
            <div class="progress-container show">
                <div class="progress-bar" style="width: <?php echo $disk_used_pct; ?>%;"></div>
            </div>
        </div>
    </div>
</div>

<?php 
include 'core/admin-footer.php'; 
?>