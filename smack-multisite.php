<?php
/**
 * SNAPSMACK - Multisite Management
 *
 * Hub and spoke site management interface. Allows admins to set up
 * multi-site configurations, register new spokes, and monitor their status.
 */

require_once 'core/auth.php';

// --- FORM SUBMISSION HANDLERS ---

// Enable as Hub
if (isset($_POST['enable_hub'])) {
    $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
    $stmt->execute(['multisite_role', 'hub', 'hub']);
    $msg = "Enabled as Hub. You can now register spoke sites.";
}

// Connect to Hub (spoke mode)
if (isset($_POST['enable_spoke'])) {
    $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
    $stmt->execute(['multisite_role', 'spoke', 'spoke']);
    $msg = "Enabled as Spoke. You can now generate a registration token.";
}

// Generate registration token
if (isset($_POST['gen_reg_token'])) {
    $token = bin2hex(random_bytes(16)); // 32-char hex
    $expires = time() + 900; // 15 minutes

    $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
    $stmt->execute(['multisite_reg_token', $token, $token]);
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?")
        ->execute(['multisite_reg_token_expires', $expires, $expires]);

    $_SESSION['multisite_reg_token'] = $token;
    $_SESSION['multisite_reg_token_expires'] = $expires;
    $msg = "Registration token generated. Valid for 15 minutes.";
}

// Register spoke (hub mode)
if (isset($_POST['register_spoke'])) {
    $spoke_url = trim($_POST['spoke_url'] ?? '');
    $spoke_token = trim($_POST['spoke_token'] ?? '');
    $spoke_name = trim($_POST['spoke_name'] ?? '');

    if (empty($spoke_url) || empty($spoke_token)) {
        $err = "Spoke URL and registration token are required.";
    } else {
        // Normalize URL
        if (!preg_match('~^https?://~i', $spoke_url)) {
            $spoke_url = 'https://' . $spoke_url;
        }
        $spoke_url = rtrim($spoke_url, '/');

        // Call the spoke's handshake endpoint
        $handshake_data = [
            'site_url' => BASE_URL,
            'site_name' => $settings['site_name'] ?? 'SnapSmack Hub',
            'token' => $spoke_token
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $spoke_url . '/api.php?route=multisite/handshake',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($handshake_data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if (!$response || $http_code !== 200) {
            $err = "Failed to contact spoke: " . ($curl_err ?: "HTTP $http_code");
        } else {
            $response_data = json_decode($response, true);

            if (empty($response_data['ok']) || empty($response_data['api_key'])) {
                $err = "Handshake failed: " . ($response_data['error'] ?? 'Unknown error');
            } else {
                $api_key_remote = $response_data['api_key'];

                // Store the spoke
                $stmt = $pdo->prepare("
                    INSERT INTO snap_multisite_nodes
                    (role, site_url, site_name, api_key_local, api_key_remote, status, connected_at)
                    VALUES (?, ?, ?, ?, ?, 'active', NOW())
                    ON DUPLICATE KEY UPDATE
                        role           = VALUES(role),
                        api_key_local  = VALUES(api_key_local),
                        api_key_remote = VALUES(api_key_remote),
                        status         = 'active',
                        site_name      = VALUES(site_name),
                        connected_at   = NOW()
                ");

                try {
                    $api_key_local = bin2hex(random_bytes(32));
                    $stmt->execute(['spoke', $spoke_url, $spoke_name, $api_key_local, $api_key_remote]);
                    $msg = "Spoke registered successfully: {$spoke_name}";
                } catch (PDOException $e) {
                    $err = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

// Disconnect spoke
if (isset($_GET['disconnect'])) {
    $node_id = (int)$_GET['disconnect'];

    // Get the spoke info
    $stmt = $pdo->prepare("SELECT site_url, api_key_local FROM snap_multisite_nodes WHERE id = ? AND role = 'spoke'");
    $stmt->execute([$node_id]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($node) {
        // Notify spoke to disconnect
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $node['site_url'] . '/api.php?route=multisite/disconnect',
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $node['api_key_local'],
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Update status
        $pdo->prepare("UPDATE snap_multisite_nodes SET status = 'disconnected' WHERE id = ?")->execute([$node_id]);
        $msg = "Spoke disconnected.";
    }
}

// Disconnect from hub
if (isset($_POST['disconnect_hub'])) {
    $pdo->prepare("DELETE FROM snap_multisite_nodes WHERE role = 'hub' LIMIT 1")->execute();
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?")
        ->execute(['multisite_reg_token', '', '']);
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?")
        ->execute(['multisite_reg_token_expires', '0', '0']);
    $msg = "Disconnected from hub.";
}

// --- VERIFY CONNECTION (spoke → hub heartbeat) ---
if (isset($_POST['verify_hub'])) {
    $hub = $pdo->query("SELECT * FROM snap_multisite_nodes WHERE role = 'hub' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($hub) {
        $url = rtrim($hub['site_url'], '/') . '/api.php?route=multisite/heartbeat';
        $ch  = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $hub['api_key_remote'],
                'Accept: application/json',
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw && $code === 200) {
            $hb = json_decode($raw, true);
            if (!empty($hb['ok'])) {
                $pdo->prepare("UPDATE snap_multisite_nodes SET last_seen_at = NOW(), status = 'active' WHERE id = ?")
                    ->execute([$hub['id']]);
                $msg = "Connection verified — hub responded OK (version " . htmlspecialchars($hb['version'] ?? 'unknown') . ").";
            } else {
                $msg = "Hub responded but returned an error: " . htmlspecialchars($hb['error'] ?? 'unknown');
            }
        } else {
            $msg = "Could not reach hub — HTTP {$code}" . ($err ? " ({$err})" : "") . ".";
        }
    } else {
        $msg = "No hub configured.";
    }
}

// --- DATA LOADING ---
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$multisite_role = $settings['multisite_role'] ?? '';

// Load connected nodes
$nodes = $pdo->query("SELECT * FROM snap_multisite_nodes ORDER BY role ASC, connected_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// --- HEARTBEAT SWEEP (hub only, once per page load) ---
// Calls each active spoke's heartbeat endpoint and caches the stats locally.
if ($multisite_role === 'hub') {
    foreach ($nodes as &$n) {
        if ($n['role'] !== 'spoke' || $n['status'] === 'disconnected') continue;

        $url = rtrim($n['site_url'], '/') . '/api.php?route=multisite/heartbeat';
        $hb_ch = curl_init();
        curl_setopt_array($hb_ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $n['api_key_local'],
                'Accept: application/json',
            ],
        ]);
        $hb_raw  = curl_exec($hb_ch);
        $hb_code = curl_getinfo($hb_ch, CURLINFO_HTTP_CODE);
        curl_close($hb_ch);

        if ($hb_raw && $hb_code === 200) {
            $hb = json_decode($hb_raw, true);
            if (!empty($hb['ok'])) {
                $pdo->prepare("
                    UPDATE snap_multisite_nodes SET
                        software_version   = ?,
                        post_count         = ?,
                        image_count        = ?,
                        pending_comments   = ?,
                        last_backup_at     = ?,
                        last_backup_size   = ?,
                        last_backup_dest   = ?,
                        last_backup_status = ?,
                        disk_usage_bytes   = ?,
                        last_seen_at       = NOW(),
                        status             = 'active'
                    WHERE id = ?
                ")->execute([
                    $hb['version']            ?? null,
                    $hb['post_count']         ?? 0,
                    $hb['image_count']        ?? 0,
                    $hb['pending_comments']   ?? 0,
                    $hb['last_backup_at']     ?? null,
                    $hb['last_backup_size']   ?? null,
                    $hb['last_backup_dest']   ?? null,
                    $hb['last_backup_status'] ?? 'unknown',
                    $hb['disk_usage_bytes']   ?? null,
                    $n['id'],
                ]);
                // Update local array so the table renders fresh data without a reload
                $n['software_version']   = $hb['version']            ?? $n['software_version'];
                $n['post_count']         = $hb['post_count']         ?? $n['post_count'];
                $n['image_count']        = $hb['image_count']        ?? $n['image_count'];
                $n['pending_comments']   = $hb['pending_comments']   ?? $n['pending_comments'];
                $n['last_backup_status'] = $hb['last_backup_status'] ?? $n['last_backup_status'];
            }
        } else {
            // Mark offline if unreachable
            $pdo->prepare("UPDATE snap_multisite_nodes SET status = 'offline' WHERE id = ?")->execute([$n['id']]);
            $n['status'] = 'offline';
        }
    }
    unset($n);

    // Reload nodes so status changes are reflected
    $nodes = $pdo->query("SELECT * FROM snap_multisite_nodes ORDER BY role ASC, connected_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}

// Get registration token if it exists and is still valid (read from DB, not session)
$reg_token         = $settings['multisite_reg_token']         ?? '';
$reg_token_expires = (int)($settings['multisite_reg_token_expires'] ?? 0);
$reg_token_valid   = $reg_token && time() < $reg_token_expires;

$page_title = "Multisite Management";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>MULTISITE MANAGEMENT</h2>
    </div>

    <?php if(isset($msg)): ?>
        <div class="msg">> <?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <?php if(isset($err)): ?>
        <div class="alert alert-error">> <?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <?php if (empty($multisite_role)): ?>
        <!-- INITIAL CHOICE: Neither hub nor spoke -->
        <div class="box">
            <h3>ENABLE MULTISITE MANAGEMENT</h3>
            <p style="margin-bottom:20px; color:var(--text-muted,#888);">
                This installation can operate as a Hub (manage multiple sites) or as a Spoke (managed by a hub).
                Choose your role to get started.
            </p>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
                <div style="padding:20px; border:1px solid var(--border,#333); background:var(--input-bg,#111);">
                    <h4>HUB MODE</h4>
                    <p style="font-size:0.9rem; color:var(--text-muted,#888); margin-bottom:15px;">
                        Manage and monitor multiple SnapSmack installations from a central dashboard.
                    </p>
                    <form method="POST" style="margin:0;">
                        <button type="submit" name="enable_hub" class="master-update-btn" style="width:100%;">
                            ENABLE AS HUB
                        </button>
                    </form>
                </div>

                <div style="padding:20px; border:1px solid var(--border,#333); background:var(--input-bg,#111);">
                    <h4>SPOKE MODE</h4>
                    <p style="font-size:0.9rem; color:var(--text-muted,#888); margin-bottom:15px;">
                        Connect this site to a central hub for remote monitoring and management.
                    </p>
                    <form method="POST" style="margin:0;">
                        <button type="submit" name="enable_spoke" class="master-update-btn" style="width:100%;">
                            ENABLE AS SPOKE
                        </button>
                    </form>
                </div>
            </div>
        </div>

    <?php elseif ($multisite_role === 'hub'): ?>

        <!-- HUB QUICK NAV -->
        <?php
            $total_pending_fleet = array_sum(array_column(
                array_filter($nodes, fn($n) => $n['role'] === 'spoke' && $n['status'] === 'active'),
                'pending_comments'
            ));
        ?>
        <div class="signal-control-header" style="margin-bottom:20px;">
            <div class="signal-nav-group">
                <a href="smack-multisite.php"          class="btn-clear active">DASHBOARD</a>
                <a href="smack-multisite-comments.php" class="btn-clear">
                    SIGNALS<?php echo $total_pending_fleet > 0 ? ' (' . $total_pending_fleet . ')' : ''; ?>
                </a>
                <a href="smack-multisite-posts.php"    class="btn-clear">POSTS</a>
                <a href="smack-multisite-backup.php"      class="btn-clear">BACKUP DOCK</a>
                <a href="smack-multisite-stats.php"       class="btn-clear">STATS</a>
                <a href="smack-multisite-crosspost.php"   class="btn-clear">CROSS-POST</a>
                <a href="smack-multisite-blogroll.php"    class="btn-clear">BLOGROLL</a>
            </div>
        </div>

        <!-- HUB MODE: Manage spokes -->
        <div class="box">
            <h3>CONNECTED SPOKES</h3>

            <?php if (empty($nodes) || count(array_filter($nodes, fn($n) => $n['role'] === 'spoke')) === 0): ?>
                <p style="color:var(--text-muted,#888);">No spokes connected yet. Register one below.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="multisite-table">
                        <thead>
                            <tr>
                                <th>NAME</th>
                                <th>URL</th>
                                <th class="col-center">VERSION</th>
                                <th class="col-center">STATUS</th>
                                <th class="col-center">LAST SEEN</th>
                                <th class="col-center">POSTS</th>
                                <th class="col-center">PENDING</th>
                                <th class="col-center">BACKUP</th>
                                <th class="col-center">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($nodes as $n): ?>
                                <?php if ($n['role'] !== 'spoke') continue; ?>
                                <?php $node_status   = $n['status'] ?? 'unknown'; ?>
                                <?php $backup_status = $n['last_backup_status'] ?? 'unknown'; ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($n['site_name'] ?? 'Unknown'); ?></strong></td>
                                    <td>
                                        <a href="<?php echo htmlspecialchars($n['site_url']); ?>" target="_blank">
                                            <?php echo htmlspecialchars(preg_replace('~^https?://~i', '', $n['site_url'])); ?>
                                        </a>
                                    </td>
                                    <td class="col-center" style="font-family:monospace; font-size:0.85rem;">
                                        <?php
                                            $spoke_ver = $n['software_version'] ?? '';
                                            echo $spoke_ver ? htmlspecialchars($spoke_ver) : '—';
                                            if ($spoke_ver && defined('SNAPSMACK_VERSION') && $spoke_ver !== SNAPSMACK_VERSION) {
                                                echo ' <span class="version-behind" title="Behind hub version ' . htmlspecialchars(SNAPSMACK_VERSION) . '">&#x25B2;</span>';
                                            }
                                        ?>
                                    </td>
                                    <td class="col-center">
                                        <span class="status-dot status-dot--<?php echo htmlspecialchars($node_status); ?>" title="<?php echo htmlspecialchars($node_status); ?>"></span>
                                        <span class="status-label status-label--<?php echo htmlspecialchars($node_status); ?>"><?php echo strtoupper($node_status); ?></span>
                                    </td>
                                    <td class="col-center">
                                        <?php
                                            if ($n['last_seen_at']) {
                                                $last_seen = strtotime($n['last_seen_at']);
                                                $diff = time() - $last_seen;
                                                if ($diff < 60) {
                                                    echo "now";
                                                } elseif ($diff < 3600) {
                                                    echo floor($diff / 60) . "m ago";
                                                } elseif ($diff < 86400) {
                                                    echo floor($diff / 3600) . "h ago";
                                                } else {
                                                    echo floor($diff / 86400) . "d ago";
                                                }
                                            } else {
                                                echo "never";
                                            }
                                        ?>
                                    </td>
                                    <td class="col-center"><?php echo (int)$n['post_count']; ?></td>
                                    <td class="col-center"><?php echo (int)$n['pending_comments']; ?></td>
                                    <td class="col-center">
                                        <span class="status-dot status-dot--lg status-dot--<?php echo htmlspecialchars($backup_status); ?>" title="<?php echo htmlspecialchars($backup_status); ?>"></span>
                                    </td>
                                    <td class="col-center">
                                        <?php if ($n['status'] === 'active'): ?>
                                            <a href="smack-multisite-sso.php?spoke=<?php echo $n['id']; ?>"
                                               target="_blank"
                                               class="action-authorize"
                                               title="Open spoke admin as primary admin user">REMOTE LOGIN</a>
                                        <?php endif; ?>
                                        <a href="?disconnect=<?php echo $n['id']; ?>" class="action-delete" onclick="return confirm('Disconnect this spoke?');">DISCONNECT</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- REGISTER NEW SPOKE -->
        <div class="box">
            <h3>REGISTER NEW SPOKE</h3>
            <form method="POST">
                <label>SPOKE NAME</label>
                <input type="text" name="spoke_name" placeholder="e.g., Travel Blog" required>

                <label>SPOKE URL</label>
                <input type="url" name="spoke_url" placeholder="https://example.com" required>

                <label>REGISTRATION TOKEN</label>
                <input type="text" name="spoke_token" placeholder="Obtained from the spoke's dashboard" required>

                <p style="font-size:0.85rem; color:var(--text-muted,#888); margin:10px 0;">
                    Ask the spoke admin to generate a token at their Multisite Management page and give it to you.
                </p>

                <button type="submit" name="register_spoke" class="master-update-btn">REGISTER SPOKE</button>
            </form>
        </div>

    <?php elseif ($multisite_role === 'spoke'): ?>
        <!-- SPOKE MODE: Connected to a hub -->

        <?php $hub_node = array_filter($nodes, fn($n) => $n['role'] === 'hub'); ?>

        <div class="box">
            <h3>HUB CONNECTION STATUS</h3>

            <?php if (empty($hub_node)): ?>
                <p style="color:var(--text-muted,#888); margin-bottom:20px;">
                    Not connected to a hub. Generate a registration token below and share it with your hub administrator.
                </p>

                <?php if ($reg_token_valid): ?>
                    <div style="padding:15px; background:var(--input-bg,#111); border:1px solid var(--border,#333); border-radius:4px; margin-bottom:20px;">
                        <p style="font-size:0.85rem; color:var(--text-muted,#888); margin-bottom:10px;">
                            <strong>Active Registration Token (valid for <?php echo max(1, ceil(($reg_token_expires - time()) / 60)); ?> more minutes):</strong>
                        </p>
                        <div style="font-family:monospace; font-size:1.1rem; letter-spacing:2px; padding:10px;
                                    background:var(--bg,#000); border:1px solid var(--border,#333); word-break:break-all;">
                            <?php echo htmlspecialchars($reg_token); ?>
                        </div>
                        <p style="font-size:0.8rem; color:var(--accent,#aaa); margin-top:10px;">
                            Give this token to your hub administrator. It expires at <?php echo date('H:i:s', $reg_token_expires); ?>.
                        </p>
                    </div>
                <?php else: ?>
                    <form method="POST" style="margin-bottom:20px;">
                        <button type="submit" name="gen_reg_token" class="master-update-btn">
                            GENERATE REGISTRATION TOKEN
                        </button>
                    </form>
                <?php endif; ?>

            <?php else: ?>
                <?php $hub = current($hub_node); ?>
                <div class="hub-connected-border" style="padding:15px; background:var(--input-bg,#111);">
                    <p><strong>Connected to Hub:</strong> <?php echo htmlspecialchars($hub['site_name']); ?></p>
                    <p style="color:var(--text-muted,#888); font-size:0.9rem;">
                        URL: <a href="<?php echo htmlspecialchars($hub['site_url']); ?>" target="_blank" style="color:var(--accent,#aaa);">
                            <?php echo htmlspecialchars($hub['site_url']); ?>
                        </a>
                    </p>
                    <p style="color:var(--text-muted,#888); font-size:0.9rem;">
                        Connected at: <?php echo date('Y-m-d H:i:s', strtotime($hub['connected_at'])); ?>
                    </p>
                </div>

                <div style="margin-top:20px; display:flex; gap:10px; align-items:center;">
                    <form method="POST">
                        <button type="submit" name="verify_hub" class="master-update-btn">
                            VERIFY CONNECTION
                        </button>
                    </form>
                    <form method="POST">
                        <button type="submit" name="disconnect_hub" class="action-delete" onclick="return confirm('Disconnect from hub? The hub will no longer be able to monitor this site.');">
                            DISCONNECT FROM HUB
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <!-- API ACCESS LOG -->
        <div class="box">
            <h3>API ACCESS LOG (Last 50 Calls)</h3>
            <?php
                $log = $pdo->query("
                    SELECT created_at FROM snap_multisite_queue
                    ORDER BY created_at DESC
                    LIMIT 50
                ")->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <?php if (empty($log)): ?>
                <p style="color:var(--text-muted,#888);">No API calls recorded yet.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                        <thead>
                            <tr style="border-bottom:1px solid var(--border,#333);">
                                <th style="text-align:left; padding:8px; color:var(--text-muted,#888);">TIMESTAMP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($log as $entry): ?>
                                <tr style="border-bottom:1px solid var(--border,#333);">
                                    <td style="padding:8px;">
                                        <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($entry['created_at']))); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div>

<?php include 'core/admin-footer.php'; ?>
