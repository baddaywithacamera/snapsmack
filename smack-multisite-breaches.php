<?php
/**
 * Read-only fleet integrity status during a Hub SMACKBACK lockout.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

require_once 'core/auth-smack.php';
smack_require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$settings = $pdo->query('SELECT setting_key, setting_val FROM snap_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$fleet_nodes = [];
$fleet_error = '';
if (($settings['multisite_role'] ?? '') === 'hub') {
    try {
        $fleet_nodes = $pdo->query(
            "SELECT site_name, site_url, status, smackback_status, smackback_breach_at,
                    smackback_breach_files, last_seen_at
             FROM snap_multisite_nodes WHERE role = 'spoke' ORDER BY site_name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $fleet_error = 'Fleet status could not be read.';
        error_log('Fleet breach status read failed: ' . $e->getMessage());
    }
}
$fleet_breach_count = count(array_filter($fleet_nodes,
    static fn($node) => ($node['smackback_status'] ?? '') === 'breach'));
$page_title = 'FLEET BREACH STATUS';
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>FLEET BREACH STATUS</h2>
    <div class="box">
        <h3>LAST REPORTED STATUS</h3>
        <p class="dim">This view reads the Hub's saved spoke reports. It does not contact sites or change settings. Check “Last seen” before treating a clean report as current.</p>
        <p><a href="smack-back.php">BACK TO SMACKBACK</a></p>
        <?php if (($settings['multisite_role'] ?? '') !== 'hub'): ?>
            <p>This site is not configured as a Hub.</p>
        <?php elseif ($fleet_error !== ''): ?>
            <p><?php echo htmlspecialchars($fleet_error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php else: ?>
            <p><strong><?php echo $fleet_breach_count; ?></strong> spoke<?php echo $fleet_breach_count === 1 ? '' : 's'; ?> last reported a breach.</p>
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;text-align:left;">
                    <thead><tr><th>Site</th><th>Connection</th><th>SMACKBACK</th><th>Last seen</th><th>Breach detected</th><th>Reported files</th></tr></thead>
                    <tbody>
                    <?php foreach ($fleet_nodes as $node):
                        $name = trim((string)($node['site_name'] ?? '')) ?: (string)($node['site_url'] ?? 'Unknown');
                        $status = strtolower((string)($node['smackback_status'] ?? 'unknown'));
                        $files = json_decode((string)($node['smackback_breach_files'] ?? ''), true);
                        $file_names = [];
                        if ($status === 'breach' && is_array($files)) {
                            foreach ($files as $entry) {
                                $path = is_array($entry) ? ($entry['path'] ?? '') : $entry;
                                if (is_string($path) && $path !== '') $file_names[] = $path;
                            }
                        }
                    ?>
                        <tr style="border-top:1px solid var(--border,#555);">
                            <td><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?><br><small class="dim"><?php echo htmlspecialchars((string)($node['site_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small></td>
                            <td><?php echo htmlspecialchars((string)($node['status'] ?? 'unknown'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><strong style="color:<?php echo $status === 'breach' ? 'var(--danger,#cc2200)' : 'inherit'; ?>"><?php echo htmlspecialchars(strtoupper($status), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                            <td><?php echo htmlspecialchars((string)($node['last_seen_at'] ?? 'Never'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($node['smackback_breach_at'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo $file_names ? htmlspecialchars(implode(', ', $file_names), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$fleet_nodes): ?><tr><td colspan="6">No spokes are registered.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
