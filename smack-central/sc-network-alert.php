<?php
/**
 * SMACK CENTRAL — Network Alert Console
 *
 * Sean's interface for managing the global network alert broadcast.
 * - View and set the current alert level (green / yellow_slow / yellow_fast)
 * - Write the alert message shown in opted-in installs' admin panels
 * - Review incoming breach reports from the install network
 * - Monitor auto-escalation status
 */

// SNAPSMACK_EOF_HEADER
//     <?php // ===== SNAPSMACK EOF =====
// Last non-empty line of this file MUST match the line above.
// Missing or different = truncated/corrupted. Restore before saving.

require_once __DIR__ . '/sc-auth.php';
require_once __DIR__ . '/sc-network-fanout.php';
$sc_active_nav = 'sc-network-alert.php';
$sc_page_title = 'Network Alert';

$db  = sc_db();
$msg = '';

// ─── ACTIONS ──────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Set alert level + message
    if (isset($_POST['set_level'])) {
        $level   = $_POST['level']   ?? 'green';
        $message = substr(trim($_POST['message'] ?? ''), 0, 500);

        if (!in_array($level, ['green', 'yellow_slow', 'yellow_fast'], true)) {
            $level = 'green';
        }

        $db->prepare(
            "UPDATE sc_network_alert_state SET level = ?, message = ?, set_by = 'manual' WHERE id = 1"
        )->execute([$level, $message]);

        // Fan-out immediately to all push subscribers
        $set_at = $db->query("SELECT set_at FROM sc_network_alert_state WHERE id = 1")->fetchColumn();
        na_fanout($db, $level, $message, (string)$set_at);

        $sub_count = (int)$db->query("SELECT COUNT(*) FROM sc_push_subscribers WHERE push_failures < 5")->fetchColumn();
        $msg = 'Alert level set to ' . strtoupper($level) . '.'
             . ($sub_count > 0 ? " Push sent to {$sub_count} subscriber(s)." : '');
    }

    // Manual push to all subscribers without changing level
    if (isset($_POST['manual_push'])) {
        $state = $db->query("SELECT * FROM sc_network_alert_state WHERE id = 1")->fetch();
        if ($state) {
            na_fanout($db, $state['level'], $state['message'], $state['set_at']);
            $sub_count = (int)$db->query("SELECT COUNT(*) FROM sc_push_subscribers WHERE push_failures < 5")->fetchColumn();
            $msg = "Manual push sent to {$sub_count} subscriber(s).";
        }
    }

    // Mark a report reviewed
    if (isset($_POST['mark_reviewed'])) {
        $rid = (int)($_POST['report_id'] ?? 0);
        if ($rid > 0) {
            $db->prepare("UPDATE sc_network_alert_reports SET reviewed = 1 WHERE id = ?")->execute([$rid]);
            $msg = "Report #{$rid} marked reviewed.";
        }
    }

    // Mark all unreviewed as reviewed
    if (isset($_POST['mark_all_reviewed'])) {
        $db->exec("UPDATE sc_network_alert_reports SET reviewed = 1 WHERE reviewed = 0");
        $msg = 'All reports marked reviewed.';
    }

    // Manual auto-escalation check
    if (isset($_POST['run_auto_check'])) {
        $distinct_stmt = $db->prepare(
            "SELECT COUNT(DISTINCT request_ip) FROM sc_network_alert_reports
             WHERE received_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)"
        );
        $distinct_stmt->execute();
        $distinct_ips = (int)$distinct_stmt->fetchColumn();
        $msg = "Auto-check: {$distinct_ips} distinct IP(s) reported in last 2 hours.";
    }

    // Clear old reports
    if (isset($_POST['clear_old'])) {
        $days = max(1, (int)($_POST['clear_days'] ?? 30));
        $db->prepare(
            "DELETE FROM sc_network_alert_reports WHERE received_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        )->execute([$days]);
        $msg = "Reports older than {$days} days cleared.";
    }
}

// ─── LOAD DATA ─────────────────────────────────────────────────────────────────

// Current state (seed if missing)
$state = $db->query("SELECT * FROM sc_network_alert_state WHERE id = 1")->fetch();
if (!$state) {
    $db->exec("INSERT IGNORE INTO sc_network_alert_state (id,level,message,set_by) VALUES (1,'green','','init')");
    $state = ['id' => 1, 'level' => 'green', 'message' => '', 'set_at' => date('Y-m-d H:i:s'), 'set_by' => 'init'];
}

// Recent reports
$reports = $db->query(
    "SELECT * FROM sc_network_alert_reports ORDER BY received_at DESC LIMIT 100"
)->fetchAll();

// Push subscriber stats
$push_subs       = [];
$push_sub_count  = 0;
$push_fail_count = 0;
try {
    $push_subs       = $db->query("SELECT * FROM sc_push_subscribers ORDER BY registered_at DESC LIMIT 100")->fetchAll();
    $push_sub_count  = (int)$db->query("SELECT COUNT(*) FROM sc_push_subscribers WHERE push_failures < 5")->fetchColumn();
    $push_fail_count = (int)$db->query("SELECT COUNT(*) FROM sc_push_subscribers WHERE push_failures >= 5")->fetchColumn();
} catch (PDOException $e) { }

// Stats
$total_reports  = (int)$db->query("SELECT COUNT(*) FROM sc_network_alert_reports")->fetchColumn();
$unreviewed     = (int)$db->query("SELECT COUNT(*) FROM sc_network_alert_reports WHERE reviewed = 0")->fetchColumn();
$last_24h       = (int)$db->query(
    "SELECT COUNT(*) FROM sc_network_alert_reports WHERE received_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
)->fetchColumn();
$di_stmt = $db->prepare(
    "SELECT COUNT(DISTINCT request_ip) FROM sc_network_alert_reports
     WHERE received_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)"
);
$di_stmt->execute();
$distinct_2h = (int)$di_stmt->fetchColumn();

// Level display helpers
$level_labels = [
    'green'       => ['label' => 'GREEN',        'colour' => '#5a9a5a', 'desc' => 'No advisory'],
    'yellow_slow' => ['label' => 'YELLOW (slow)', 'colour' => '#cc9900', 'desc' => '4s pulse — advisory'],
    'yellow_fast' => ['label' => 'YELLOW (fast)', 'colour' => '#ffcc00', 'desc' => '2s pulse — coordinated threat'],
];
$current_info = $level_labels[$state['level']] ?? $level_labels['green'];

require __DIR__ . '/sc-layout-top.php';
?>

<div class="sc-page-header">
  <span class="sc-page-title">Network Alert</span>
  <span class="sc-dim">Layer 2 &mdash; SC broadcast to opted-in installs &mdash; YELLOW only</span>
</div>

<?php if ($msg): ?>
<div class="sc-alert sc-alert--info"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<!-- ── CURRENT STATE ──────────────────────────────────────────────────────── -->
<div class="sc-box" style="border-left:4px solid <?php echo $current_info['colour']; ?>;">
  <div class="sc-box-header">
    <span class="sc-box-title">Current Broadcast Level</span>
    <span class="sc-dim" style="font-size:0.8rem;">set_by: <?php echo htmlspecialchars($state['set_by']); ?> &bull; <?php echo htmlspecialchars($state['set_at']); ?></span>
  </div>
  <div class="sc-box-body">
    <div style="font-size:1.8rem;font-weight:900;color:<?php echo $current_info['colour']; ?>;letter-spacing:2px;margin-bottom:4px;">
      <?php echo $current_info['label']; ?>
    </div>
    <div class="sc-dim" style="margin-bottom:16px;"><?php echo $current_info['desc']; ?></div>
    <?php if ($state['message']): ?>
    <div style="background:#1e1e1e;border-left:3px solid <?php echo $current_info['colour']; ?>;padding:10px 16px;font-size:0.9rem;color:#ddd;margin-bottom:16px;">
      <?php echo htmlspecialchars($state['message']); ?>
    </div>
    <?php endif; ?>

    <div class="sc-stat-grid" style="grid-template-columns:repeat(5,1fr);margin-bottom:20px;">
      <div class="sc-stat">
        <div class="sc-stat__val"><?php echo $total_reports; ?></div>
        <div class="sc-stat__label">Total Reports</div>
      </div>
      <div class="sc-stat">
        <div class="sc-stat__val" style="<?php echo $unreviewed > 0 ? 'color:#cc9900' : ''; ?>"><?php echo $unreviewed; ?></div>
        <div class="sc-stat__label">Unreviewed</div>
      </div>
      <div class="sc-stat">
        <div class="sc-stat__val"><?php echo $last_24h; ?></div>
        <div class="sc-stat__label">Last 24h</div>
      </div>
      <div class="sc-stat">
        <div class="sc-stat__val" style="<?php echo $distinct_2h >= 5 ? 'color:#ffcc00' : ($distinct_2h >= 2 ? 'color:#cc9900' : ''); ?>"><?php echo $distinct_2h; ?></div>
        <div class="sc-stat__label">Distinct IPs (2h)</div>
      </div>
      <div class="sc-stat">
        <div class="sc-stat__val" style="color:#5c9cff;"><?php echo $push_sub_count; ?></div>
        <div class="sc-stat__label">Push Subscribers</div>
      </div>
    </div>

    <?php if ($distinct_2h >= 2 && $state['level'] !== 'yellow_fast'): ?>
    <div class="sc-alert sc-alert--warn" style="margin-bottom:16px;">
      &#9888; <?php echo $distinct_2h; ?> distinct IP(s) have reported in the last 2 hours.
      <?php if ($distinct_2h >= 5): ?>
        Auto-escalation threshold (5) reached &mdash; consider confirming yellow_fast.
      <?php else: ?>
        Auto-escalation threshold (5) not yet reached.
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ── SET LEVEL ──────────────────────────────────────────────────────────── -->
<div class="sc-box">
  <div class="sc-box-header"><span class="sc-box-title">Set Alert Level</span></div>
  <div class="sc-box-body">
    <form method="post">
      <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <button type="submit" name="set_level" value="1"
                onclick="this.form.querySelector('[name=level]').value='green'"
                class="sc-btn" style="border-color:#5a9a5a;color:#5a9a5a;">
          &#9679; Set GREEN
        </button>
        <button type="submit" name="set_level" value="1"
                onclick="this.form.querySelector('[name=level]').value='yellow_slow'"
                class="sc-btn" style="border-color:#cc9900;color:#cc9900;">
          &#9679; Set YELLOW (slow)
        </button>
        <button type="submit" name="set_level" value="1"
                onclick="this.form.querySelector('[name=level]').value='yellow_fast'"
                class="sc-btn" style="border-color:#ffcc00;color:#ffcc00;">
          &#9679; Set YELLOW (fast)
        </button>
      </div>
      <input type="hidden" name="level" value="<?php echo htmlspecialchars($state['level']); ?>">
      <div style="margin-bottom:12px;">
        <label style="display:block;margin-bottom:6px;font-size:0.88rem;color:#aaa;">
          Message shown in opted-in admin panels (leave blank for default):
        </label>
        <input type="text" name="message"
               value="<?php echo htmlspecialchars($state['message']); ?>"
               placeholder="e.g. Coordinated breach activity detected. Check your file integrity."
               style="width:100%;max-width:600px;">
      </div>
    </form>

    <form method="post" style="margin-top:8px;">
      <button type="submit" name="run_auto_check" value="1" class="sc-btn">Run Auto-Escalation Check</button>
    </form>
  </div>
</div>

<!-- ── BREACH REPORTS ─────────────────────────────────────────────────────── -->
<div class="sc-box">
  <div class="sc-box-header">
    <span class="sc-box-title">Breach Reports</span>
    <?php if ($unreviewed > 0): ?>
      <form method="post" style="display:inline;">
        <button type="submit" name="mark_all_reviewed" value="1" class="sc-btn sc-btn--sm">Mark All Reviewed</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="sc-box-body" style="padding:0;">
    <?php if (empty($reports)): ?>
      <div style="padding:20px;color:#666;">No breach reports received yet.</div>
    <?php else: ?>
      <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
        <thead>
          <tr style="border-bottom:1px solid #2a2a2a;">
            <th style="padding:10px 16px;text-align:left;color:#666;font-weight:600;">Site</th>
            <th style="padding:10px 16px;text-align:left;color:#666;font-weight:600;">IP</th>
            <th style="padding:10px 16px;text-align:left;color:#666;font-weight:600;">Files</th>
            <th style="padding:10px 16px;text-align:left;color:#666;font-weight:600;">Received</th>
            <th style="padding:10px 16px;text-align:left;color:#666;font-weight:600;">Status</th>
            <th style="padding:10px 16px;"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($reports as $r): ?>
          <tr style="border-bottom:1px solid #1e1e1e;<?php echo !$r['reviewed'] ? 'background:#1a1a12;' : ''; ?>">
            <td style="padding:8px 16px;">
              <div style="font-weight:<?php echo $r['reviewed'] ? '400' : '600'; ?>;color:<?php echo $r['reviewed'] ? '#888' : '#ddd'; ?>;">
                <?php echo htmlspecialchars($r['site_name'] ?: '—'); ?>
              </div>
              <?php if ($r['site_url']): ?>
              <div style="font-size:0.78rem;color:#555;"><?php echo htmlspecialchars($r['site_url']); ?></div>
              <?php endif; ?>
            </td>
            <td style="padding:8px 16px;font-family:monospace;color:#888;"><?php echo htmlspecialchars($r['request_ip']); ?></td>
            <td style="padding:8px 16px;color:<?php echo $r['file_count'] > 0 ? '#cc9900' : '#555'; ?>;">
              <?php echo (int)$r['file_count']; ?>
            </td>
            <td style="padding:8px 16px;color:#666;white-space:nowrap;"><?php echo htmlspecialchars($r['received_at']); ?></td>
            <td style="padding:8px 16px;">
              <?php if ($r['reviewed']): ?>
                <span style="color:#5a9a5a;font-size:0.8rem;">&#10003; reviewed</span>
              <?php else: ?>
                <span style="color:#cc9900;font-size:0.8rem;font-weight:700;">NEW</span>
              <?php endif; ?>
            </td>
            <td style="padding:8px 16px;">
              <?php if (!$r['reviewed']): ?>
              <form method="post" style="display:inline;">
                <input type="hidden" name="report_id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" name="mark_reviewed" value="1" class="sc-btn sc-btn--sm">Review</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<!-- ── PUSH SUBSCRIBERS ───────────────────────────────────────────────────── -->
<div class="sc-box">
  <div class="sc-box-header">
    <span class="sc-box-title">Push Subscribers</span>
    <span class="sc-dim" style="font-size:0.8rem;"><?php echo $push_sub_count; ?> active<?php echo $push_fail_count > 0 ? " &bull; {$push_fail_count} failed (auto-pruned at 5 failures)" : ''; ?></span>
    <?php if ($push_sub_count > 0): ?>
    <form method="post" style="display:inline;margin-left:auto;">
      <button type="submit" name="manual_push" value="1" class="sc-btn">
        &#9654; Push Current Level Now
      </button>
    </form>
    <?php endif; ?>
  </div>
  <div class="sc-box-body" style="padding:0;">
    <?php if (empty($push_subs)): ?>
      <div style="padding:20px;color:#666;">No subscribers registered yet. Installs opt in via Admin → SMACKBACK → Network Alert.</div>
    <?php else: ?>
      <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
        <thead>
          <tr style="border-bottom:1px solid #2a2a2a;">
            <th style="padding:10px 16px;text-align:left;color:#666;font-weight:600;">Site</th>
            <th style="padding:10px 16px;text-align:left;color:#666;font-weight:600;">Registered</th>
            <th style="padding:10px 16px;text-align:left;color:#666;font-weight:600;">Last Push</th>
            <th style="padding:10px 16px;text-align:left;color:#666;font-weight:600;">Failures</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($push_subs as $s): ?>
          <tr style="border-bottom:1px solid #1e1e1e;<?php echo $s['push_failures'] >= 3 ? 'background:#1a0000;' : ''; ?>">
            <td style="padding:8px 16px;">
              <div style="font-weight:600;color:#ddd;"><?php echo htmlspecialchars($s['site_name'] ?: '—'); ?></div>
              <div style="font-size:0.78rem;color:#555;"><?php echo htmlspecialchars($s['site_url']); ?></div>
            </td>
            <td style="padding:8px 16px;color:#666;white-space:nowrap;"><?php echo htmlspecialchars($s['registered_at']); ?></td>
            <td style="padding:8px 16px;color:#666;white-space:nowrap;"><?php echo $s['last_push_at'] ? htmlspecialchars($s['last_push_at']) : '<span style="color:#444;">Never</span>'; ?></td>
            <td style="padding:8px 16px;">
              <?php if ($s['push_failures'] === 0): ?>
                <span style="color:#5a9a5a;">&#10003;</span>
              <?php elseif ($s['push_failures'] < 3): ?>
                <span style="color:#cc9900;"><?php echo (int)$s['push_failures']; ?></span>
              <?php else: ?>
                <span style="color:#e45735;font-weight:700;"><?php echo (int)$s['push_failures']; ?> &#9888;</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<!-- ── MAINTENANCE ────────────────────────────────────────────────────────── -->
<div class="sc-box">
  <div class="sc-box-header"><span class="sc-box-title">Maintenance</span></div>
  <div class="sc-box-body">
    <form method="post" style="display:flex;align-items:center;gap:12px;"
          onsubmit="return confirm('Delete old reports?');">
      <label style="font-size:0.88rem;color:#aaa;">Clear reports older than</label>
      <input type="number" name="clear_days" value="30" min="1" max="365" style="width:70px;">
      <label style="font-size:0.88rem;color:#aaa;">days</label>
      <button type="submit" name="clear_old" value="1" class="sc-btn">Clear Old Reports</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
<?php // ===== SNAPSMACK EOF =====
