<?php
/**
 * SnapSmack - Master Dashboard
 * Version: 3.1 - Trinity Restored (SVS Compliant)
 */

require_once 'core/auth.php';

// --- 1. ADMIN THEME DISCOVERY ---
$active_theme_slug = $settings['active_theme'] ?? 'midnight-lime';
$theme_base = "assets/adminthemes/{$active_theme_slug}";
$manifest_path = "{$theme_base}/{$active_theme_slug}-manifest.php";
$theme_meta = file_exists($manifest_path) ? include $manifest_path : ['name' => 'Midnight Lime', 'version' => '1.0', 'author' => 'Sean McCormick'];

// --- 2. DATA ACQUISITION ---
$count_pub      = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND img_date <= NOW()")->fetchColumn();
$count_pending  = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='published' AND img_date > NOW()")->fetchColumn();
$count_drafts   = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='draft'")->fetchColumn();
$pending_comments = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 0")->fetchColumn();
$live_comments    = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 1")->fetchColumn();
$latest_img = $pdo->query("SELECT * FROM snap_images ORDER BY img_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// System Vitals & Environment
$load = sys_getloadavg(); 
$disk_free  = disk_free_space("/");
$disk_total = disk_total_space("/");
$disk_used_pct = ($disk_total > 0) ? round((($disk_total - $disk_free) / $disk_total) * 100, 1) : 0;
$mem_limit = ini_get('memory_limit');
$php_ver = phpversion();
$server_soft = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';

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

    <div class="box appearance-controller">
        <div class="skin-meta-wrap">
            <h4 class="skin-active-name"><?php echo strtoupper($theme_meta['name']); ?> <span class="v-tag">v<?php echo $theme_meta['version']; ?></span></h4>
            <p class="skin-desc-text"><?php echo $theme_meta['description'] ?? ''; ?></p>
            <div class="skin-author-line">
                BY <?php echo strtoupper($theme_meta['author']); ?> | 
                <a href="mailto:sean@iswa.ca" class="support-link">SUPPORT</a>
            </div>
        </div>
        <div class="skin-selector-wrap">
             <div class="stat-mini">
                <label>INTERFACE SLUG</label>
                <div class="stat-val"><?php echo strtoupper($active_theme_slug); ?></div>
            </div>
        </div>
    </div>

    <div class="dash-grid">
        <div class="box">
            <h3>QUICK STRIKE</h3>
            <div class="quick-strike-grid">
                <a href="smack-post.php" class="btn-link"><button class="btn-smack">NEW POST</button></a>
                <a href="smack-backup.php" class="btn-link"><button class="btn-secondary">BACKUP</button></a>
                <a href="smack-config.php" class="btn-link"><button class="btn-ghost">SETTINGS</button></a>
                <a href="../index.php" target="_blank" class="btn-link"><button class="btn-ghost">LIVE SITE</button></a>
            </div>
        </div>

        <div class="box">
            <h3>LATEST SMACK</h3>
            <?php if ($latest_img): ?>
                <div class="item-details" style="display: flex; gap: 15px;">
                    <img src="<?php echo $display_thumb; ?>" class="table-thumb" style="width: 80px;" onerror="this.src='<?php echo $latest_img['img_file']; ?>';">
                    <div class="item-text">
                        <strong><?php echo htmlspecialchars($latest_img['img_title']); ?></strong><br>
                        <span class="dim"><?php echo date('M j, Y', strtotime($latest_img['img_date'])); ?></span><br><br>
                        <a href="smack-edit.php?id=<?php echo $latest_img['id']; ?>" class="action-edit">[ EDIT ENTRY ]</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="widget-row">
        <div class="box">
            <h3>LIBRARY & SIGNALS</h3>
            <div class="stat-row"><span class="label">PUBLISHED:</span><span class="value"><?php echo $count_pub; ?></span></div>
            <div class="stat-row"><span class="label">PENDING:</span><span class="value"><?php echo $count_pending; ?></span></div>
            <div class="stat-row"><span class="label">DRAFTS:</span><span class="value"><?php echo $count_drafts; ?></span></div>
            <br>
            <label>COMMENTS</label>
            <div class="stat-row"><span class="label">INCOMING:</span><span class="value highlight-green"><?php echo $pending_comments; ?></span></div>
            <div class="stat-row"><span class="label">BROADCAST:</span><span class="value"><?php echo $live_comments; ?></span></div>
            <br>
            <a href="smack-comments.php" class="btn-link"><button class="btn-smack btn-block">MANAGE SIGNALS</button></a>
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
            <div class="read-only-display" style="color: var(--neon-green);">
                <?php echo round($load[0], 2); ?> / <?php echo round($load[1], 2); ?> / <?php echo round($load[2], 2); ?>
            </div>
            <br>
            <label>DISK USAGE (<?php echo $disk_used_pct; ?>%)</label>
            <div class="read-only-display"><?php echo round($disk_total / (1024**4), 2) - round($disk_free / (1024**4), 2); ?> TB of <?php echo round($disk_total / (1024**4), 1); ?> TB</div>
            <div class="progress-bar-wrap">
                <div class="progress-fill" style="width: <?php echo $disk_used_pct; ?>%;"></div>
            </div>
        </div>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>