<?php
/**
 * SnapSmack - Transmission Control (Admin)
 * Version: 2.3 - Visual Restoration & Logic Sync
 * MASTER DIRECTIVE: Old structure restored. New logic integrated. No inline CSS.
 */
require_once 'core/auth.php';

// 1. Fetch Global Settings for Status Bar
$s_rows = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$global_comments_active = (($s_rows['global_comments_enabled'] ?? '1') == '1');

// 2. Handle Actions (Approve / Delete)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($_GET['action'] == 'approve') {
        $pdo->prepare("UPDATE snap_comments SET is_approved = 1 WHERE id = ?")->execute([$id]);
        $msg = "Signal authorized. Broadcasting live.";
    } elseif ($_GET['action'] == 'delete') {
        $pdo->prepare("DELETE FROM snap_comments WHERE id = ?")->execute([$id]);
        $msg = "Signal terminated.";
    }
}

// 3. Logic for Search & Pagination
$view_mode = $_GET['view'] ?? 'pending';
$search = trim($_GET['s'] ?? '');
$search_query = $search ? " AND (c.comment_author LIKE ? OR c.comment_text LIKE ? OR c.comment_email LIKE ?)" : "";
$params = $search ? ["%$search%", "%$search%", "%$search%"] : [];
$per_page = 20;
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$offset = ($page - 1) * $per_page;

$pending_count = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 0")->fetchColumn();
$live_count = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 1")->fetchColumn();

$count_sql = "SELECT COUNT(*) FROM snap_comments c WHERE is_approved = " . ($view_mode == 'live' ? '1' : '0') . $search_query;
$stmt_count = $pdo->prepare($count_sql);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Fetching comments + image data + post-specific comment status
$sql = "SELECT c.*, i.img_title, i.img_file, i.allow_comments as post_comments_active
        FROM snap_comments c 
        LEFT JOIN snap_images i ON c.img_id = i.id 
        WHERE c.is_approved = " . ($view_mode == 'live' ? '1' : '0') . $search_query . " 
        ORDER BY c.comment_date DESC LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$comments = $stmt->fetchAll();

$page_title = "TRANSMISSION CONTROL";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="title-bar-flex">
        <h2>TRANSMISSION CONTROL</h2>
        <div class="status-pill <?php echo $global_comments_active ? 'status-online' : 'status-offline'; ?>">
            GLOBAL SYSTEM: <?php echo $global_comments_active ? 'ONLINE' : 'OFFLINE'; ?>
        </div>
    </div>

    <div class="transmission-header">
        <form method="GET" class="transmission-search-group">
            <input type="hidden" name="view" value="<?php echo $view_mode; ?>">
            <input type="text" name="s" placeholder="SCAN FREQUENCIES..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn-smack">SCAN</button>
        </form>
        
        <div class="transmission-nav-group">
            <a href="?view=pending" class="btn-clear <?php if($view_mode == 'pending') echo 'active'; ?>">INCOMING (<?php echo $pending_count; ?>)</a>
            <a href="?view=live" class="btn-clear <?php if($view_mode == 'live') echo 'active'; ?>">BROADCASTING (<?php echo $live_count; ?>)</a>
        </div>
    </div>

    <?php if(isset($msg)) echo "<div class='msg'>> $msg</div>"; ?>

    <div class="box">
        <h3><?php echo ($view_mode == 'pending' ? 'AWAITING AUTHORIZATION' : 'LIVE TRANSMISSIONS'); ?></h3>
        
        <?php if ($comments): ?>
            <div class="recent-list">
                <?php foreach ($comments as $c): ?>
                    <div class="recent-item">
                        <div class="item-details">
                            <img src="/<?php echo htmlspecialchars($c['img_file']); ?>" class="archive-thumb">
                            <div class="item-text">
                                <div class="signal-sender">
                                    <?php echo htmlspecialchars($c['comment_author']); ?> 
                                    <span>[<?php echo htmlspecialchars($c['comment_email']); ?>]</span>
                                </div>
                                
                                <div class="signal-body"><?php echo nl2br(htmlspecialchars($c['comment_text'])); ?></div>
                                
                                <div class="signal-meta">
                                    ON: <?php echo htmlspecialchars($c['img_title'] ?? 'UNKNOWN SOURCE'); ?> 
                                    
                                    <?php if (isset($c['post_comments_active']) && $c['post_comments_active'] == 0): ?>
                                        <span class="alert-text">[FREQUENCY MUTED]</span>
                                    <?php endif; ?>
                                    
                                    | IP: <?php echo htmlspecialchars($c['comment_ip'] ?? '0.0.0.0'); ?> 
                                    | <?php echo $c['comment_date']; ?>
                                </div>
                                
                                <div class="item-actions">
                                    <?php if ($view_mode == 'pending'): ?>
                                        <a href="?action=approve&id=<?php echo $c['id']; ?>&view=<?php echo $view_mode; ?>&s=<?php echo urlencode($search); ?>&p=<?php echo $page; ?>" class="action-authorize">AUTHORIZE</a>
                                    <?php endif; ?>
                                    <a href="?action=delete&id=<?php echo $c['id']; ?>&view=<?php echo $view_mode; ?>&s=<?php echo urlencode($search); ?>&p=<?php echo $page; ?>" class="action-delete" onclick="return confirm('Terminate signal?');">TERMINATE</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?view=<?php echo $view_mode; ?>&s=<?php echo urlencode($search); ?>&p=<?php echo $i; ?>" class="<?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <div class="read-only-display text-center" style="border:none;">NO SIGNALS DETECTED.</div>
        <?php endif; ?>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>