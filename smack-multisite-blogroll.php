<?php
/**
 * SNAPSMACK - Multisite Blogroll Sync
 * Alpha v0.7.9
 *
 * Hub-only page. Two modes:
 *
 * PUSH — sends the hub's own blogroll to selected satellites. Each satellite
 *        receives entries in a dedicated "Hub: {domain}" category, replacing
 *        any previously synced entries from this hub.
 *
 * PULL — fetches each satellite's blogroll (read-only view on the hub).
 *        Allows the hub admin to discover what satellites are linking and
 *        optionally import entries into the hub's own blogroll.
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
// cURL helper
// ─────────────────────────────────────────────────────────────────────────────
function ms_blogroll_call(string $site_url, string $api_key, string $route, string $method = 'GET', array $post_data = []): ?array {
    $url = rtrim($site_url, '/') . '/api.php?route=' . $route;
    $ch  = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
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
// POST: PUSH hub blogroll to satellites
// ─────────────────────────────────────────────────────────────────────────────
$push_results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['push_blogroll'])) {
    $selected_sat_ids = array_map('intval', (array)($_POST['sat_ids'] ?? []));

    if (empty($selected_sat_ids)) {
        $err = "Select at least one satellite to push to.";
    } else {
        // Load hub's blogroll
        $hub_entries = $pdo->query("
            SELECT b.peer_name, b.peer_url, b.peer_rss, b.peer_desc
            FROM snap_blogroll b
            ORDER BY b.peer_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($hub_entries)) {
            $err = "Hub blogroll is empty — nothing to push.";
        } else {
            $entries_json = json_encode($hub_entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            foreach ($satellites as $sat) {
                if (!in_array($sat['id'], $selected_sat_ids)) continue;
                $result = ms_blogroll_call(
                    $sat['site_url'],
                    $sat['api_key_local'],
                    'multisite/blogroll/sync',
                    'POST',
                    ['hub_url' => BASE_URL, 'entries' => $entries_json]
                );
                $push_results[$sat['site_name']] = $result
                    ? ['ok' => true, 'inserted' => $result['inserted'] ?? 0, 'category' => $result['category'] ?? '']
                    : ['ok' => false, 'error' => 'Satellite unreachable or refused the sync.'];
            }
            $msg = "Push complete. " . count($push_results) . " satellite" . (count($push_results) !== 1 ? 's' : '') . " contacted.";
        }
    }
}

// POST: Import a satellite entry into the hub's own blogroll
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_entry'])) {
    $peer_name = trim($_POST['peer_name'] ?? '');
    $peer_url  = trim($_POST['peer_url']  ?? '');
    $peer_rss  = trim($_POST['peer_rss']  ?? '');
    $peer_desc = trim($_POST['peer_desc'] ?? '');
    $from_sat  = trim($_POST['from_sat']  ?? '');

    if ($peer_url && filter_var($peer_url, FILTER_VALIDATE_URL)) {
        // Find or create "Satellites" category for imports
        $cat_stmt = $pdo->prepare("SELECT id FROM snap_blogroll_cats WHERE cat_name = 'Satellites'");
        $cat_stmt->execute();
        $cat_id = $cat_stmt->fetchColumn();
        if (!$cat_id) {
            $pdo->prepare("INSERT INTO snap_blogroll_cats (cat_name) VALUES ('Satellites')")->execute();
            $cat_id = $pdo->lastInsertId();
        }

        // Check for duplicate
        $dup = $pdo->prepare("SELECT id FROM snap_blogroll WHERE peer_url = ?");
        $dup->execute([$peer_url]);
        if ($dup->fetchColumn()) {
            $msg = htmlspecialchars($peer_name) . " is already in your blogroll.";
        } else {
            $pdo->prepare("INSERT INTO snap_blogroll (peer_name, peer_url, peer_rss, peer_desc, cat_id) VALUES (?, ?, ?, ?, ?)")
                ->execute([$peer_name ?: parse_url($peer_url, PHP_URL_HOST), $peer_url, $peer_rss, $peer_desc, $cat_id]);
            $msg = "Added " . htmlspecialchars($peer_name ?: $peer_url) . " from " . htmlspecialchars($from_sat) . " to your blogroll.";
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PULL: Fetch satellite blogrolls (always, on every page load — used for display)
// ─────────────────────────────────────────────────────────────────────────────
$mode = $_GET['mode'] ?? 'push';   // 'push' or 'pull'

$satellite_blogrolls = [];
$fetch_errors        = [];

if ($mode === 'pull') {
    foreach ($satellites as $sat) {
        $result = ms_blogroll_call($sat['site_url'], $sat['api_key_local'], 'multisite/blogroll/list');
        if ($result) {
            $satellite_blogrolls[$sat['id']] = [
                'site_name' => $sat['site_name'],
                'site_url'  => $sat['site_url'],
                'entries'   => $result['entries'] ?? [],
            ];
        } else {
            $fetch_errors[] = $sat['site_name'];
        }
    }
}

// Hub's own blogroll (for push mode + de-dupe checking)
$hub_blogroll = $pdo->query("
    SELECT b.peer_name, b.peer_url, b.peer_rss, b.peer_desc,
           c.cat_name AS category
    FROM snap_blogroll b
    LEFT JOIN snap_blogroll_cats c ON b.cat_id = c.id
    ORDER BY c.cat_name ASC, b.peer_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$hub_urls = array_column($hub_blogroll, 'peer_url');

$page_title = "Blogroll Sync";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>BLOGROLL SYNC</h2>
        <div class="header-actions">
            <div class="status-pill status-online">
                <?php echo count($hub_blogroll); ?> HUB ENTRIES
            </div>
        </div>
    </div>

    <!-- QUICK NAV -->
    <div class="signal-control-header" style="margin-bottom:20px;">
        <div class="signal-nav-group">
            <a href="smack-multisite.php"              class="btn-clear">DASHBOARD</a>
            <a href="smack-multisite-comments.php"     class="btn-clear">SIGNALS</a>
            <a href="smack-multisite-posts.php"        class="btn-clear">POSTS</a>
            <a href="smack-multisite-backup.php"       class="btn-clear">BACKUP DOCK</a>
            <a href="smack-multisite-stats.php"        class="btn-clear">STATS</a>
            <a href="smack-multisite-crosspost.php"    class="btn-clear">CROSS-POST</a>
            <a href="smack-multisite-blogroll.php"     class="btn-clear active">BLOGROLL</a>
            <span class="sep">|</span>
            <a href="?mode=push" class="btn-clear <?php echo $mode === 'push' ? 'active' : ''; ?>">PUSH</a>
            <a href="?mode=pull" class="btn-clear <?php echo $mode === 'pull' ? 'active' : ''; ?>">PULL</a>
        </div>
    </div>

    <?php if (isset($msg)): ?>
        <div class="msg">> <?php echo $msg; ?></div>
    <?php endif; ?>
    <?php if (isset($err)): ?>
        <div class="alert alert-error">> <?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>
    <?php if (!empty($fetch_errors)): ?>
        <div class="alert alert-error">> OFFLINE SATELLITES: <?php echo htmlspecialchars(implode(', ', $fetch_errors)); ?></div>
    <?php endif; ?>

    <?php if (empty($satellites)): ?>
        <div class="box">
            <p style="color:var(--text-muted,#888);">No active satellites. <a href="smack-multisite.php" style="color:var(--accent,#aaa);">Register one first.</a></p>
        </div>

    <?php elseif ($mode === 'push'): ?>

        <!-- PUSH RESULTS -->
        <?php if (!empty($push_results)): ?>
            <div class="box">
                <h3>PUSH RESULTS</h3>
                <div style="display:flex; flex-wrap:wrap; gap:10px;">
                    <?php foreach ($push_results as $sat_name => $r): ?>
                        <div style="padding:10px 16px; border:1px solid <?php echo $r['ok'] ? '#4CAF50' : '#f44336'; ?>;
                                    border-radius:3px; font-size:0.85rem;">
                            <span style="color:<?php echo $r['ok'] ? '#4CAF50' : '#f44336'; ?>; margin-right:8px; font-weight:700;">
                                <?php echo $r['ok'] ? '&#x2713;' : '&#x2717;'; ?>
                            </span>
                            <?php echo htmlspecialchars($sat_name); ?>
                            <?php if ($r['ok']): ?>
                                <span style="color:var(--text-muted,#666); font-size:0.8rem;">
                                    — <?php echo (int)$r['inserted']; ?> entries in "<?php echo htmlspecialchars($r['category']); ?>"
                                </span>
                            <?php else: ?>
                                <span style="color:#f44336; font-size:0.8rem;"> — <?php echo htmlspecialchars($r['error']); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- PUSH FORM -->
        <div class="box">
            <h3>PUSH HUB BLOGROLL TO SATELLITES</h3>
            <p style="color:var(--text-muted,#888); font-size:0.9rem; margin-bottom:20px;">
                Sends all <?php echo count($hub_blogroll); ?> entries from the hub's blogroll to the selected satellites.
                On each satellite, entries are placed in a dedicated "Hub:" category and replace any previously
                synced entries from this hub. Satellites' own existing blogroll entries are untouched.
            </p>

            <form method="POST">
                <div style="margin-bottom:15px;">
                    <div style="font-size:0.75rem; color:var(--text-muted,#888); letter-spacing:1px; margin-bottom:10px;">TARGET SATELLITES</div>
                    <div style="display:flex; flex-wrap:wrap; gap:8px;">
                        <?php foreach ($satellites as $sat): ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer;
                                          padding:7px 14px; border:1px solid var(--border,#333);
                                          border-radius:3px; font-size:0.85rem;">
                                <input type="checkbox" name="sat_ids[]" value="<?php echo $sat['id']; ?>"
                                       class="tactical-checkbox" style="margin:0;" checked>
                                <?php echo htmlspecialchars($sat['site_name']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (empty($hub_blogroll)): ?>
                    <p style="color:#f44336; font-size:0.9rem;">Hub blogroll is empty. <a href="smack-blogroll.php" style="color:var(--accent,#aaa);">Add entries first.</a></p>
                <?php else: ?>
                    <button type="submit" name="push_blogroll" value="1" class="master-update-btn"
                            onclick="return confirm('Push <?php echo count($hub_blogroll); ?> entries to selected satellites?');">
                        PUSH <?php echo count($hub_blogroll); ?> ENTRIES
                    </button>
                <?php endif; ?>
            </form>
        </div>

        <!-- HUB BLOGROLL PREVIEW -->
        <?php if (!empty($hub_blogroll)): ?>
        <div class="box">
            <h3>HUB BLOGROLL PREVIEW</h3>
            <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border,#333);">
                        <th style="text-align:left; padding:8px; color:var(--text-muted,#888);">NAME</th>
                        <th style="text-align:left; padding:8px; color:var(--text-muted,#888);">URL</th>
                        <th style="text-align:left; padding:8px; color:var(--text-muted,#888);">CATEGORY</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hub_blogroll as $entry): ?>
                        <tr style="border-bottom:1px solid var(--border,#222);">
                            <td style="padding:8px;"><?php echo htmlspecialchars($entry['peer_name']); ?></td>
                            <td style="padding:8px; color:var(--text-muted,#888);">
                                <a href="<?php echo htmlspecialchars($entry['peer_url']); ?>" target="_blank"
                                   style="color:inherit;"><?php echo htmlspecialchars(preg_replace('~^https?://~i', '', $entry['peer_url'])); ?></a>
                            </td>
                            <td style="padding:8px; color:var(--text-muted,#666); font-size:0.8rem;">
                                <?php echo htmlspecialchars($entry['category'] ?? '—'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    <?php else: // mode === 'pull' ?>

        <!-- PULL: Per-satellite blogroll view with import buttons -->
        <?php if (empty($satellite_blogrolls) && empty($fetch_errors)): ?>
            <div class="box"><p style="color:var(--text-muted,#888);">No blogroll data returned from any satellite.</p></div>
        <?php endif; ?>

        <?php foreach ($satellite_blogrolls as $node_id => $sat_data): ?>
        <div class="box">
            <h3>
                <?php echo htmlspecialchars(strtoupper($sat_data['site_name'])); ?>
                <span style="font-weight:400; font-size:0.8rem; color:var(--text-muted,#666);">
                    &mdash; <?php echo count($sat_data['entries']); ?> ENTRIES
                </span>
            </h3>

            <?php if (empty($sat_data['entries'])): ?>
                <p style="color:var(--text-muted,#666); font-size:0.85rem;">No blogroll entries on this satellite.</p>
            <?php else: ?>
                <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--border,#333);">
                            <th style="text-align:left; padding:8px; color:var(--text-muted,#888);">NAME</th>
                            <th style="text-align:left; padding:8px; color:var(--text-muted,#888);">URL</th>
                            <th style="text-align:left; padding:8px; color:var(--text-muted,#888);">CAT</th>
                            <th style="text-align:center; padding:8px; color:var(--text-muted,#888);">ADD TO HUB</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sat_data['entries'] as $entry):
                            $already_have = in_array($entry['peer_url'], $hub_urls);
                        ?>
                            <tr style="border-bottom:1px solid var(--border,#222);">
                                <td style="padding:8px;"><?php echo htmlspecialchars($entry['peer_name']); ?></td>
                                <td style="padding:8px; color:var(--text-muted,#888); font-size:0.8rem;">
                                    <a href="<?php echo htmlspecialchars($entry['peer_url']); ?>" target="_blank"
                                       style="color:inherit;"><?php echo htmlspecialchars(preg_replace('~^https?://~i', '', $entry['peer_url'])); ?></a>
                                </td>
                                <td style="padding:8px; color:var(--text-muted,#666); font-size:0.75rem;">
                                    <?php echo htmlspecialchars($entry['category'] ?? '—'); ?>
                                </td>
                                <td style="padding:8px; text-align:center;">
                                    <?php if ($already_have): ?>
                                        <span style="color:#4CAF50; font-size:0.75rem;">&#x2713; HAVE IT</span>
                                    <?php else: ?>
                                        <form method="POST" style="margin:0; display:inline;">
                                            <input type="hidden" name="peer_name" value="<?php echo htmlspecialchars($entry['peer_name']); ?>">
                                            <input type="hidden" name="peer_url"  value="<?php echo htmlspecialchars($entry['peer_url']); ?>">
                                            <input type="hidden" name="peer_rss"  value="<?php echo htmlspecialchars($entry['peer_rss'] ?? ''); ?>">
                                            <input type="hidden" name="peer_desc" value="<?php echo htmlspecialchars($entry['peer_desc'] ?? ''); ?>">
                                            <input type="hidden" name="from_sat"  value="<?php echo htmlspecialchars($sat_data['site_name']); ?>">
                                            <input type="hidden" name="mode"      value="pull">
                                            <button type="submit" name="import_entry" value="1" class="action-authorize"
                                                    style="font-size:0.75rem; padding:3px 10px;">IMPORT</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

    <?php endif; ?>

</div>

<?php include 'core/admin-footer.php'; ?>
