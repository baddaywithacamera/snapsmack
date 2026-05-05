<?php
/**
 * SNAPSMACK - Comment moderation interface
 *
 * Manages review, approval, and deletion of visitor comments.
 * Covers both legacy anonymous comments (snap_comments) and community
 * account comments (snap_community_comments). Tab selector switches
 * between the two systems. Search and pagination apply within each tab.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth.php';
require_once 'core/ban-check.php';
require_once 'core/ste-client.php';

// --- GLOBAL COMMENT SETTINGS ---
$s_rows = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$global_comments_active = (($s_rows['global_comments_enabled'] ?? '1') == '1');

// --- SYSTEM TAB ---
// 'legacy'    = snap_comments (anonymous, moderation queue)
// 'community' = snap_community_comments (account/guest, visible/hidden)
// 'bans'      = snap_ban_list management
$system = $_GET['system'] ?? 'legacy';

// --- MODERATION ACTIONS ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];

    // ── Ban actions (work regardless of system tab) ───────────────────────────
    if ($_GET['action'] === 'ban_fp' || $_GET['action'] === 'ban_ip') {
        // Fetch the comment to get its fingerprint / IP
        $tbl   = ($system === 'community') ? 'snap_community_comments' : 'snap_comments';
        $ip_col = ($system === 'community') ? 'ip' : 'comment_ip';
        $row = $pdo->prepare("SELECT fp_hash, $ip_col AS ip FROM $tbl WHERE id = ? LIMIT 1");
        $row->execute([$id]);
        $cmt = $row->fetch(PDO::FETCH_ASSOC);
        if ($cmt) {
            $admin_id = (int)($_SESSION['user_id'] ?? 0) ?: null;
            if ($_GET['action'] === 'ban_fp' && !empty($cmt['fp_hash'])) {
                add_ban($pdo, 'fingerprint', $cmt['fp_hash'], 'Banned via comment moderation', $admin_id);
                $msg = "Browser fingerprint banned. Future submissions from this device will be silently discarded.";
            } elseif ($_GET['action'] === 'ban_ip' && !empty($cmt['ip'])) {
                add_ban($pdo, 'ip', $cmt['ip'], 'Banned via comment moderation', $admin_id);
                $msg = "IP address banned.";
            } else {
                $msg = "No fingerprint or IP available for this comment — nothing to ban.";
            }
        }
    }

    // ── Remove ban ────────────────────────────────────────────────────────────
    elseif ($_GET['action'] === 'unban' && $system === 'bans') {
        remove_ban($pdo, $id);
        $msg = "Ban removed.";
    }

    // ── Standard comment actions ──────────────────────────────────────────────
    elseif ($system === 'community') {
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
            // Fetch comment for STE allow-vote before approving
            $_ste_cmt = $pdo->prepare("SELECT fp_hash, comment_ip FROM snap_comments WHERE id = ? LIMIT 1");
            $_ste_cmt->execute([$id]);
            $_ste_row = $_ste_cmt->fetch(PDO::FETCH_ASSOC);

            $pdo->prepare("UPDATE snap_comments SET is_approved = 1 WHERE id = ?")->execute([$id]);
            $msg = "Signal authorized. Broadcasting live.";

            // Send allow-vote to SMACK THE ENEMY (non-fatal)
            try {
                $_ste_on  = ($s_rows['ste_enabled']  ?? '0') === '1';
                $_ste_key = $s_rows['ste_api_key']   ?? '';
                if ($_ste_on && $_ste_key !== '' && $_ste_row) {
                    if (!empty($_ste_row['fp_hash']))   ste_client_allow($_ste_key, 'fingerprint', $_ste_row['fp_hash']);
                    if (!empty($_ste_row['comment_ip'])) ste_client_allow($_ste_key, 'ip',         $_ste_row['comment_ip']);
                }
            } catch (Exception $e) { /* non-fatal */ }
        } elseif ($_GET['action'] == 'delete') {
            $pdo->prepare("DELETE FROM snap_comments WHERE id = ?")->execute([$id]);
            $msg = "Signal terminated.";
        }
    }
}

// --- BAN LIST DATA (for bans tab) ---
$ban_rows = [];
if ($system === 'bans') {
    try {
        $ban_rows = $pdo->query("
            SELECT b.*, u.username AS banned_by_name
            FROM snap_ban_list b
            LEFT JOIN snap_users u ON u.id = b.banned_by
            ORDER BY b.banned_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $ban_rows = [];
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

// ── SMACK THE ENEMY display settings ─────────────────────────────────────────
$_ste_active = ($s_rows['ste_enabled'] ?? '0') === '1' && ($s_rows['ui_mode'] ?? 'bigwheel') === 'pimpmobile';


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
            <a href="?system=bans"      class="btn-clear <?php echo $system === 'bans'      ? 'active' : ''; ?>">BAN LIST (<?php echo count($ban_rows); ?>)</a>
        </div>
    </div>

    <?php if (isset($msg)) echo "<div class='msg'>> $msg</div>"; ?>

    <?php if ($system === 'bans'): ?>
    <div class="box">
        <h3>BAN LIST</h3>
        <p style="color:var(--fg-dim);font-size:0.85em;margin-bottom:1rem;">
            Banned submissions are silently discarded — the sender sees a normal success response and never knows they are blocked.
        </p>
        <?php if ($ban_rows): ?>
            <div class="recent-list">
                <?php foreach ($ban_rows as $b): ?>
                    <div class="recent-item">
                        <div class="item-text">
                            <div class="signal-sender">
                                <?php echo htmlspecialchars(strtoupper($b['ban_type'])); ?>:
                                <code><?php echo htmlspecialchars($b['ban_value']); ?></code>
                            </div>
                            <div class="signal-meta">
                                Banned <?php echo htmlspecialchars($b['banned_at']); ?>
                                <?php if ($b['banned_by_name']): ?>
                                    by <?php echo htmlspecialchars($b['banned_by_name']); ?>
                                <?php endif; ?>
                                <?php if ($b['reason']): ?>
                                    — <?php echo htmlspecialchars($b['reason']); ?>
                                <?php endif; ?>
                            </div>
                            <div class="item-actions">
                                <a href="?system=bans&action=unban&id=<?php echo $b['id']; ?>"
                                   class="action-authorize"
                                   onclick="return confirm('Remove this ban?');">UNBAN</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="read-only-display text-center no-border">NO ACTIVE BANS.</div>
        <?php endif; ?>
    </div>
    <?php else: ?>
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
                                    <?php if (!empty($c['fp_hash'])): ?>
                                        | FP: <span title="<?php echo htmlspecialchars($c['fp_hash']); ?>" class="fp-indicator">&#x2022;&#x2022;&#x2022;<?php echo substr($c['fp_hash'], -6); ?></span>
                                    <?php else: ?>
                                        | FP: <span class="fp-none" title="No fingerprint — submitted before fingerprinting was enabled, or JavaScript was disabled">—</span>
                                    <?php endif; ?>
                                    <?php if ($_ste_active): ?>
                                        <?php
                                        $_ste_checks = [];
                                        if (!empty($c['fp_hash']))     $_ste_checks[] = ['ban_type' => 'fingerprint', 'ban_value' => $c['fp_hash']];
                                        if (!empty($c['display_ip']))  $_ste_checks[] = ['ban_type' => 'ip',          'ban_value' => $c['display_ip']];
                                        $_ste_colour = !empty($_ste_checks) ? ste_worst_colour($pdo, $_ste_checks) : 'green';
                                        $_ste_labels = ['green' => 'Clean', 'yellow' => '1 strike', 'orange' => '2 strikes', 'red' => '3 strikes', 'black' => '4+ strikes'];
                                        ?>
                                        | <span class="ste-dot ste-dot-<?php echo $_ste_colour; ?>" title="Network: <?php echo $_ste_labels[$_ste_colour] ?? $_ste_colour; ?>">&#x2B24;</span>
                                    <?php endif; ?>
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
                                    // Ban controls — shown when a fingerprint or IP is available
                                    if (!empty($c['fp_hash'])) {
                                        echo '<a href="' . $base . '&action=ban_fp" class="action-delete" onclick="return confirm(\'Ban this browser fingerprint? Future submissions from this device will be silently discarded.\');">BAN DEVICE</a>';
                                    }
                                    if (!empty($c['display_ip'])) {
                                        echo '<a href="' . $base . '&action=ban_ip" class="action-delete" onclick="return confirm(\'Ban this IP address?\');">BAN IP</a>';
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
    <?php endif; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
