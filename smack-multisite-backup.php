<?php
/**
 * SNAPSMACK - Multisite Backup Dock
 *
 * Hub-only page. Fleet-wide backup health matrix. Reads cached backup state
 * from snap_multisite_nodes (populated by the heartbeat sweep in
 * smack-multisite.php) and optionally fetches full backup logs from
 * individual spokes on demand.
 *
 * Colour coding:
 *   GREEN  = last_backup_status 'ok' AND backed up within the last 7 days
 *   AMBER  = last_backup_status 'ok' but backup is older than 7 days
 *   RED    = last_backup_status 'failed'
 *   GREY   = last_backup_status 'unknown' or no backup recorded
 */

require_once 'core/auth.php';
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

// --- HUB GUARD ---
$multisite_role = $settings['multisite_role'] ?? '';
if ($multisite_role !== 'hub') {
    header('Location: smack-multisite.php');
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Load spokes (use cached data from heartbeat — no extra cURL here)
// ─────────────────────────────────────────────────────────────────────────────
$spokes = $pdo->query("
    SELECT id, site_url, site_name,
           api_key_local,
           last_backup_at, last_backup_size, last_backup_dest, last_backup_status,
           disk_usage_bytes, status, last_seen_at
    FROM snap_multisite_nodes
    WHERE role = 'spoke'
    ORDER BY site_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ─────────────────────────────────────────────────────────────────────────────
// Drill-down: fetch backup log from a specific spoke on demand
// ─────────────────────────────────────────────────────────────────────────────
$drill_node    = isset($_GET['node']) ? (int)$_GET['node'] : 0;
$drill_log     = [];
$drill_spoke   = null;
$drill_err     = null;

if ($drill_node > 0) {
    foreach ($spokes as $spoke) {
        if ($spoke['id'] === $drill_node) { $drill_spoke = $spoke; break; }
    }
    if ($drill_spoke) {
        $url = rtrim($drill_spoke['site_url'], '/') . '/api.php?route=multisite/backup/log';
        $ch  = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $drill_spoke['api_key_local'],
                'Accept: application/json',
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw && $code === 200) {
            $resp = json_decode($raw, true);
            $drill_log = ($resp['ok'] ?? false) ? ($resp['log'] ?? []) : [];
        } else {
            $drill_err = "Could not reach spoke.";
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Helper: classify a spoke's backup health
// Returns: 'ok' | 'stale' | 'failed' | 'unknown'
// ─────────────────────────────────────────────────────────────────────────────
function backup_health(array $spoke): string {
    $status = $spoke['last_backup_status'] ?? 'unknown';
    if ($status === 'failed') return 'failed';
    if ($status !== 'ok')    return 'unknown';
    if (!$spoke['last_backup_at']) return 'unknown';
    $age_days = (time() - strtotime($spoke['last_backup_at'])) / 86400;
    return $age_days > 7 ? 'stale' : 'ok';
}

// Health → colour / label maps
$health_color = [
    'ok'      => '#4CAF50',
    'stale'   => '#FF9800',
    'failed'  => '#f44336',
    'unknown' => '#666',
];
$health_label = [
    'ok'      => 'HEALTHY',
    'stale'   => 'STALE',
    'failed'  => 'FAILED',
    'unknown' => 'UNKNOWN',
];

// ─────────────────────────────────────────────────────────────────────────────
// Fleet summary counts
// ─────────────────────────────────────────────────────────────────────────────
$fleet_counts = ['ok' => 0, 'stale' => 0, 'failed' => 0, 'unknown' => 0];
$fleet_total_bytes = 0;
foreach ($spokes as $spoke) {
    $h = backup_health($spoke);
    $fleet_counts[$h]++;
    $fleet_total_bytes += (int)($spoke['disk_usage_bytes'] ?? 0);
}

// ─────────────────────────────────────────────────────────────────────────────
// Helper: human-readable bytes
// ─────────────────────────────────────────────────────────────────────────────
function human_bytes(int $bytes): string {
    if ($bytes <= 0)          return '—';
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1)    . ' MB';
    return round($bytes / 1024, 1) . ' KB';
}

// ─────────────────────────────────────────────────────────────────────────────
// Helper: relative time
// ─────────────────────────────────────────────────────────────────────────────
function rel_time(?string $dt): string {
    if (!$dt) return 'never';
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60)   . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600)  . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('Y-m-d', strtotime($dt));
}

$page_title = "Backup Dock";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>BACKUP DOCK</h2>
        <div class="header-actions">
            <?php
                $worst = 'ok';
                if ($fleet_counts['unknown'] > 0) $worst = 'unknown';
                if ($fleet_counts['stale']   > 0) $worst = 'stale';
                if ($fleet_counts['failed']  > 0) $worst = 'failed';
                $pill_class = $worst === 'ok' ? 'status-online' : ($worst === 'failed' ? 'status-offline' : 'status-warning');
            ?>
            <div class="status-pill <?php echo $pill_class; ?>" style="color:<?php echo $health_color[$worst]; ?>; border-color:<?php echo $health_color[$worst]; ?>;">
                FLEET: <?php echo strtoupper($worst); ?>
            </div>
        </div>
    </div>

    <!-- QUICK NAV -->
    <div class="signal-control-header" style="margin-bottom:20px;">
        <div class="signal-nav-group">
            <a href="smack-multisite.php"             class="btn-clear">DASHBOARD</a>
            <a href="smack-multisite-comments.php"    class="btn-clear">SIGNALS</a>
            <a href="smack-multisite-posts.php"       class="btn-clear">POSTS</a>
            <a href="smack-multisite-backup.php"      class="btn-clear active">BACKUP DOCK</a>
            <a href="smack-multisite-stats.php"       class="btn-clear">STATS</a>
            <a href="smack-multisite-crosspost.php"   class="btn-clear">CROSS-POST</a>
                <a href="smack-multisite-blogroll.php"    class="btn-clear">BLOGROLL</a>
        </div>
    </div>

    <?php if (empty($spokes)): ?>
        <div class="box">
            <p style="color:var(--text-muted,#888);">No spokes connected. <a href="smack-multisite.php" style="color:var(--accent,#aaa);">Register a spoke</a> first.</p>
        </div>
    <?php else: ?>

    <!-- FLEET HEALTH MATRIX -->
    <div class="box">
        <h3>FLEET HEALTH MATRIX</h3>

        <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:15px; margin-bottom:25px;">
            <?php foreach (['ok' => 'HEALTHY', 'stale' => 'STALE', 'failed' => 'FAILED', 'unknown' => 'UNKNOWN'] as $key => $label): ?>
                <div style="padding:20px; border:1px solid <?php echo $fleet_counts[$key] > 0 ? $health_color[$key] : 'var(--border,#333)'; ?>;
                            background:var(--input-bg,#111); text-align:center; border-radius:2px;">
                    <div style="font-size:2rem; font-weight:900; color:<?php echo $fleet_counts[$key] > 0 ? $health_color[$key] : 'var(--text-muted,#666)'; ?>;">
                        <?php echo $fleet_counts[$key]; ?>
                    </div>
                    <div style="font-size:0.75rem; color:var(--text-muted,#888); letter-spacing:2px; margin-top:5px;">
                        <?php echo $label; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($fleet_total_bytes > 0): ?>
            <div style="font-size:0.85rem; color:var(--text-muted,#888); text-align:right;">
                TOTAL FLEET DISK USAGE: <strong style="color:var(--text,#eee);"><?php echo human_bytes($fleet_total_bytes); ?></strong>
            </div>
        <?php endif; ?>
    </div>

    <!-- PER-SPOKE BACKUP STATUS -->
    <div class="box">
        <h3>SPOKE BACKUP STATUS</h3>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:0.9rem;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border,#333);">
                        <th style="text-align:left;   padding:10px; color:var(--text-muted,#888);">SPOKE</th>
                        <th style="text-align:center; padding:10px; color:var(--text-muted,#888);">HEALTH</th>
                        <th style="text-align:center; padding:10px; color:var(--text-muted,#888);">LAST BACKUP</th>
                        <th style="text-align:center; padding:10px; color:var(--text-muted,#888);">SIZE</th>
                        <th style="text-align:center; padding:10px; color:var(--text-muted,#888);">DESTINATION</th>
                        <th style="text-align:center; padding:10px; color:var(--text-muted,#888);">DISK USED</th>
                        <th style="text-align:center; padding:10px; color:var(--text-muted,#888);">LOG</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($spokes as $spoke):
                        $health      = backup_health($spoke);
                        $color       = $health_color[$health];
                        $is_offline  = $spoke['status'] === 'offline';
                        $is_drilling = $drill_node === $spoke['id'];
                    ?>
                        <tr style="border-bottom:1px solid var(--border,#333); <?php echo $is_drilling ? 'background:var(--hover-bg,rgba(255,255,255,0.03));' : ''; ?>">
                            <td style="padding:10px;">
                                <strong><?php echo htmlspecialchars($spoke['site_name'] ?? 'Unknown'); ?></strong>
                                <div style="font-size:0.8rem; color:var(--text-muted,#888);">
                                    <a href="<?php echo htmlspecialchars($spoke['site_url']); ?>" target="_blank" style="color:inherit; text-decoration:none;">
                                        <?php echo htmlspecialchars(preg_replace('~^https?://~i', '', $spoke['site_url'])); ?>
                                    </a>
                                </div>
                                <?php if ($is_offline): ?>
                                    <div style="font-size:0.75rem; color:#f44336; margin-top:3px;">OFFLINE</div>
                                <?php endif; ?>
                            </td>

                            <td style="padding:10px; text-align:center;">
                                <span style="display:inline-flex; align-items:center; gap:6px;">
                                    <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:<?php echo $color; ?>;"></span>
                                    <span style="color:<?php echo $color; ?>; font-size:0.8rem; font-weight:700;">
                                        <?php echo $health_label[$health]; ?>
                                    </span>
                                </span>
                            </td>

                            <td style="padding:10px; text-align:center; color:var(--text-muted,#888);">
                                <?php
                                    if ($spoke['last_backup_at']) {
                                        echo '<span title="' . htmlspecialchars($spoke['last_backup_at']) . '">';
                                        echo htmlspecialchars(rel_time($spoke['last_backup_at']));
                                        echo '</span>';
                                    } else {
                                        echo '<span style="color:#666;">never</span>';
                                    }
                                ?>
                            </td>

                            <td style="padding:10px; text-align:center; color:var(--text-muted,#888); font-family:monospace;">
                                <?php echo human_bytes((int)($spoke['last_backup_size'] ?? 0)); ?>
                            </td>

                            <td style="padding:10px; text-align:center;">
                                <?php
                                    $dest = $spoke['last_backup_dest'] ?? '';
                                    $dest_icons = [
                                        'local' => '&#x1F4BE;', 'cloud' => '&#x2601;', 'ftp' => '&#x1F4E1;',
                                        's3'    => '&#x2601;', 'b2' => '&#x2601;',
                                    ];
                                    $icon = $dest_icons[strtolower($dest)] ?? '&#x2753;';
                                    echo $dest
                                        ? '<span title="' . htmlspecialchars($dest) . '">' . $icon . ' <span style="font-size:0.8rem; color:var(--text-muted,#888);">' . htmlspecialchars(strtoupper($dest)) . '</span></span>'
                                        : '<span style="color:#666;">—</span>';
                                ?>
                            </td>

                            <td style="padding:10px; text-align:center; color:var(--text-muted,#888); font-family:monospace;">
                                <?php echo human_bytes((int)($spoke['disk_usage_bytes'] ?? 0)); ?>
                            </td>

                            <td style="padding:10px; text-align:center;">
                                <?php if ($spoke['status'] === 'active'): ?>
                                    <a href="smack-multisite-backup.php?node=<?php echo $spoke['id']; ?>#drill"
                                       class="btn-clear <?php echo $is_drilling ? 'active' : ''; ?>"
                                       style="font-size:0.75rem; padding:4px 10px;">
                                        <?php echo $is_drilling ? 'VIEWING' : 'VIEW'; ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color:#666; font-size:0.8rem;">OFFLINE</span>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <?php if ($is_drilling): ?>
                            <!-- INLINE DRILL-DOWN LOG -->
                            <tr>
                                <td colspan="7" style="padding:0 10px 20px 10px;" id="drill">
                                    <div style="border:1px solid var(--border,#333); border-top:none; padding:15px; background:var(--input-bg,#111);">
                                        <h4 style="margin:0 0 12px; font-size:0.85rem; color:var(--text-muted,#888); letter-spacing:2px;">
                                            BACKUP LOG — <?php echo htmlspecialchars(strtoupper($spoke['site_name'])); ?>
                                        </h4>

                                        <?php if ($drill_err): ?>
                                            <p style="color:#f44336; font-size:0.85rem;"><?php echo htmlspecialchars($drill_err); ?></p>

                                        <?php elseif (empty($drill_log)): ?>
                                            <p style="color:var(--text-muted,#666); font-size:0.85rem;">No backup log entries found. Requires snap_backup_log table on the spoke.</p>

                                        <?php else: ?>
                                            <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                                                <thead>
                                                    <tr style="border-bottom:1px solid var(--border,#333);">
                                                        <th style="text-align:left;   padding:6px 10px; color:var(--text-muted,#888);">DATE</th>
                                                        <th style="text-align:center; padding:6px 10px; color:var(--text-muted,#888);">STATUS</th>
                                                        <th style="text-align:center; padding:6px 10px; color:var(--text-muted,#888);">SIZE</th>
                                                        <th style="text-align:center; padding:6px 10px; color:var(--text-muted,#888);">DESTINATION</th>
                                                        <th style="text-align:left;   padding:6px 10px; color:var(--text-muted,#888);">NOTES</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($drill_log as $entry):
                                                        $entry_status = $entry['status'] ?? 'unknown';
                                                        $entry_color  = $entry_status === 'ok' ? '#4CAF50' : ($entry_status === 'failed' ? '#f44336' : '#888');
                                                    ?>
                                                        <tr style="border-bottom:1px solid var(--border,#222);">
                                                            <td style="padding:6px 10px; color:var(--text-muted,#888);">
                                                                <?php echo htmlspecialchars(substr($entry['created_at'] ?? '', 0, 16)); ?>
                                                            </td>
                                                            <td style="padding:6px 10px; text-align:center;">
                                                                <span style="color:<?php echo $entry_color; ?>; font-weight:700; font-size:0.8rem;">
                                                                    <?php echo htmlspecialchars(strtoupper($entry_status)); ?>
                                                                </span>
                                                            </td>
                                                            <td style="padding:6px 10px; text-align:center; font-family:monospace; color:var(--text-muted,#888);">
                                                                <?php echo human_bytes((int)($entry['size_bytes'] ?? 0)); ?>
                                                            </td>
                                                            <td style="padding:6px 10px; text-align:center; color:var(--text-muted,#888);">
                                                                <?php echo htmlspecialchars(strtoupper($entry['destination'] ?? '—')); ?>
                                                            </td>
                                                            <td style="padding:6px 10px; color:var(--text-muted,#666); font-size:0.8rem;">
                                                                <?php echo htmlspecialchars($entry['notes'] ?? ''); ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
// EOF
