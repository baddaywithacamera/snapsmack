<?php
/**
 * SNAPSMACK - Multisite Post Feed
 * Alpha v0.7.8
 *
 * Hub-only page. Pulls recent published posts from all active satellites via
 * the multisite API and presents a unified reverse-chronological feed.
 * Per-satellite filtering and limit control are supported.
 */

require_once 'core/auth.php';

// --- HUB GUARD ---
$multisite_role = $settings['multisite_role'] ?? '';
if ($multisite_role !== 'hub') {
    header('Location: smack-multisite.php');
    exit;
}

// --- ACTIVE SATELLITES ---
$satellites = $pdo->query("
    SELECT id, site_url, site_name, api_key_local
    FROM snap_multisite_nodes
    WHERE role = 'satellite' AND status = 'active'
    ORDER BY site_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ─────────────────────────────────────────────────────────────────────────────
// cURL helper: call a satellite API endpoint
// ─────────────────────────────────────────────────────────────────────────────
function ms_post_call(string $site_url, string $api_key, string $route): ?array {
    $url = rtrim($site_url, '/') . '/api.php?route=' . $route;
    $ch  = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Accept: application/json',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$raw || $code !== 200) return null;
    $decoded = json_decode($raw, true);
    return (is_array($decoded) && ($decoded['ok'] ?? false)) ? $decoded : null;
}

// ─────────────────────────────────────────────────────────────────────────────
// FETCH: Pull recent posts from all active satellites
// ─────────────────────────────────────────────────────────────────────────────
$per_satellite = min((int)($_GET['limit'] ?? 20), 50);
$all_posts     = [];
$fetch_errors  = [];

foreach ($satellites as $sat) {
    $result = ms_post_call(
        $sat['site_url'],
        $sat['api_key_local'],
        'multisite/posts/recent?limit=' . $per_satellite
    );

    if ($result && !empty($result['posts'])) {
        foreach ($result['posts'] as $p) {
            $p['_node_id']   = $sat['id'];
            $p['_site_name'] = $sat['site_name'];
            $p['_site_url']  = $sat['site_url'];
            $all_posts[]     = $p;
        }
    } elseif ($result === null) {
        $fetch_errors[] = $sat['site_name'];
    }
}

// Sort all collected posts by creation date descending
usort($all_posts, function($a, $b) {
    return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
});

// ─────────────────────────────────────────────────────────────────────────────
// Satellite filter
// ─────────────────────────────────────────────────────────────────────────────
$filter_node = isset($_GET['node']) ? (int)$_GET['node'] : 0;
if ($filter_node > 0) {
    $all_posts = array_values(array_filter($all_posts, fn($p) => $p['_node_id'] === $filter_node));
}

// Post type filter
$filter_type = $_GET['type'] ?? 'all';

// Get unique post types across all fetched posts for filter bar
$post_types = array_unique(array_column($all_posts, 'post_type'));
sort($post_types);

if ($filter_type !== 'all') {
    $all_posts = array_values(array_filter($all_posts, fn($p) => ($p['post_type'] ?? '') === $filter_type));
}

$total_posts = count($all_posts);

$page_title = "Satellite Posts";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>SATELLITE POST FEED</h2>
        <div class="header-actions">
            <div class="status-pill status-online">
                <?php echo $total_posts; ?> POST<?php echo $total_posts !== 1 ? 'S' : ''; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($fetch_errors)): ?>
        <div class="alert alert-error">> OFFLINE SATELLITES (could not fetch): <?php echo htmlspecialchars(implode(', ', $fetch_errors)); ?></div>
    <?php endif; ?>

    <?php if (empty($satellites)): ?>
        <div class="box">
            <p style="color:var(--text-muted,#888);">No active satellites connected. <a href="smack-multisite.php" style="color:var(--accent,#aaa);">Register a satellite</a> first.</p>
        </div>
    <?php else: ?>

        <!-- QUICK NAV -->
        <div class="signal-control-header" style="margin-bottom:20px;">
            <div class="signal-nav-group">
                <a href="smack-multisite.php"          class="btn-clear">DASHBOARD</a>
                <a href="smack-multisite-comments.php" class="btn-clear">SIGNALS</a>
                <a href="smack-multisite-posts.php"    class="btn-clear active">POSTS</a>
                <a href="smack-multisite-backup.php"      class="btn-clear">BACKUP DOCK</a>
                <a href="smack-multisite-stats.php"       class="btn-clear">STATS</a>
                <a href="smack-multisite-crosspost.php"   class="btn-clear">CROSS-POST</a>
                <a href="smack-multisite-blogroll.php"    class="btn-clear">BLOGROLL</a>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="signal-control-header">
            <div class="signal-nav-group">
                <!-- Site filter -->
                <a href="smack-multisite-posts.php<?php echo $filter_type !== 'all' ? '?type=' . urlencode($filter_type) : ''; ?>"
                   class="btn-clear <?php echo $filter_node === 0 ? 'active' : ''; ?>">
                    ALL SITES
                </a>
                <?php foreach ($satellites as $sat): ?>
                    <a href="smack-multisite-posts.php?node=<?php echo $sat['id']; ?><?php echo $filter_type !== 'all' ? '&type=' . urlencode($filter_type) : ''; ?>"
                       class="btn-clear <?php echo $filter_node === $sat['id'] ? 'active' : ''; ?>">
                        <?php echo htmlspecialchars(strtoupper($sat['site_name'])); ?>
                        <?php if (in_array($sat['site_name'], $fetch_errors)): ?>
                            (OFFLINE)
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>

                <?php if (!empty($post_types) || $filter_type !== 'all'): ?>
                    <span class="sep">|</span>
                    <a href="smack-multisite-posts.php<?php echo $filter_node > 0 ? '?node=' . $filter_node : ''; ?>"
                       class="btn-clear <?php echo $filter_type === 'all' ? 'active' : ''; ?>">ALL TYPES</a>
                    <?php foreach ($post_types as $pt): ?>
                        <a href="smack-multisite-posts.php?<?php echo $filter_node > 0 ? 'node=' . $filter_node . '&' : ''; ?>type=<?php echo urlencode($pt); ?>"
                           class="btn-clear <?php echo $filter_type === $pt ? 'active' : ''; ?>">
                            <?php echo htmlspecialchars(strtoupper($pt)); ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- POST FEED -->
        <div class="box">
            <h3>RECENT POSTS<?php
                $labels = [];
                if ($filter_node > 0) {
                    $sat_names = array_column($satellites, 'site_name', 'id');
                    $labels[] = htmlspecialchars(strtoupper($sat_names[$filter_node] ?? ''));
                }
                if ($filter_type !== 'all') $labels[] = htmlspecialchars(strtoupper($filter_type));
                if (!empty($labels)) echo ' — ' . implode(' / ', $labels);
            ?></h3>

            <?php if (!empty($all_posts)): ?>
                <div class="recent-list">
                    <?php foreach ($all_posts as $p): ?>
                        <div class="recent-item">
                            <div class="item-details">
                                <?php if (!empty($p['thumb_url'])): ?>
                                    <a href="<?php echo htmlspecialchars($p['post_url'] ?? '#'); ?>" target="_blank">
                                        <img src="<?php echo htmlspecialchars($p['thumb_url']); ?>"
                                             class="archive-thumb"
                                             alt="<?php echo htmlspecialchars($p['title'] ?? ''); ?>"
                                             onerror="this.style.display='none'">
                                    </a>
                                <?php endif; ?>

                                <div class="item-text">
                                    <!-- SATELLITE SOURCE BADGE -->
                                    <div style="font-size:0.75rem; color:var(--accent,#aaa); margin-bottom:4px; letter-spacing:1px;">
                                        &#x25BA;&nbsp;<a href="<?php echo htmlspecialchars($p['_site_url']); ?>" target="_blank" style="color:inherit; text-decoration:none;">
                                            <?php echo htmlspecialchars(strtoupper($p['_site_name'])); ?>
                                        </a>
                                    </div>

                                    <div class="item-title">
                                        <a href="<?php echo htmlspecialchars($p['post_url'] ?? '#'); ?>" target="_blank"
                                           style="color:var(--text,#eee); text-decoration:none;">
                                            <?php echo htmlspecialchars($p['title'] ?? 'Untitled'); ?>
                                        </a>
                                    </div>

                                    <?php if (!empty($p['description'])): ?>
                                        <div class="item-desc" style="color:var(--text-muted,#888); font-size:0.9rem; margin:4px 0 8px;">
                                            <?php echo htmlspecialchars(mb_strimwidth($p['description'] ?? '', 0, 160, '…')); ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="signal-meta">
                                        TYPE: <?php echo htmlspecialchars(strtoupper($p['post_type'] ?? 'post')); ?>
                                        | POSTED: <?php echo htmlspecialchars(substr($p['created_at'] ?? '', 0, 16)); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (count($all_posts) >= ($per_satellite * count($satellites))): ?>
                    <div style="text-align:center; padding:15px; color:var(--text-muted,#888); font-size:0.85rem;">
                        Showing <?php echo $per_satellite; ?> posts per satellite.
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['limit' => min($per_satellite + 20, 50)])); ?>"
                           style="color:var(--accent,#aaa);">LOAD MORE</a>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="read-only-display text-center no-border">NO POSTS FOUND.</div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
