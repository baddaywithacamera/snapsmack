<?php
/**
 * SNAPSMACK - Multisite Cross-Post
 *
 * Hub-only page. Browse the hub's published image library, select one or
 * more posts, choose target spokes, and push the content across. The hub
 * sends each spoke the post metadata and a public URL for the image —
 * the spoke fetches the image itself and creates a local draft/published
 * record via the multisite/posts/create API endpoint.
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
// cURL helper: call a spoke API endpoint (POST)
// ─────────────────────────────────────────────────────────────────────────────
function ms_crosspost_call(string $site_url, string $api_key, string $route, array $post_data): array {
    $url = rtrim($site_url, '/') . '/api.php?route=' . $route;
    $ch  = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post_data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,    // Give spoke time to fetch the image
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $api_key,
            'Accept: application/json',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if (!$raw) return ['ok' => false, 'error' => $cerr ?: 'No response'];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return ['ok' => false, 'error' => 'Invalid JSON from spoke'];
    return $decoded;
}

// ─────────────────────────────────────────────────────────────────────────────
// POST HANDLER: Execute cross-post
// ─────────────────────────────────────────────────────────────────────────────
$xp_results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['xp_submit'])) {
    $selected_img_ids = array_map('intval', (array)($_POST['img_ids'] ?? []));
    $selected_spoke_ids = array_map('intval', (array)($_POST['spoke_ids'] ?? []));
    $xp_status        = in_array($_POST['xp_status'] ?? '', ['draft', 'published']) ? $_POST['xp_status'] : 'draft';

    if (empty($selected_img_ids)) {
        $err = "Select at least one post to cross-post.";
    } elseif (empty($selected_spoke_ids)) {
        $err = "Select at least one spoke to post to.";
    } else {
        // Load selected images from hub DB
        $placeholders = implode(',', array_fill(0, count($selected_img_ids), '?'));
        $img_stmt = $pdo->prepare("
            SELECT id, img_title, img_slug, img_file, img_description,
                   img_film, img_date, img_status,
                   img_thumb_aspect, img_thumb_square
            FROM snap_images
            WHERE id IN ($placeholders) AND img_status = 'published'
        ");
        $img_stmt->execute($selected_img_ids);
        $hub_images = $img_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($hub_images)) {
            $err = "None of the selected posts were found or are published.";
        } else {
            // Build map of selected spokes
            $spoke_map = [];
            foreach ($spokes as $spoke) {
                if (in_array($spoke['id'], $selected_spoke_ids)) {
                    $spoke_map[$spoke['id']] = $spoke;
                }
            }

            foreach ($hub_images as $img) {
                $img_url = rtrim(BASE_URL, '/') . '/' . ltrim($img['img_file'], '/');
                $img_ext = strtolower(pathinfo($img['img_file'], PATHINFO_EXTENSION));

                $row_results = [];
                foreach ($spoke_map as $spoke_id => $spoke) {
                    $resp = ms_crosspost_call(
                        $spoke['site_url'],
                        $spoke['api_key_local'],
                        'multisite/posts/create',
                        [
                            'title'       => $img['img_title'],
                            'description' => $img['img_description'] ?? '',
                            'img_url'     => $img_url,
                            'img_ext'     => $img_ext,
                            'img_status'  => $xp_status,
                            'img_date'    => substr($img['img_date'] ?? '', 0, 10),
                            'film'        => $img['img_film'] ?? '',
                        ]
                    );

                    $row_results[$spoke['site_name']] = [
                        'ok'      => !empty($resp['ok']),
                        'img_id'  => $resp['img_id']  ?? null,
                        'post_url'=> $resp['post_url'] ?? null,
                        'error'   => $resp['error']   ?? null,
                    ];
                }
                $xp_results[$img['img_title']] = $row_results;
            }
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Load hub's own published images for the picker
// ─────────────────────────────────────────────────────────────────────────────
$search  = trim($_GET['s'] ?? '');
$page    = max(1, (int)($_GET['p'] ?? 1));
$per_pg  = 24;
$offset  = ($page - 1) * $per_pg;

$search_clause = $search ? " AND (img_title LIKE ? OR img_description LIKE ?)" : "";
$search_params = $search ? ["%$search%", "%$search%"] : [];

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published'" . $search_clause);
$count_stmt->execute($search_params);
$total = (int)$count_stmt->fetchColumn();
$total_pages = max(1, ceil($total / $per_pg));

$img_stmt = $pdo->prepare("
    SELECT id, img_title, img_slug, img_file, img_description,
           img_thumb_square, img_thumb_aspect, img_date
    FROM snap_images
    WHERE img_status = 'published'" . $search_clause . "
    ORDER BY img_date DESC, id DESC
    LIMIT $per_pg OFFSET $offset
");
$img_stmt->execute($search_params);
$hub_images_browse = $img_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Cross-Post";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>CROSS-POST</h2>
        <div class="header-actions">
            <div class="status-pill status-online">
                <?php echo count($spokes); ?> SPOKE<?php echo count($spokes) !== 1 ? 'S' : ''; ?> AVAILABLE
            </div>
        </div>
    </div>

    <!-- QUICK NAV -->
    <div class="signal-control-header" style="margin-bottom:20px;">
        <div class="signal-nav-group">
            <a href="smack-multisite.php"             class="btn-clear">DASHBOARD</a>
            <a href="smack-multisite-comments.php"    class="btn-clear">SIGNALS</a>
            <a href="smack-multisite-posts.php"       class="btn-clear">POSTS</a>
            <a href="smack-multisite-backup.php"      class="btn-clear">BACKUP DOCK</a>
            <a href="smack-multisite-stats.php"       class="btn-clear">STATS</a>
            <a href="smack-multisite-crosspost.php"   class="btn-clear active">CROSS-POST</a>
            <a href="smack-multisite-blogroll.php"    class="btn-clear">BLOGROLL</a>
        </div>
    </div>

    <?php if (isset($err)): ?>
        <div class="alert alert-error">> <?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <!-- CROSS-POST RESULTS -->
    <?php if (!empty($xp_results)): ?>
        <div class="box">
            <h3>CROSS-POST RESULTS</h3>
            <?php foreach ($xp_results as $img_title => $spoke_results): ?>
                <div style="margin-bottom:15px; padding-bottom:15px; border-bottom:1px solid var(--border,#333);">
                    <div style="font-weight:700; margin-bottom:8px;"><?php echo htmlspecialchars($img_title); ?></div>
                    <div style="display:flex; flex-wrap:wrap; gap:10px;">
                        <?php foreach ($spoke_results as $spoke_name => $result): ?>
                            <div style="padding:8px 14px; border:1px solid <?php echo $result['ok'] ? '#4CAF50' : '#f44336'; ?>;
                                        border-radius:3px; font-size:0.8rem; display:flex; align-items:center; gap:8px;">
                                <span style="color:<?php echo $result['ok'] ? '#4CAF50' : '#f44336'; ?>; font-weight:700;">
                                    <?php echo $result['ok'] ? '&#x2713;' : '&#x2717;'; ?>
                                </span>
                                <span style="color:var(--text-muted,#888);"><?php echo htmlspecialchars($spoke_name); ?></span>
                                <?php if ($result['ok'] && $result['post_url']): ?>
                                    <a href="<?php echo htmlspecialchars($result['post_url']); ?>" target="_blank"
                                       style="color:var(--accent,#aaa); font-size:0.75rem;">VIEW</a>
                                <?php elseif (!$result['ok']): ?>
                                    <span style="color:#f44336; font-size:0.75rem;"><?php echo htmlspecialchars($result['error'] ?? 'Failed'); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($spokes)): ?>
        <div class="box">
            <p style="color:var(--text-muted,#888);">No active spokes connected. <a href="smack-multisite.php" style="color:var(--accent,#aaa);">Register a spoke</a> first.</p>
        </div>
    <?php else: ?>

    <form method="POST" id="crosspost-form">

        <!-- TARGET OPTIONS (sticky top strip) -->
        <div class="box" style="position:sticky; top:0; z-index:50; background:var(--bg,#0a0a0a);">
            <div style="display:flex; flex-wrap:wrap; align-items:center; gap:20px;">
                <div>
                    <div style="font-size:0.75rem; color:var(--text-muted,#888); letter-spacing:1px; margin-bottom:8px;">TARGET SPOKES</div>
                    <div style="display:flex; flex-wrap:wrap; gap:8px;">
                        <?php foreach ($spokes as $spoke): ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer;
                                          padding:6px 12px; border:1px solid var(--border,#333);
                                          border-radius:3px; font-size:0.85rem;">
                                <input type="checkbox" name="spoke_ids[]" value="<?php echo $spoke['id']; ?>"
                                       class="tactical-checkbox" style="margin:0;">
                                <?php echo htmlspecialchars($spoke['site_name']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <div style="font-size:0.75rem; color:var(--text-muted,#888); letter-spacing:1px; margin-bottom:8px;">PUBLISH AS</div>
                    <div style="display:flex; gap:8px;">
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.85rem;">
                            <input type="radio" name="xp_status" value="draft" checked class="tactical-checkbox" style="margin:0;">
                            DRAFT
                        </label>
                        <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:0.85rem;">
                            <input type="radio" name="xp_status" value="published" class="tactical-checkbox" style="margin:0;">
                            PUBLISHED
                        </label>
                    </div>
                </div>

                <div style="margin-left:auto;">
                    <button type="submit" name="xp_submit" value="1" class="master-update-btn"
                            onclick="return confirmCrossPost();">
                        CROSS-POST SELECTED
                    </button>
                </div>
            </div>
        </div>

        <!-- SEARCH -->
        <div style="margin-bottom:20px; display:flex; gap:10px; align-items:center;">
            <form method="GET" style="display:flex; gap:8px; flex:1; margin:0;" id="search-form">
                <input type="text" name="s" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="SEARCH POSTS..." style="flex:1;">
                <button type="submit" class="btn-smack">SCAN</button>
            </form>
            <div style="font-size:0.85rem; color:var(--text-muted,#888);">
                <?php echo $total; ?> POST<?php echo $total !== 1 ? 'S' : ''; ?>
            </div>
        </div>

        <!-- IMAGE GRID PICKER -->
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:12px; margin-bottom:20px;">
            <?php foreach ($hub_images_browse as $img): ?>
                <?php
                    $thumb = $img['img_thumb_square'] ?: $img['img_thumb_aspect'] ?: $img['img_file'];
                ?>
                <label style="position:relative; cursor:pointer; display:block;">
                    <input type="checkbox" name="img_ids[]" value="<?php echo $img['id']; ?>"
                           class="img-picker-cb" style="position:absolute; top:8px; left:8px; z-index:2; width:18px; height:18px; cursor:pointer;">

                    <div class="img-picker-card" style="border:2px solid var(--border,#333); border-radius:3px; overflow:hidden; transition:border-color 0.15s;">
                        <?php if ($thumb): ?>
                            <div style="aspect-ratio:1; overflow:hidden; background:#111;">
                                <img src="/<?php echo htmlspecialchars(ltrim($thumb, '/')); ?>"
                                     loading="lazy"
                                     style="width:100%; height:100%; object-fit:cover; display:block;"
                                     alt="<?php echo htmlspecialchars($img['img_title']); ?>">
                            </div>
                        <?php else: ?>
                            <div style="aspect-ratio:1; background:#111; display:flex; align-items:center; justify-content:center; color:#444; font-size:2rem;">
                                &#x1F4F7;
                            </div>
                        <?php endif; ?>

                        <div style="padding:8px;">
                            <div style="font-size:0.75rem; font-weight:700; line-height:1.3; overflow:hidden;
                                        display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">
                                <?php echo htmlspecialchars($img['img_title']); ?>
                            </div>
                            <div style="font-size:0.7rem; color:var(--text-muted,#666); margin-top:4px;">
                                <?php echo htmlspecialchars(substr($img['img_date'] ?? '', 0, 10)); ?>
                            </div>
                        </div>
                    </div>
                </label>
            <?php endforeach; ?>
        </div>

        <!-- PAGINATION -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= min($total_pages, 10); $i++): ?>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['p' => $i])); ?>"
                       class="<?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
                <?php if ($total_pages > 10): ?>
                    <span style="color:var(--text-muted,#666);">... <?php echo $total_pages; ?> pages</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </form>

    <?php endif; ?>
</div>

<script src="assets/js/ss-engine-crosspost.js"></script>

<?php include 'core/admin-footer.php'; ?>
// EOF
