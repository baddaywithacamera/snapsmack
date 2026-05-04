<?php
/**
 * SNAPSMACK - Multisite Signal Control
 *
 * Hub-only page. Pulls pending comments from all active spokes via the
 * multisite API and presents a unified moderation queue. Approve/delete
 * actions are proxied back to the originating spoke.
 */

require_once 'core/auth.php';
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

// --- HUB GUARD ---
$multisite_role = $settings['multisite_role'] ?? '';
if ($multisite_role !== 'hub') {
    header('Location: smack-multisite.php');
    exit;
}

// --- ACTIVE SPOKES ---
$spokes = $pdo->query("
    SELECT id, site_url, site_name, api_key_local
    FROM snap_multisite_nodes
    WHERE role = 'spoke' AND status = 'active'
    ORDER BY site_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ─────────────────────────────────────────────────────────────────────────────
// cURL helper: call a spoke API endpoint
// ─────────────────────────────────────────────────────────────────────────────
function ms_spoke_call(string $site_url, string $api_key, string $route, string $method = 'GET', array $post_data = []): ?array {
    $url = rtrim($site_url, '/') . '/api.php?route=' . $route;
    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Accept: application/json',
        ],
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($post_data);
    }
    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw || $code !== 200) return null;
    $decoded = json_decode($raw, true);
    return (is_array($decoded) && ($decoded['ok'] ?? false)) ? $decoded : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST: Proxy an approve/delete action to the originating spoke
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_id'], $_POST['action'], $_POST['node_id'])) {
    $comment_id = (int)$_POST['comment_id'];
    $action     = $_POST['action'];
    $node_id    = (int)$_POST['node_id'];

    if ($comment_id > 0 && in_array($action, ['approve', 'delete'], true)) {
        // Find the spoke this comment belongs to
        $spoke_stmt = $pdo->prepare("SELECT site_url, api_key_local FROM snap_multisite_nodes WHERE id = ? AND role = 'spoke' AND status = 'active'");
        $spoke_stmt->execute([$node_id]);
        $spoke = $spoke_stmt->fetch(PDO::FETCH_ASSOC);

        if ($spoke) {
            $result = ms_spoke_call(
                $spoke['site_url'],
                $spoke['api_key_local'],
                'multisite/comments/action',
                'POST',
                ['comment_id' => $comment_id, 'action' => $action]
            );

            if ($result) {
                $msg = ($action === 'approve')
                    ? "Signal authorized on " . htmlspecialchars($spoke['site_url']) . "."
                    : "Signal terminated on " . htmlspecialchars($spoke['site_url']) . ".";
            } else {
                $err = "Failed to proxy action to spoke. It may be offline.";
            }
        } else {
            $err = "Spoke not found or no longer active.";
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FETCH: Pull pending comments from all active spokes
// ─────────────────────────────────────────────────────────────────────────────
$all_comments  = [];
$fetch_errors  = [];
$total_pending = 0;

foreach ($spokes as $spoke) {
    $result = ms_spoke_call($spoke['site_url'], $spoke['api_key_local'], 'multisite/comments/pending');

    if ($result && !empty($result['comments'])) {
        foreach ($result['comments'] as $c) {
            $c['_node_id']    = $spoke['id'];
            $c['_site_name']  = $spoke['site_name'];
            $c['_site_url']   = $spoke['site_url'];
            $all_comments[]   = $c;
            $total_pending++;
        }
    } elseif ($result === null) {
        $fetch_errors[] = $spoke['site_name'];
    }
    // result with count:0 is fine — no comments, no error
}

// Sort all collected comments by date descending
usort($all_comments, function($a, $b) {
    return strcmp($b['comment_date'] ?? '', $a['comment_date'] ?? '');
});

// Count per spoke BEFORE applying the filter (so nav tabs show correct totals)
$counts_by_node = [];
foreach ($all_comments as $c) {
    $counts_by_node[$c['_node_id']] = ($counts_by_node[$c['_node_id']] ?? 0) + 1;
}

// ─────────────────────────────────────────────────────────────────────────────
// Spoke filter (sidebar drill-down)
// ─────────────────────────────────────────────────────────────────────────────
$filter_node = isset($_GET['node']) ? (int)$_GET['node'] : 0;
if ($filter_node > 0) {
    $all_comments = array_values(array_filter($all_comments, fn($c) => $c['_node_id'] === $filter_node));
}

$page_title = "Spoke Signals";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>SPOKE SIGNAL CONTROL</h2>
        <div class="header-actions">
            <div class="status-pill <?php echo $total_pending > 0 ? 'status-warning' : 'status-online'; ?>">
                <?php echo $total_pending; ?> INCOMING
            </div>
        </div>
    </div>

    <?php if (!empty($fetch_errors)): ?>
        <div class="alert alert-error">> OFFLINE SPOKES (could not fetch): <?php echo htmlspecialchars(implode(', ', $fetch_errors)); ?></div>
    <?php endif; ?>

    <?php if (isset($msg)): ?>
        <div class="msg">> <?php echo $msg; ?></div>
    <?php endif; ?>

    <?php if (isset($err)): ?>
        <div class="alert alert-error">> <?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <?php if (empty($spokes)): ?>
        <div class="box">
            <p style="color:var(--text-muted,#888);">No active spokes connected. <a href="smack-multisite.php" style="color:var(--accent,#aaa);">Register a spoke</a> first.</p>
        </div>
    <?php else: ?>

        <!-- QUICK NAV -->
        <div class="signal-control-header" style="margin-bottom:20px;">
            <div class="signal-nav-group">
                <a href="smack-multisite.php"          class="btn-clear">DASHBOARD</a>
                <a href="smack-multisite-comments.php" class="btn-clear active">SIGNALS</a>
                <a href="smack-multisite-posts.php"    class="btn-clear">POSTS</a>
                <a href="smack-multisite-backup.php"      class="btn-clear">BACKUP DOCK</a>
                <a href="smack-multisite-stats.php"       class="btn-clear">STATS</a>
                <a href="smack-multisite-crosspost.php"   class="btn-clear">CROSS-POST</a>
                <a href="smack-multisite-blogroll.php"    class="btn-clear">BLOGROLL</a>
            </div>
        </div>

        <!-- SPOKE FILTER BAR -->
        <div class="signal-control-header">
            <div class="signal-nav-group">
                <a href="smack-multisite-comments.php" class="btn-clear <?php echo $filter_node === 0 ? 'active' : ''; ?>">
                    ALL SITES (<?php echo $total_pending; ?>)
                </a>
                <?php foreach ($spokes as $spoke):
                    $spoke_count = $counts_by_node[$spoke['id']] ?? 0;
                    $spoke_offline = in_array($spoke['site_name'], $fetch_errors);
                ?>
                    <a href="smack-multisite-comments.php?node=<?php echo $spoke['id']; ?>"
                       class="btn-clear <?php echo $filter_node === $spoke['id'] ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars(strtoupper($spoke['site_name'])); ?>
                        <?php echo $spoke_offline ? ' (OFFLINE)' : ' (' . $spoke_count . ')'; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- COMMENT QUEUE -->
        <div class="box">
            <?php
                $section_label = '';
                if ($filter_node > 0) {
                    foreach ($spokes as $spoke) {
                        if ($spoke['id'] === $filter_node) { $section_label = ' — ' . htmlspecialchars(strtoupper($spoke['site_name'])); break; }
                    }
                }
            ?>
            <h3>AWAITING AUTHORIZATION<?php echo $section_label; ?></h3>

            <?php if (!empty($all_comments)): ?>
                <div class="recent-list">
                    <?php foreach ($all_comments as $c): ?>
                        <div class="recent-item">
                            <div class="item-details">
                                <div class="item-text">
                                    <!-- SPOKE SOURCE BADGE -->
                                    <div style="font-size:0.75rem; color:var(--accent,#aaa); margin-bottom:6px; letter-spacing:1px;">
                                        &#x25BA;&nbsp;<a href="<?php echo htmlspecialchars($c['_site_url']); ?>" target="_blank" style="color:inherit; text-decoration:none;">
                                            <?php echo htmlspecialchars(strtoupper($c['_site_name'])); ?>
                                        </a>
                                    </div>

                                    <div class="signal-sender">
                                        <?php echo htmlspecialchars($c['comment_author'] ?? 'Anonymous'); ?>
                                        <?php if (!empty($c['comment_email'])): ?>
                                            <span>[<?php echo htmlspecialchars($c['comment_email']); ?>]</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="signal-body"><?php echo nl2br(htmlspecialchars($c['comment_text'] ?? '')); ?></div>

                                    <div class="signal-meta">
                                        ON: <?php echo htmlspecialchars($c['img_title'] ?? 'UNKNOWN SOURCE'); ?>
                                        | IP: <?php echo htmlspecialchars($c['comment_ip'] ?? '—'); ?>
                                        | <?php echo htmlspecialchars($c['comment_date'] ?? ''); ?>
                                    </div>

                                    <div class="item-actions">
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="comment_id" value="<?php echo (int)$c['id']; ?>">
                                            <input type="hidden" name="node_id"    value="<?php echo (int)$c['_node_id']; ?>">
                                            <?php if ($filter_node > 0): ?>
                                                <input type="hidden" name="redirect_node" value="<?php echo $filter_node; ?>">
                                            <?php endif; ?>
                                            <button type="submit" name="action" value="approve" class="action-authorize">AUTHORIZE</button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Terminate this signal?');">
                                            <input type="hidden" name="comment_id" value="<?php echo (int)$c['id']; ?>">
                                            <input type="hidden" name="node_id"    value="<?php echo (int)$c['_node_id']; ?>">
                                            <?php if ($filter_node > 0): ?>
                                                <input type="hidden" name="redirect_node" value="<?php echo $filter_node; ?>">
                                            <?php endif; ?>
                                            <button type="submit" name="action" value="delete" class="action-delete">TERMINATE</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="read-only-display text-center no-border">NO INCOMING SIGNALS<?php echo $filter_node > 0 ? ' FROM THIS SPOKE' : ''; ?>.</div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // EOF
