<?php
/**
 * SNAPSMACK - Comment moderation interface
 * Alpha v0.7.3a
 *
 * Manages review, approval, and deletion of visitor comments.
 * Covers both legacy anonymous comments (snap_comments) and community
 * account comments (snap_community_comments). Tab selector switches
 * between the two systems. Search and pagination apply within each tab.
 */

require_once 'core/auth.php';

// --- GLOBAL COMMENT SETTINGS ---
$s_rows = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$global_comments_active = (($s_rows['global_comments_enabled'] ?? '1') == '1');

// --- SYSTEM TAB ---
// 'legacy'    = snap_comments (anonymous, moderation queue)
// 'community' = snap_community_comments (account/guest, visible/hidden)
$system = $_GET['system'] ?? 'legacy';

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
$search    = trim($_GET['s'] ?? '');
$per_page  = 20;
$page      = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$offset    = ($page - 1) * $per_page;

// ── Community counts (for nav tabs) ──────────────────────────────────────────
// Guarded: snap_community_comments only exists after the community migration.
try {
    $cc_visible_count = $pdo->query("SELECT COUNT(*) FROM snap_community_comments WHERE status = 'visible'")->fetchColumn();
    $cc_hidden_count  = $pdo->query("SELECT COUNT(*) FROM snap_community_comments WHERE status = 'hidden'")->fetchColumn();
} catch (Exception $e) {
    $cc_visible_count = 0;
    $cc_hidden_count  = 0;
}

// ── Legacy counts (for nav tabs) ─────────────────────────────────────────────
$leg_pending_count = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 0")->fetchColumn();
$leg_live_count    = $pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 1")->fetchColumn();


// ═══════════════════════════════════════════════════════════════════════════════
//  DATA RETRIEVAL — branch on $system
// ═══════════════════════════════════════════════════════════════════════════════

$comments      = [];
$total_records = 0;
$total_pages   = 1;

if ($system === 'community') {

    // Build search clause against community fields
    $search_clause = $search
        ? " AND (u.username LIKE :s1 OR cc.comment_text LIKE :s2 OR cc.guest_name LIKE :s3 OR cc.guest_email LIKE :s4)"
        : "";

    $status_filter = ($view_mode === 'hidden') ? 'hidden' : 'visible';

    $count_sql = "
        SELECT COUNT(*)
        FROM snap_community_comments cc
        LEFT JOIN snap_community_users u ON cc.user_id = u.id
        WHERE cc.status = :status" . $search_clause;

    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->bindValue(':status', $status_filter);
    if ($search) {
        $s = "%$search%";
        $count_stmt->bindValue(':s1', $s);
        $count_stmt->bindValue(':s2', $s);
        $count_stmt->bindValue(':s3', $s);
        $count_stmt->bindValue(':s4', $s);
    }
    $count_stmt->execute();
    $total_records = (int)$count_stmt->fetchColumn();
    $total_pages   = max(1, ceil($total_records / $per_page));

    $data_sql = "
        SELECT cc.id,
               cc.post_id,
               cc.user_id,
               cc.guest_name,
               cc.guest_email,
               cc.comment_text,
               cc.status,
               cc.ip,
               cc.created_at,
               u.username,
               u.email        AS user_email,
               i.img_title,
               i.img_file,
               i.allow_comments AS post_comments_active
        FROM snap_community_comments cc
        LEFT JOIN snap_community_users u  ON cc.user_id = u.id
        LEFT JOIN snap_images          i  ON cc.post_id = i.id
        WHERE cc.status = :status" . $search_clause . "
        ORDER BY cc.created_at DESC
        LIMIT :lim OFFSET :off";

    $data_stmt = $pdo->prepare($data_sql);
    $data_stmt->bindValue(':status', $status_filter);
    $data_stmt->bindValue(':lim',    $per_page, PDO::PARAM_INT);
    $data_stmt->bindValue(':off',    $offset,   PDO::PARAM_INT);
    if ($search) {
        $s = "%$search%";
        $data_stmt->bindValue(':s1', $s);
        $data_stmt->bindValue(':s2', $s);
        $data_stmt->bindValue(':s3', $s);
        $data_stmt->bindValue(':s4', $s);
    }
    $data_stmt->execute();
    $raw = $data_stmt->fetchAll();

    // Normalise into display-friendly shape
    foreach ($raw as $row) {
        $row['display_author'] = $row['username']
            ?? $row['guest_name']
            ?? 'Anonymous';
        $row['display_email'] = $row['user_email']
            ?? $row['guest_email']
            ?? '';
        $row['display_date'] = $row['created_at'];
        $row['display_ip']   = $row['ip'] ?? '';
        $comments[] = $row;
    }

} else {

    // ── Legacy snap_comments ──────────────────────────────────────────────
    $search_clause = $search
        ? " AND (c.comment_author LIKE ? OR c.comment_text LIKE ? OR c.comment_email LIKE ?)"
        : "";
    $search_params = $search ? ["%$search%", "%$search%", "%$search%"] : [];

    $approved_val = ($view_mode === 'live') ? 1 : 0;

    $count_sql  = "SELECT COUNT(*) FROM snap_comments c WHERE c.is_approved = $approved_val" . $search_clause;
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($search_params);
    $total_records = (int)$count_stmt->fetchColumn();
    $total_pages   = max(1, ceil($total_records / $per_page));

    $data_sql = "SELECT c.*, i.img_title, i.img_file, i.allow_comments AS post_comments_active
                 FROM snap_comments c
                 LEFT JOIN snap_images i ON c.img_id = i.id
                 WHERE c.is_approved = $approved_val" . $search_clause . "
                 ORDER BY c.comment_date DESC
                 LIMIT $per_page OFFSET $offset";
    $data_stmt = $pdo->prepare($data_sql);
    $data_stmt->execute($search_params);
    $raw = $data_stmt->fetchAll();

    foreach ($raw as $row) {
        $row['display_author'] = $row['comment_author'] ?? 'Anonymous';
        $row['display_email']  = $row['comment_email']  ?? '';
        $row['display_date']   = $row['comment_date']   ?? '';
        $row['display_ip']     = $row['comment_ip']     ?? '';
        $comments[] = $row;
    }
}

// ── Section heading ───────────────────────────────────────────────────────────
if ($system === 'community') {
    $section_heading = ($view_mode === 'hidden') ? 'HIDDEN SIGNALS' : 'LIVE SIGNALS';
} else {
    $section_heading = ($view_mode === 'live') ? 'LIVE SIGNALS' : 'AWAITING AUTHORIZATION';
}

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
            <input type="hidden" name="system" value="<?php echo htmlspecialchars($system); ?>">
            <input type="hidden" name="view"   value="<?php echo htmlspecialchars($view_mode); ?>">
            <input type="text"   name="s" placeholder="SCAN FREQUENCIES..." value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn-smack">SCAN</button>
        </form>

        <div class="signal-nav-group">
            <?php if ($system === 'community'): ?>
                <a href="?system=community&view=visible" class="btn-clear <?php echo $view_mode === 'visible' ? 'active' : ''; ?>">VISIBLE (<?php echo $cc_visible_count; ?>)</a>
                <a href="?system=community&view=hidden"  class="btn-clear <?php echo $view_mode === 'hidden'  ? 'active' : ''; ?>">HIDDEN (<?php echo $cc_hidden_count; ?>)</a>
            <?php else: ?>
                <a href="?system=legacy&view=pending" class="btn-clear <?php echo $view_mode === 'pending' ? 'active' : ''; ?>">INCOMING (<?php echo $leg_pending_count; ?>)</a>
                <a href="?system=legacy&view=live"    class="btn-clear <?php echo $view_mode === 'live'    ? 'active' : ''; ?>">BROADCASTING (<?php echo $leg_live_count; ?>)</a>
            <?php endif; ?>
            <span class="sep">|</span>
            <a href="?system=community" class="btn-clear <?php echo $system === 'community' ? 'active' : ''; ?>">COMMUNITY</a>
            <a href="?system=legacy"    class="btn-clear <?php echo $system === 'legacy'    ? 'active' : ''; ?>">LEGACY</a>
        </div>
    </div>

    <?php if (isset($msg)) echo "<div class='msg'>> $msg</div>"; ?>

    <div class="box">
        <h3><?php echo $section_heading; ?></h3>

        <?php if ($comments): ?>
            <div class="recent-list">
                <?php foreach ($comments as $c): ?>
                    <div class="recent-item">
                        <div class="item-details">
                            <?php if (!empty($c['img_file'])): ?>
                                <img src="/<?php echo htmlspecialchars($c['img_file']); ?>" class="archive-thumb">
                            <?php endif; ?>
                            <div class="item-text">
                                <div class="signal-sender">
                                    <?php echo htmlspecialchars($c['display_author']); ?>
                                    <?php if ($c['display_email']): ?>
                                        <span>[<?php echo htmlspecialchars($c['display_email']); ?>]</span>
                                    <?php endif; ?>
                                </div>

                                <div class="signal-body"><?php echo nl2br(htmlspecialchars($c['comment_text'])); ?></div>

                                <div class="signal-meta">
                                    ON: <?php echo htmlspecialchars($c['img_title'] ?? 'UNKNOWN SOURCE'); ?>
                                    <?php if (isset($c['post_comments_active']) && $c['post_comments_active'] == 0): ?>
                                        <span class="alert-text">[FREQUENCY MUTED]</span>
                                    <?php endif; ?>
                                    | IP: <?php echo htmlspecialchars($c['display_ip'] ?: '—'); ?>
                                    | <?php echo htmlspecialchars($c['display_date']); ?>
                                </div>

                                <div class="item-actions">
                                    <?php
                                    $base = '?system=' . urlencode($system)
                                          . '&view='   . urlencode($view_mode)
                                          . '&id='     . $c['id']
                                          . '&s='      . urlencode($search)
                                          . '&p='      . $page;
                                    if ($system === 'community') {
                                        if ($view_mode === 'visible') {
                                            echo '<a href="' . $base . '&action=hide"    class="action-hide">HIDE</a>';
                                        } else {
                                            echo '<a href="' . $base . '&action=restore" class="action-authorize">RESTORE</a>';
                                        }
                                        echo '<a href="' . $base . '&action=delete" class="action-delete" onclick="return confirm(\'Terminate signal?\');">TERMINATE</a>';
                                    } else {
                                        if ($view_mode === 'pending') {
                                            echo '<a href="' . $base . '&action=approve" class="action-authorize">AUTHORIZE</a>';
                                        }
                                        echo '<a href="' . $base . '&action=delete" class="action-delete" onclick="return confirm(\'Terminate signal?\');">TERMINATE</a>';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?system=<?php echo urlencode($system); ?>&view=<?php echo urlencode($view_mode); ?>&s=<?php echo urlencode($search); ?>&p=<?php echo $i; ?>"
                           class="<?php echo ($i == $page) ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="read-only-display text-center no-border">NO SIGNALS DETECTED.</div>
        <?php endif; ?>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
