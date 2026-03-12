<?php
/**
 * SNAPSMACK - Comment moderation interface
 * Alpha v0.7.3
 *
 * Manages review, approval, and deletion of visitor comments.
 * Covers both legacy anonymous comments (snap_comments) and community
 * account comments (snap_community_comments). Tab selector switches
 * between the two systems. Search and pagination apply within each tab.
 */

require_once 'core/auth.php';

// --- GLOBAL COMMENT SETTINGS ---
// Determines if the comment system is enabled across the site.
$s_rows = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$global_comments_active = (($s_rows['global_comments_enabled'] ?? '1') == '1');

// --- SYSTEM TAB ---
// 'legacy' = snap_comments (anonymous, moderation queue)
// 'community' = snap_community_comments (account-required, visible/hidden)
$system = $_GET['system'] ?? 'community';

// --- MODERATION ACTIONS ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    if ($system === 'community') {
        if ($_GET['action'] === 'hide') {
            $pdo->prepare("UPDATE snap_community_comments SET status = 'hidden' WHERE id = ?")->execute([$id]);
            $msg = "Comment hidden.";
        } elseif ($_GET['action'] === 'restore') {
            $pdo->prepare("UPDATE snap_community_comments SET status = 'visible' WHERE id = ?")->execute([$id]);
            $msg = "Comment restored.";
        } elseif ($_GET['action'] === 'delete') {
            $pdo->prepare("UPDATE snap_community_comments SET status = 'deleted' WHERE id = ?")->execute([$id]);
            $msg = "Comment deleted.";
        }
    } else {
        // Legacy system
        if ($_GET['action'] == 'approve') {
            $pdo->prepare("UPDATE snap_comments SET is_approved = 1 WHERE id = ?")->execute([$id]);
            $msg = "Signal authorized. Broadcasting live.";
        } elseif ($_GET['action'] == 'delete') {
            $pdo->prepare("DELETE FROM snap_comments WHERE id = ?")->execute([$id]);
            $msg = "Signal terminated.";
        }
    }
}

// --- SEARCH AND PAGINATION ---
$view_mode = $_GET['view'] ?? ($system === 'community' ? 'visible' : 'pending');
$search = trim($_GET['s'] ?? '');
$search_query = $search ? " AND (c.comment_author LIKE ? OR c.comment_text LIKE ? OR c.comment_email LIKE ?)" : "";
$params = $search ? ["%$search%", "%$search%", "%$search%"] : [];

$per_page = 20;
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$offset = ($page - 1) * $per_page;

// Load comment counts for UI tabs.
$pending_count = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 0")->fetchColumn();
$live_count = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 1")->fetchColumn();

// Count total records matching current filters.
$count_sql = "SELECT COUNT(*) FROM snap_comments c WHERE is_approved = " . ($view_mode == 'live' ? '1' : '0') . $search_query;
$stmt_count = $pdo->prepare($count_sql);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// --- DATA RETRIEVAL ---
// Fetch comments with parent post metadata for display context.
$sql = "SELECT c.*, i.img_title, i.img_file, i.allow_comments as post_comments_active
        FROM snap_comments c
        LEFT JOIN snap_images i ON c.img_id = i.id
        WHERE c.is_approved = " . ($view_mode == 'live' ? '1' : '0') . $search_query . "
        ORDER BY c.comment_date DESC LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$comments = $stmt->fetchAll();

$page_title = "Signal Control";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>SIGNAL CONTROL</h2>
        <div class="header-actions">
            <div class="status-pill <?php echo $global_comments_active ? 'status-online' : 'status-offline'; ?>">
                GLOBAL SYSTEM: <?php echo $global_comments_active ? 'ONLINE' : 'OFFLINE'; ?>
            </div>
        </div>
    </div>

    <div class="signal-control-header">
        <form method="GET" class="signal-search-group">
            <input type="hidden" name="view" value="<?php echo $view_mode; ?>">
            <input type="text" name="s" placeholder="SCAN FREQUENCIES..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn-smack">SCAN</button>
        </form>
        
        <div class="signal-nav-group">
            <?php if ($system === 'community'): ?>
                <a href="?system=community&view=visible" class="btn-clear <?php echo $view_mode === 'visible' ? 'active' : ''; ?>">VISIBLE (<?php echo $live_count; ?>)</a>
                <a href="?system=community&view=hidden"  class="btn-clear <?php echo $view_mode === 'hidden'  ? 'active' : ''; ?>">HIDDEN (<?php echo $pending_count; ?>)</a>
            <?php else: ?>
                <a href="?system=legacy&view=pending" class="btn-clear <?php echo $view_mode === 'pending' ? 'active' : ''; ?>">INCOMING (<?php echo $pending_count; ?>)</a>
                <a href="?system=legacy&view=live"    class="btn-clear <?php echo $view_mode === 'live'    ? 'active' : ''; ?>">BROADCASTING (<?php echo $live_count; ?>)</a>
            <?php endif; ?>
            <span class="sep">|</span>
            <a href="?system=community" class="btn-clear <?php echo $system === 'community' ? 'active' : ''; ?>">COMMUNITY</a>
            <a href="?system=legacy"    class="btn-clear <?php echo $system === 'legacy'    ? 'active' : ''; ?>">LEGACY</a>
        </div>
    </div>

    <?php if(isset($msg)) echo "<div class='msg'>> $msg</div>"; ?>

    <div class="box">
        <h3><?php echo ($view_mode == 'pending' ? 'AWAITING AUTHORIZATION' : 'LIVE SIGNALS'); ?></h3>
        
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
            <div class="read-only-display text-center no-border">NO SIGNALS DETECTED.</div>
        <?php endif; ?>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>