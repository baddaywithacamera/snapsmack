<?php
/**
 * SNAPSMACK - Multisite Blogroll Sync
 *
 * Hub-only page. Two modes:
 *
 * PUSH — sends the hub's own blogroll to selected spokes. Each spoke
 *        receives entries in a dedicated "Hub: {domain}" category, replacing
 *        any previously synced entries from this hub.
 *
 * PULL — fetches each spoke's blogroll (read-only view on the hub).
 *        Allows the hub admin to discover what spokes are linking and
 *        optionally import entries into the hub's own blogroll.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
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
// cURL helper
// ─────────────────────────────────────────────────────────────────────────────
// --- MY BLOGS SETTINGS ---
$my_blogs_enabled = ($settings['blogroll_my_blogs_enabled'] ?? '0') === '1';
$my_blogs_cat     = $settings['blogroll_my_blogs_cat'] ?? 'My Blogs';

// Spokes with tagline + per-node blogroll_desc overrides (for My Blogs entries)
$spoke_nodes = [];
try {
    $spoke_nodes = $pdo->query("
        SELECT id, site_name, site_url, site_tagline, blogroll_desc
        FROM snap_multisite_nodes
        WHERE role = 'spoke' AND status = 'active'
        ORDER BY site_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Migration 059 not yet applied -- site_tagline/blogroll_desc columns missing
    $spoke_nodes = $pdo->query("
        SELECT id, site_name, site_url, NULL AS site_tagline, NULL AS blogroll_desc
        FROM snap_multisite_nodes
        WHERE role = 'spoke' AND status = 'active'
        ORDER BY site_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

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
// POST: PUSH hub blogroll to spokes
// ─────────────────────────────────────────────────────────────────────────────
$push_results = [];

// POST: Save My Blogs settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_my_blogs_settings'])) {
    $new_enabled = isset($_POST['my_blogs_enabled']) ? '1' : '0';
    $new_cat     = trim($_POST['my_blogs_cat'] ?? 'My Blogs') ?: 'My Blogs';
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('blogroll_my_blogs_enabled', ?)
                    ON DUPLICATE KEY UPDATE setting_val = ?")->execute([$new_enabled, $new_enabled]);
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('blogroll_my_blogs_cat', ?)
                    ON DUPLICATE KEY UPDATE setting_val = ?")->execute([$new_cat, $new_cat]);
    // Save per-spoke blogroll_desc overrides
    if (isset($_POST['spoke_blogroll_desc']) && is_array($_POST['spoke_blogroll_desc'])) {
        foreach ($_POST['spoke_blogroll_desc'] as $node_id => $desc) {
            $node_id = (int)$node_id;
            $desc    = trim($desc);
            $pdo->prepare("UPDATE snap_multisite_nodes SET blogroll_desc = ? WHERE id = ?")->execute([$desc ?: null, $node_id]);
        }
    }
    $my_blogs_enabled = $new_enabled === '1';
    $my_blogs_cat     = $new_cat;
    $msg = "My Blogs settings saved.";
    // Reload spoke nodes with updated descs
    try {
        $spoke_nodes = $pdo->query("SELECT id, site_name, site_url, site_tagline, blogroll_desc FROM snap_multisite_nodes WHERE role = 'spoke' AND status = 'active' ORDER BY site_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* ignore if columns missing */ }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['push_blogroll'])) {
    $selected_spoke_ids = array_map('intval', (array)($_POST['spoke_ids'] ?? []));

    if (empty($selected_spoke_ids)) {
        $err = "Select at least one spoke to push to.";
    } else {
        // Load hub's blogroll, including category name per entry so the
        // spoke can preserve the hub's category structure instead of dumping
        // everything into one bucket.
        $hub_entries = $pdo->query("
            SELECT b.peer_name, b.peer_url, b.peer_rss, b.peer_desc,
                   c.cat_name AS category
            FROM snap_blogroll b
            LEFT JOIN snap_blogroll_cats c ON b.cat_id = c.id
            ORDER BY c.cat_name ASC, b.peer_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Prepend My Blogs entries (hub-network spokes) when enabled
        if ($my_blogs_enabled && !empty($spoke_nodes)) {
            $my_blog_entries = [];
            foreach ($spoke_nodes as $sn) {
                $desc = trim($sn['blogroll_desc'] ?? '');
                if ($desc === '') $desc = trim($sn['site_tagline'] ?? '');
                $my_blog_entries[] = [
                    'peer_name' => $sn['site_name'],
                    'peer_url'  => rtrim($sn['site_url'], '/') . '/',
                    'peer_rss'  => '',
                    'peer_desc' => $desc,
                    'category'  => $my_blogs_cat,
                ];
            }
            // Also include the hub itself — spokes should see all network sites
            $hub_self_url = rtrim($settings['site_url'] ?? '', '/') . '/';
            $hub_self_name = trim($settings['site_name'] ?? '');
            if ($hub_self_url !== '/' && $hub_self_name !== '') {
                $my_blog_entries[] = [
                    'peer_name' => $hub_self_name,
                    'peer_url'  => $hub_self_url,
                    'peer_rss'  => '',
                    'peer_desc' => trim($settings['site_tagline'] ?? ''),
                    'category'  => $my_blogs_cat,
                ];
            }
            usort($my_blog_entries, fn($a, $b) => strcasecmp($a['peer_name'], $b['peer_name']));
            $existing_urls = array_map('strtolower', array_column($hub_entries, 'peer_url'));
            foreach ($my_blog_entries as $mbe) {
                if (!in_array(strtolower($mbe['peer_url']), $existing_urls)) {
                    array_unshift($hub_entries, $mbe);
                }
            }
        }

        if (empty($hub_entries)) {
            $err = "Hub blogroll is empty — nothing to push.";
        } else {
            foreach ($spokes as $spoke) {
                if (!in_array($spoke['id'], $selected_spoke_ids)) continue;

                // Build per-spoke entries: exclude the receiving spoke's own URL
                // from the My Blogs category so it doesn't link to itself.
                $spoke_url_norm = strtolower(rtrim($spoke['site_url'], '/'));
                $spoke_entries  = array_filter($hub_entries, function ($e) use ($spoke_url_norm) {
                    return strtolower(rtrim($e['peer_url'], '/')) !== $spoke_url_norm;
                });
                $entries_json = json_encode(array_values($spoke_entries), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $result = ms_blogroll_call(
                    $spoke['site_url'],
                    $spoke['api_key_local'],
                    'multisite/blogroll/sync',
                    'POST',
                    ['hub_url' => BASE_URL, 'entries' => $entries_json]
                );
                $push_results[$spoke['site_name']] = $result
                    ? ['ok' => true, 'inserted' => $result['inserted'] ?? 0, 'category' => $result['category'] ?? '']
                    : ['ok' => false, 'error' => 'Spoke unreachable or refused the sync.'];
            }
            $msg = "Push complete. " . count($push_results) . " spoke" . (count($push_results) !== 1 ? 's' : '') . " contacted.";
        }
    }
}

// POST: Import a spoke entry into the hub's own blogroll
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_entry'])) {
    $peer_name = trim($_POST['peer_name'] ?? '');
    $peer_url  = trim($_POST['peer_url']  ?? '');
    $peer_rss  = trim($_POST['peer_rss']  ?? '');
    $peer_desc = trim($_POST['peer_desc'] ?? '');
    $from_sat  = trim($_POST['from_sat']  ?? '');

    if ($peer_url && filter_var($peer_url, FILTER_VALIDATE_URL)) {
        // Find or create "Spokes" category for imports
        $cat_stmt = $pdo->prepare("SELECT id FROM snap_blogroll_cats WHERE cat_name = 'Spokes'");
        $cat_stmt->execute();
        $cat_id = $cat_stmt->fetchColumn();
        if (!$cat_id) {
            $pdo->prepare("INSERT INTO snap_blogroll_cats (cat_name) VALUES ('Spokes')")->execute();
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
// PULL: Fetch spoke blogrolls (always, on every page load — used for display)
// ─────────────────────────────────────────────────────────────────────────────
$mode = $_GET['mode'] ?? 'push';   // 'push' or 'pull'

$spoke_blogrolls = [];
$fetch_errors        = [];

if ($mode === 'pull') {
    foreach ($spokes as $spoke) {
        $result = ms_blogroll_call($spoke['site_url'], $spoke['api_key_local'], 'multisite/blogroll/list');
        if ($result) {
            $spoke_blogrolls[$spoke['id']] = [
                'site_name' => $spoke['site_name'],
                'site_url'  => $spoke['site_url'],
                'entries'   => $result['entries'] ?? [],
            ];
        } else {
            $fetch_errors[] = $spoke['site_name'];
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
        <div class="alert alert-error">> OFFLINE SPOKES: <?php echo htmlspecialchars(implode(', ', $fetch_errors)); ?></div>
    <?php endif; ?>

    <?php if (empty($spokes)): ?>
        <div class="box">
            <p style="color:var(--text-muted,#888);">No active spokes. <a href="smack-multisite.php" style="color:var(--accent,#aaa);">Register one first.</a></p>
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
            <h3>PUSH HUB BLOGROLL TO SPOKES</h3>
            <p style="color:var(--text-muted,#888); font-size:0.9rem; margin-bottom:20px;">
                Sends all <?php echo count($hub_blogroll); ?> entries from the hub's blogroll to the selected spokes.
                On each spoke, entries are placed in a dedicated "Hub:" category and replace any previously
                synced entries from this hub. Spokes' own existing blogroll entries are untouched.
            </p>

            <form method="POST">
                <div style="margin-bottom:15px;">
                    <div style="font-size:0.75rem; color:var(--text-muted,#888); letter-spacing:1px; margin-bottom:10px;">TARGET SPOKES</div>
                    <div style="display:flex; flex-wrap:wrap; gap:8px;">
                        <?php foreach ($spokes as $spoke): ?>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer;
                                          padding:7px 14px; border:1px solid var(--border,#333);
                                          border-radius:3px; font-size:0.85rem;">
                                <input type="checkbox" name="spoke_ids[]" value="<?php echo $spoke['id']; ?>"
                                       class="tactical-checkbox" style="margin:0;" checked>
                                <?php echo htmlspecialchars($spoke['site_name']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if (empty($hub_blogroll)): ?>
                    <p style="color:#f44336; font-size:0.9rem;">Hub blogroll is empty. <a href="smack-blogroll.php" style="color:var(--accent,#aaa);">Add entries first.</a></p>
                <?php else: ?>
                    <button type="submit" name="push_blogroll" value="1" class="master-update-btn"
                            onclick="return confirm('Push <?php echo count($hub_blogroll); ?> entries to selected spokes?');">
                        PUSH <?php echo count($hub_blogroll); ?> ENTRIES
                    </button>
                <?php endif; ?>
            </form>
        </div>

        <!-- MY BLOGS SETTINGS -->
        <div class="box">
            <h3>MY BLOGS</h3>
            <p style="color:var(--text-muted,#888); font-size:0.9rem; margin-bottom:20px;">
                When enabled, a category containing all hub-network spokes is automatically
                prepended to every blogroll push. Each entry uses the spoke's site tagline
                as its description by default. Hub-only &mdash; spokes cannot configure this.
            </p>
            <form method="POST">
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.85rem;">
                        <input type="checkbox" name="my_blogs_enabled" value="1"
                               <?php echo $my_blogs_enabled ? 'checked' : ''; ?>>
                        Enable My Blogs category in push
                    </label>
                </div>
                <div style="margin-bottom:20px;">
                    <label style="font-size:0.75rem; letter-spacing:1px; color:var(--text-muted,#888); display:block; margin-bottom:6px;">
                        CATEGORY NAME
                    </label>
                    <input type="text" name="my_blogs_cat"
                           value="<?php echo htmlspecialchars($my_blogs_cat); ?>"
                           style="max-width:300px;"
                           placeholder="My Blogs">
                </div>
                <?php if (!empty($spoke_nodes)): ?>
                <div style="margin-bottom:20px;">
                    <div style="font-size:0.75rem; letter-spacing:1px; color:var(--text-muted,#888); margin-bottom:10px;">
                        DESCRIPTION OVERRIDES <span style="font-weight:400; color:var(--text-muted,#666);">(leave blank to use site tagline)</span>
                    </div>
                    <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                        <thead>
                            <tr style="border-bottom:1px solid var(--border,#333);">
                                <th style="text-align:left; padding:6px 8px; color:var(--text-muted,#888); width:220px;">SPOKE</th>
                                <th style="text-align:left; padding:6px 8px; color:var(--text-muted,#888);">TAGLINE (CURRENT)</th>
                                <th style="text-align:left; padding:6px 8px; color:var(--text-muted,#888);">CUSTOM DESC</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($spoke_nodes as $sn): ?>
                            <tr style="border-bottom:1px solid var(--border,#222);">
                                <td style="padding:6px 8px;"><?php echo htmlspecialchars($sn['site_name']); ?></td>
                                <td style="padding:6px 8px; color:var(--text-muted,#666); font-size:0.8rem;">
                                    <?php echo htmlspecialchars($sn['site_tagline'] ?? ''); ?>
                                </td>
                                <td style="padding:6px 8px;">
                                    <input type="text" name="spoke_blogroll_desc[<?php echo $sn['id']; ?>]"
                                           value="<?php echo htmlspecialchars($sn['blogroll_desc'] ?? ''); ?>"
                                           placeholder="<?php echo htmlspecialchars($sn['site_tagline'] ?? ''); ?>"
                                           style="width:100%;">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <button type="submit" name="save_my_blogs_settings" value="1" class="btn-smack">
                    SAVE MY BLOGS SETTINGS
                </button>
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

        <!-- PULL: Per-spoke blogroll view with import buttons -->
        <?php if (empty($spoke_blogrolls) && empty($fetch_errors)): ?>
            <div class="box"><p style="color:var(--text-muted,#888);">No blogroll data returned from any spoke.</p></div>
        <?php endif; ?>

        <?php foreach ($spoke_blogrolls as $node_id => $spoke_data): ?>
        <div class="box">
            <h3>
                <?php echo htmlspecialchars(strtoupper($spoke_data['site_name'])); ?>
                <span style="font-weight:400; font-size:0.8rem; color:var(--text-muted,#666);">
                    &mdash; <?php echo count($spoke_data['entries']); ?> ENTRIES
                </span>
            </h3>

            <?php if (empty($spoke_data['entries'])): ?>
                <p style="color:var(--text-muted,#666); font-size:0.85rem;">No blogroll entries on this spoke.</p>
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
                        <?php foreach ($spoke_data['entries'] as $entry):
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
                                            <input type="hidden" name="from_sat"  value="<?php echo htmlspecialchars($spoke_data['site_name']); ?>">
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
<?php // ===== SNAPSMACK EOF =====
