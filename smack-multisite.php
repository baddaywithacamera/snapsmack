<?php
// EOF marker required (SNAPSMACK_EOF_HEADER): file must end with `// ===== SNAPSMACK EOF =====`. If that line is missing, the file was truncated.
/**
 * SNAPSMACK - Multisite Management
 *
 * Hub and spoke site management interface. Allows admins to set up
 * multi-site configurations, register new spokes, and monitor their status.
 */

require_once 'core/auth.php';

// Load settings early so multisite_role is available to all POST handlers.
$settings       = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$multisite_role = $settings['multisite_role'] ?? '';

// --- SSRF GUARD ---
// Returns true if the URL resolves to a private/loopback/reserved address.
// Used to prevent admin-initiated cURL requests from probing internal services.
function snap_is_private_url(string $url): bool {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return true;
    // Block by hostname pattern first (avoids DNS lookup for obvious cases)
    $h = strtolower($host);
    if (in_array($h, ['localhost', 'ip6-localhost', 'ip6-loopback', '::1', '0.0.0.0'], true)) return true;
    // Resolve hostname and check against private/reserved ranges
    $ip = gethostbyname($host);
    if ($ip === $host) return false; // DNS failed — allow, let cURL fail naturally
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

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
    $settings['multisite_role'] = 'spoke';
    $multisite_role = 'spoke';
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

    // Update in-memory $settings so the token display is correct on this same page load.
    // $settings was loaded before this POST handler ran, so it's stale without this.
    $settings['multisite_reg_token']         = $token;
    $settings['multisite_reg_token_expires'] = (string)$expires;

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

        // SSRF guard — reject private/loopback addresses
        if (snap_is_private_url($spoke_url)) {
            $err = "Spoke URL must be a publicly accessible HTTPS address.";
        }

        if (!isset($err)) {
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
                // api_key     = key hub presents when calling spoke (hub→spoke auth)
                // api_key_outbound = key spoke presents when calling hub (spoke→hub auth)
                $api_key_local  = $response_data['api_key'];
                $api_key_remote = $response_data['api_key_outbound'] ?? '';

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
                    $stmt->execute(['spoke', $spoke_url, $spoke_name, $api_key_local, $api_key_remote]);
                    $msg = "Spoke registered successfully: {$spoke_name}";
                } catch (PDOException $e) {
                    $err = "Database error: " . $e->getMessage();
                }
            }
        }
        } // end SSRF guard
    }
}

// Manual ping a spoke (hub-initiated heartbeat for a single node)
// POST-only: prevents CSRF via crafted links
if (isset($_POST['ping']) && isset($_POST['ping_id'])) {
    $node_id = (int)$_POST['ping_id'];
    $stmt = $pdo->prepare("SELECT * FROM snap_multisite_nodes WHERE id = ? AND role = 'spoke' LIMIT 1");
    $stmt->execute([$node_id]);
    $n = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($n) {
        $url = rtrim($n['site_url'], '/') . '/api.php?route=multisite/heartbeat';
        $ch  = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $n['api_key_local'],
                'Accept: application/json',
            ],
        ]);
        $hb_raw  = curl_exec($ch);
        $hb_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

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
                        site_tagline       = ?,
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
                    $hb['site_tagline']       ?? null,
                    $n['id'],
                ]);
                $msg = "Ping OK — {$n['site_name']} is online (HTTP {$hb_code}).";
            } else {
                $msg = "Ping reached {$n['site_name']} but got an error response.";
            }
        } else {
            $pdo->prepare("UPDATE snap_multisite_nodes SET status = 'offline' WHERE id = ?")->execute([$node_id]);
            $err = "Could not reach {$n['site_name']} — HTTP {$hb_code}.";
        }
    } else {
        $err = "Spoke not found.";
    }
}

// Disconnect spoke (POST-only: prevents CSRF via crafted links)
if (isset($_POST['disconnect'])) {
    $node_id = (int)$_POST['disconnect'];

    // Get the spoke info
    $stmt = $pdo->prepare("SELECT site_url, api_key_local FROM snap_multisite_nodes WHERE id = ? AND role = 'spoke'");
    $stmt->execute([$node_id]);
    $node = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($node) {
        // Best-effort notify spoke to wipe its hub record.
        // Fire and forget — we delete our side regardless of whether the spoke responds.
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => rtrim($node['site_url'], '/') . '/api.php?route=multisite/disconnect',
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $node['api_key_local'],
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Delete our spoke record — clean slate for re-registration.
        $pdo->prepare("DELETE FROM snap_multisite_nodes WHERE id = ?")->execute([$node_id]);
        $msg = "Spoke disconnected and removed.";
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

// --- PUSH UPDATE TO SPOKE(S) ---
// Hub triggers a remote update on one or all behind-spokes.
$is_ajax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
if (isset($_POST['push_update']) || isset($_POST['push_update_all'])) {
    if ($multisite_role !== 'hub') {
        $err = "Only a hub can push updates.";
    } else {
        // Build list of spoke IDs to update
        if (isset($_POST['push_update_all'])) {
            // All spokes that are behind the hub version (strictly older, not just different)
            $stmt = $pdo->prepare("SELECT * FROM snap_multisite_nodes WHERE role = 'spoke' AND status = 'active' AND (software_version IS NULL OR software_version != ?)");
            $stmt->execute([SNAPSMACK_VERSION_SHORT]);
            $all_different = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $target_nodes = array_filter($all_different, fn($n) =>
                empty($n['software_version']) || snap_version_compare(SNAPSMACK_VERSION_SHORT, $n['software_version'], '>')
            );
        } else {
            $spoke_id = (int)($_POST['spoke_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM snap_multisite_nodes WHERE id = ? AND role = 'spoke' LIMIT 1");
            $stmt->execute([$spoke_id]);
            $target_nodes = array_filter([$stmt->fetch(PDO::FETCH_ASSOC)]);
        }

        $update_results = [];
        foreach ($target_nodes as $tn) {
            if (!$tn) continue;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => rtrim($tn['site_url'], '/') . '/api.php?route=multisite/updates/trigger',
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $tn['api_key_local'],
                    'Accept: application/json',
                    'Content-Length: 0',
                ],
            ]);
            $raw  = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);

            $result = ['name' => $tn['site_name'] ?? $tn['site_url']];
            if ($cerr) {
                $result['ok'] = false;
                $result['detail'] = 'cURL error: ' . $cerr;
            } elseif ($code !== 200) {
                $data = json_decode($raw, true);
                $result['ok'] = false;
                $result['detail'] = 'HTTP ' . $code . ': ' . ($data['error'] ?? $raw);
            } else {
                $data = json_decode($raw, true);
                $result['ok'] = true;
                $result['status']   = $data['status'] ?? '';
                $result['from']     = $data['from_version'] ?? '';
                $result['to']       = $data['to_version'] ?? '';
                $result['files']    = $data['files_updated'] ?? 0;
                $result['migs']     = $data['migrations'] ?? 0;
                $result['errors']   = $data['errors'] ?? [];
                // Refresh DB version
                if (!empty($data['to_version'])) {
                    $pdo->prepare("UPDATE snap_multisite_nodes SET software_version = ?, status = 'active', last_seen_at = NOW() WHERE id = ?")
                        ->execute([$data['to_version'], $tn['id']]);
                }
            }
            $update_results[] = $result;
        }
        // AJAX: return JSON, skip full page render
        if ($is_ajax) {
            if (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['results' => $update_results]);
            exit;
        }
    }
}

// --- VERIFY CONNECTION (spoke → hub heartbeat) ---
if (isset($_POST['verify_hub'])) {
    $hub = $pdo->query("SELECT * FROM snap_multisite_nodes WHERE role = 'hub' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($hub) {
        $url = rtrim($hub['site_url'], '/') . '/api.php?route=multisite/ping';
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
        $raw       = curl_exec($ch);
        $code      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($raw && $code === 200) {
            $hb = json_decode($raw, true);
            if (!empty($hb['ok'])) {
                $pdo->prepare("UPDATE snap_multisite_nodes SET last_seen_at = NOW(), status = 'active' WHERE id = ?")
                    ->execute([$hub['id']]);

                // Mesh: hub returns the canonical roster of peers. Ingest it
                // so this spoke knows about its siblings and can talk to them.
                $roster_msg = '';
                if (!empty($hb['mesh']['peers']) && is_array($hb['mesh']['peers'])) {
                    require_once __DIR__ . '/core/mesh-helpers.php';
                    $hub_url = rtrim($hub['site_url'], '/');
                    $r = ms_ingest_roster($pdo, $hub_url, $hb['mesh']['peers']);
                    $roster_msg = sprintf(
                        ' Mesh roster updated — %d added, %d updated, %d pruned.',
                        $r['added'], $r['updated'], $r['pruned']
                    );
                }
                $msg = "Connection verified — hub responded OK (version "
                     . htmlspecialchars($hb['version'] ?? 'unknown') . ")."
                     . $roster_msg;
            } else {
                $msg = "Hub responded but returned an error: " . htmlspecialchars($hb['error'] ?? 'unknown');
            }
        } else {
            $msg = "Could not reach hub — HTTP {$code}" . ($curl_err ? " ({$curl_err})" : "") . ".";
        }
    } else {
        $msg = "No hub configured.";
    }
}

// --- DATA LOADING ---
// (settings + multisite_role loaded at top of file before POST handlers)

// Load connected nodes — fetch UNIX_TIMESTAMP to avoid strtotime/timezone issues
$nodes = $pdo->query("SELECT *, UNIX_TIMESTAMP(last_seen_at) AS last_seen_ts FROM snap_multisite_nodes ORDER BY role ASC, connected_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Hub self-entry data — shown as first row in Connected Spokes table
$hub_post_count    = (int)$pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published'")->fetchColumn();
$hub_pending       = (int)$pdo->query("SELECT COUNT(*) FROM snap_comments WHERE is_approved = 0")->fetchColumn();
$hub_backup_status = $settings['last_backup_status'] ?? 'unknown';
$hub_site_name     = $settings['site_name'] ?? 'Hub';
$hub_site_url      = rtrim($settings['site_url'] ?? '', '/');

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
                        site_tagline       = ?,
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
            // Only mark offline if the spoke hasn't been seen recently.
            // A single failed heartbeat could be a transient blip (restart,
            // brief downtime) — don't take the whole fleet dark on one bad curl.
            // Require 10 minutes of silence before flipping status.
            $grace_seconds = 600;
            $last_seen_ts  = isset($n['last_seen_ts']) ? (int)$n['last_seen_ts'] : 0;
            if ($last_seen_ts === 0 || (time() - $last_seen_ts) >= $grace_seconds) {
                $pdo->prepare("UPDATE snap_multisite_nodes SET status = 'offline' WHERE id = ?")->execute([$n['id']]);
                $n['status'] = 'offline';
            }
            // else: spoke was active recently — leave status unchanged, treat as blip
        }
    }
    unset($n);

    // Reload nodes so status changes are reflected
    $nodes = $pdo->query("SELECT *, UNIX_TIMESTAMP(last_seen_at) AS last_seen_ts FROM snap_multisite_nodes ORDER BY role ASC, connected_at DESC")->fetchAll(PDO::FETCH_ASSOC);
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
        <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
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

            <?php
                // Count spokes that are behind and active (eligible for bulk update)
                $behind_count = count(array_filter($nodes, fn($n) =>
                    $n['role'] === 'spoke' && $n['status'] === 'active' &&
                    !empty($n['software_version']) &&
                    snap_version_compare(SNAPSMACK_VERSION_SHORT, $n['software_version'], '>')
                ));
            ?>

            <div id="update-progress-live" style="margin-bottom:6px;"></div>
            <?php if (!empty($update_results)): ?>
                <div id="update-progress-php" style="margin-bottom:18px;">
                    <?php foreach ($update_results as $ur): ?>
                        <?php if ($ur['ok']): ?>
                            <?php if (($ur['status'] ?? '') === 'already_current'): ?>
                                <div class="alert alert-success" style="margin-bottom:6px;">
                                    ✓ <strong><?php echo htmlspecialchars($ur['name']); ?></strong> — already on current version.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-success" style="margin-bottom:6px;">
                                    ✓ <strong><?php echo htmlspecialchars($ur['name']); ?></strong>
                                    updated <?php echo htmlspecialchars($ur['from']); ?> → <?php echo htmlspecialchars($ur['to']); ?>
                                    (<?php echo (int)$ur['files']; ?> files<?php echo $ur['migs'] > 0 ? ', ' . (int)$ur['migs'] . ' migration' . ($ur['migs'] !== 1 ? 's' : '') : ''; ?>)<?php
                                    if (!empty($ur['errors'])): ?> — <span style="color:var(--warning,#f90);">warnings: <?php echo htmlspecialchars(implode('; ', $ur['errors'])); ?></span><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-error" style="margin-bottom:6px;">
                                ✗ <strong><?php echo htmlspecialchars($ur['name']); ?></strong> — <?php echo htmlspecialchars($ur['detail']); ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (empty($nodes) || count(array_filter($nodes, fn($n) => $n['role'] === 'spoke')) === 0): ?>
                <p style="color:var(--text-muted,#888);">No spokes connected yet. Register one below.</p>
            <?php else: ?>
                <div style="margin-bottom:14px;">
                    <?php if ($behind_count > 0): ?>
                        <form method="POST" id="update-all-form">
                            <button type="submit" name="push_update_all" class="btn-smack" style="width:auto;height:auto;margin-top:0;padding:8px 18px;">
                                UPDATE ALL BEHIND (<?php echo $behind_count; ?>)
                            </button>
                        </form>
                    <?php else: ?>
                        <button type="button" class="btn-smack" disabled style="width:auto;height:auto;margin-top:0;padding:8px 18px;opacity:0.4;cursor:default;">
                            ALL UP TO DATE
                        </button>
                    <?php endif; ?>
                </div>

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
                            <!-- HUB SELF-ROW -->
                            <tr style="border-bottom:2px solid var(--accent,#f90); opacity:0.85;">
                                <td>
                                    <strong><?php echo htmlspecialchars($hub_site_name); ?></strong>
                                    <span style="margin-left:6px; font-size:0.7rem; font-weight:700; letter-spacing:1px; color:var(--accent,#f90); opacity:0.8;">HUB</span>
                                </td>
                                <td>
                                    <?php if ($hub_site_url): ?>
                                        <a href="<?php echo htmlspecialchars($hub_site_url); ?>" target="_blank">
                                            <?php echo htmlspecialchars(preg_replace('~^https?://~i', '', $hub_site_url)); ?>
                                        </a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="col-center" style="font-family:monospace; font-size:0.85rem;"><?php echo htmlspecialchars(SNAPSMACK_VERSION_SHORT); ?></td>
                                <td class="col-center">
                                    <span class="status-dot status-dot--active" title="active"></span>
                                    <span class="status-label status-label--active">ACTIVE</span>
                                </td>
                                <td class="col-center">just now</td>
                                <td class="col-center"><?php echo $hub_post_count; ?></td>
                                <td class="col-center"><?php echo $hub_pending; ?></td>
                                <td class="col-center">
                                    <span class="status-dot status-dot--lg status-dot--<?php echo htmlspecialchars($hub_backup_status); ?>" title="<?php echo htmlspecialchars($hub_backup_status); ?>"></span>
                                </td>
                                <td class="col-center" style="font-size:0.75rem; color:var(--text-muted,#888); letter-spacing:1px;">THIS SITE</td>
                            </tr>
                            <?php foreach ($nodes as $n): ?>
                                <?php if ($n['role'] !== 'spoke') continue; ?>
                                <?php $node_status   = $n['status'] ?? 'unknown'; ?>
                                <?php $backup_status = $n['last_backup_status'] ?? 'unknown'; ?>
                                <tr id="spoke-row-<?php echo $n['id']; ?>">
                                    <td><strong><?php echo htmlspecialchars($n['site_name'] ?? 'Unknown'); ?></strong></td>
                                    <td>
                                        <a href="<?php echo htmlspecialchars($n['site_url']); ?>" target="_blank">
                                            <?php echo htmlspecialchars(preg_replace('~^https?://~i', '', $n['site_url'])); ?>
                                        </a>
                                    </td>
                                    <td class="col-center" id="spoke-ver-<?php echo $n['id']; ?>" style="font-family:monospace; font-size:0.85rem;">
                                        <?php
                                            $spoke_ver = $n['software_version'] ?? '';
                                            echo $spoke_ver ? htmlspecialchars($spoke_ver) : '—';
                                            if ($spoke_ver && snap_version_compare(SNAPSMACK_VERSION_SHORT, $spoke_ver, '>')) {
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
                                            // Use UNIX_TIMESTAMP from MySQL to avoid PHP/MySQL timezone mismatch
                                            $last_seen_ts = isset($n['last_seen_ts']) ? (int)$n['last_seen_ts'] : 0;
                                            if ($last_seen_ts > 0) {
                                                $diff = time() - $last_seen_ts;
                                                if ($diff < 60) {
                                                    echo "just now";
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
                                    <td class="col-center" id="spoke-act-<?php echo $n['id']; ?>">
                                        <?php if ($n['status'] === 'active'): ?>
                                            <a href="smack-multisite-sso.php?sat=<?php echo $n['id']; ?>"
                                               target="_blank"
                                               class="action-authorize"
                                               title="Open spoke admin as primary admin user">REMOTE LOGIN</a>
                                        <?php else: ?>
                                            <form method="POST" style="display:inline;">
                                            <input type="hidden" name="ping_id" value="<?php echo $n['id']; ?>">
                                            <button type="submit" name="ping" class="action-view" title="Manually ping this spoke">PING</button>
                                        </form>
                                        <?php endif; ?>
                                        <?php
                                            $spoke_ver_action = $n['software_version'] ?? '';
                                            if ($n['status'] === 'active' && $spoke_ver_action && snap_version_compare(SNAPSMACK_VERSION_SHORT, $spoke_ver_action, '>')):
                                        ?>
                                            <form method="POST" class="spoke-update-form" data-spoke-id="<?php echo $n['id']; ?>" data-spoke-name="<?php echo htmlspecialchars($n['site_name'] ?? $n['site_url']); ?>" style="display:inline;">
                                                <input type="hidden" name="spoke_id" value="<?php echo $n['id']; ?>">
                                                <button type="submit" name="push_update" class="action-update" title="Push update to this spoke">UPDATE</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Disconnect this spoke?');">
                                            <input type="hidden" name="disconnect" value="<?php echo $n['id']; ?>">
                                            <button type="submit" class="action-delete">DISCONNECT</button>
                                        </form>
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
                        <input id="reg-token-display" type="text" readonly
                               value="<?php echo htmlspecialchars($reg_token); ?>"
                               style="width:100%; box-sizing:border-box; font-family:monospace; font-size:1rem;
                                      letter-spacing:2px; padding:12px 14px; margin-bottom:8px;
                                      background:var(--input-bg,#111); border:1px solid var(--border,#333);
                                      border-radius:4px; color:inherit; cursor:text;"
                               onclick="this.select();">
                        <button type="button" class="btn-smack" id="copy-token-btn" style="width:100%; margin-bottom:10px;"
                                onclick="navigator.clipboard.writeText(document.getElementById('reg-token-display').value).then(function(){ var b=document.getElementById('copy-token-btn'); b.textContent='COPIED ✓'; setTimeout(function(){ b.textContent='COPY'; }, 2000); });">
                            COPY
                        </button>
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
                        <button type="submit" name="verify_hub" class="btn-smack btn-mt-0">
                            VERIFY CONNECTION
                        </button>
                    </form>
                    <form method="POST">
                        <button type="submit" name="disconnect_hub" class="btn-smack btn-mt-0 btn-danger" onclick="return confirm('Disconnect from hub? The hub will no longer be able to monitor this site.');">
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
<?php // ===== SNAPSMACK EOF =====
