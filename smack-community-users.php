<?php
/**
 * SNAPSMACK - Community User Management
 * Alpha v0.7.3a
 *
 * Admin panel for viewing, suspending, and removing community (visitor)
 * accounts. Shows signup date, last seen, comment count, like count,
 * and verification status. Supports search and status filter.
 */

require_once 'core/auth.php';

// --- ACTIONS ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $uid = (int)$_GET['id'];
    switch ($_GET['action']) {
        case 'suspend':
            $pdo->prepare("UPDATE snap_community_users SET status = 'suspended' WHERE id = ?")
                ->execute([$uid]);
            $msg = "Account suspended.";
            break;
        case 'unsuspend':
            $pdo->prepare("UPDATE snap_community_users SET status = 'active' WHERE id = ?")
                ->execute([$uid]);
            $msg = "Account reinstated.";
            break;
        case 'delete':
            // Cascade: delete comments, likes, reactions, sessions, tokens
            foreach (['snap_community_comments', 'snap_likes', 'snap_reactions',
                      'snap_community_sessions', 'snap_community_tokens'] as $table) {
                $pdo->prepare("DELETE FROM {$table} WHERE user_id = ?")->execute([$uid]);
            }
            $pdo->prepare("DELETE FROM snap_community_users WHERE id = ?")->execute([$uid]);
            $msg = "Account and associated data deleted.";
            break;
    }
}

// --- FILTERS ---
$status_filter = $_GET['status'] ?? 'all';
$search        = trim($_GET['s'] ?? '');
$per_page      = 25;
$page          = max(1, (int)($_GET['p'] ?? 1));
$offset        = ($page - 1) * $per_page;

$where_parts = [];
$params      = [];

if ($status_filter !== 'all') {
    $where_parts[] = "u.status = ?";
    $params[]      = $status_filter;
}
if ($search) {
    $where_parts[] = "(u.username LIKE ? OR u.email LIKE ? OR u.display_name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$where = $where_parts ? "WHERE " . implode(" AND ", $where_parts) : "";

// --- COUNTS FOR TABS ---
$total_count      = $pdo->query("SELECT COUNT(*) FROM snap_community_users")->fetchColumn();
$active_count     = $pdo->query("SELECT COUNT(*) FROM snap_community_users WHERE status = 'active'")->fetchColumn();
$unverified_count = $pdo->query("SELECT COUNT(*) FROM snap_community_users WHERE status = 'unverified'")->fetchColumn();
$suspended_count  = $pdo->query("SELECT COUNT(*) FROM snap_community_users WHERE status = 'suspended'")->fetchColumn();

// --- PAGINATED DATA ---
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_community_users u $where");
$count_stmt->execute($params);
$total_filtered = (int)$count_stmt->fetchColumn();
$total_pages    = max(1, (int)ceil($total_filtered / $per_page));

$data_stmt = $pdo->prepare("
    SELECT u.id, u.username, u.display_name, u.email,
           u.status, u.email_verified, u.created_at, u.last_seen_at,
           (SELECT COUNT(*) FROM snap_community_comments c WHERE c.user_id = u.id AND c.status = 'visible') AS comment_count,
           (SELECT COUNT(*) FROM snap_likes l WHERE l.user_id = u.id) AS like_count
    FROM snap_community_users u
    $where
    ORDER BY u.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$data_stmt->execute($params);
$users = $data_stmt->fetchAll();

$page_title = "Community Users";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>COMMUNITY USERS</h2>
        <div class="header-actions">
            <div class="status-pill status-online">
                TOTAL: <?php echo (int)$total_count; ?>
            </div>
        </div>
    </div>

    <div class="signal-control-header">
        <form method="GET" class="signal-search-group">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
            <input type="text" name="s" placeholder="SEARCH USERS..."
                   value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn-smack">SEARCH</button>
        </form>

        <div class="signal-nav-group">
            <a href="?status=all" class="btn-clear <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                ALL (<?php echo (int)$total_count; ?>)
            </a>
            <a href="?status=active" class="btn-clear <?php echo $status_filter === 'active' ? 'active' : ''; ?>">
                ACTIVE (<?php echo (int)$active_count; ?>)
            </a>
            <a href="?status=unverified" class="btn-clear <?php echo $status_filter === 'unverified' ? 'active' : ''; ?>">
                UNVERIFIED (<?php echo (int)$unverified_count; ?>)
            </a>
            <a href="?status=suspended" class="btn-clear <?php echo $status_filter === 'suspended' ? 'active' : ''; ?>">
                SUSPENDED (<?php echo (int)$suspended_count; ?>)
            </a>
        </div>
    </div>

    <?php if (isset($msg)): ?>
        <div class="msg">> <?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <div class="box">
        <h3>
            <?php echo strtoupper($status_filter === 'all' ? 'ALL MEMBERS' : $status_filter . ' ACCOUNTS'); ?>
            <?php if ($search): ?>
                &mdash; SEARCHING: <?php echo htmlspecialchars($search); ?>
            <?php endif; ?>
        </h3>

        <?php if ($users): ?>
            <div class="recent-list">
                <?php foreach ($users as $u):
                    $display   = $u['display_name'] ? $u['display_name'] . ' (' . $u['username'] . ')' : $u['username'];
                    $joined    = date('Y-m-d', strtotime($u['created_at']));
                    $last_seen = $u['last_seen_at'] ? date('Y-m-d', strtotime($u['last_seen_at'])) : 'never';
                    $status_class = match($u['status']) {
                        'active'    => 'status-online',
                        'suspended' => 'status-offline',
                        default     => '',
                    };
                ?>
                <div class="recent-item">
                    <div class="item-details">
                        <div class="item-text">
                            <div class="signal-sender">
                                <?php echo htmlspecialchars($display); ?>
                                <span class="status-pill <?php echo $status_class; ?>" style="font-size:10px;padding:2px 8px;">
                                    <?php echo strtoupper($u['status']); ?>
                                </span>
                                <?php if (!$u['email_verified']): ?>
                                    <span class="alert-text">[UNVERIFIED]</span>
                                <?php endif; ?>
                            </div>

                            <div class="signal-meta">
                                <?php echo htmlspecialchars($u['email']); ?>
                                | JOINED: <?php echo $joined; ?>
                                | LAST SEEN: <?php echo $last_seen; ?>
                                | COMMENTS: <?php echo (int)$u['comment_count']; ?>
                                | LIKES GIVEN: <?php echo (int)$u['like_count']; ?>
                            </div>

                            <div class="item-actions">
                                <?php if ($u['status'] === 'active' || $u['status'] === 'unverified'): ?>
                                    <a href="?action=suspend&id=<?php echo $u['id']; ?>&status=<?php echo urlencode($status_filter); ?>&s=<?php echo urlencode($search); ?>&p=<?php echo $page; ?>"
                                       class="action-delete"
                                       onclick="return confirm('Suspend <?php echo htmlspecialchars($u['username']); ?>?');">
                                        SUSPEND
                                    </a>
                                <?php elseif ($u['status'] === 'suspended'): ?>
                                    <a href="?action=unsuspend&id=<?php echo $u['id']; ?>&status=<?php echo urlencode($status_filter); ?>&s=<?php echo urlencode($search); ?>&p=<?php echo $page; ?>"
                                       class="action-authorize">
                                        REINSTATE
                                    </a>
                                <?php endif; ?>
                                <a href="?action=delete&id=<?php echo $u['id']; ?>&status=<?php echo urlencode($status_filter); ?>&s=<?php echo urlencode($search); ?>&p=<?php echo $page; ?>"
                                   class="action-delete"
                                   onclick="return confirm('Permanently delete <?php echo htmlspecialchars($u['username']); ?> and all their data?');">
                                    DELETE
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?status=<?php echo urlencode($status_filter); ?>&s=<?php echo urlencode($search); ?>&p=<?php echo $i; ?>"
                           class="<?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="read-only-display text-center no-border">NO MEMBERS FOUND.</div>
        <?php endif; ?>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
