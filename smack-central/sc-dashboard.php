<?php
/**
 * SMACK CENTRAL - Dashboard
 */

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-dashboard.php';
$sc_page_title = 'Dashboard';

// ── POST: Maintenance — rebuild fleet count (purge phone-home table) ──
// Clears sc_phone_home so stale spoke rows (recorded before the spoke-skip
// fix) are removed. Live hubs + standalone installs re-ping on their next
// update check; spokes no longer ping, so the rebuilt count is accurate.
$sc_maint_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['maint_action'] ?? '') === 'purge_phone_home') {
    try {
        sc_db()->exec("TRUNCATE TABLE sc_phone_home");
        $msg = 'ok|Phone-home table cleared. Active Installs will rebuild as installs check in.';
    } catch (Exception $e) {
        $msg = 'err|Could not clear table: ' . $e->getMessage();
    }
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['sc_dash_maint'] = $msg;
    header('Location: sc-dashboard.php?maint=1');
    exit;
}
if (!empty($_GET['maint'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['sc_dash_maint'])) {
        $sc_maint_msg = $_SESSION['sc_dash_maint'];
        unset($_SESSION['sc_dash_maint']);
    }
}

// Quick stats
$stats = ['installs' => 0, 'threads' => 0, 'replies' => 0, 'releases' => 0,
          'thomas_y' => 0, 'thomas_z' => 0, 'thomas_unique' => 0, 'thomas_sites' => 0];
try {
    // Active installs: sum of (1 per pinging install) + spoke counts reported by hubs.
    // A hub with 5 active spokes contributes 6 to the fleet total.
    $stats['installs'] = (int)sc_db()->query(
        "SELECT COALESCE(SUM(1 + spoke_count), 0) FROM sc_phone_home WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
    )->fetchColumn();
} catch (Exception $e) { /* sc_phone_home not yet created — shows 0 until first ping lands */ }
try {
    $fdb = sc_forum_db();
    $stats['threads']  = (int)$fdb->query("SELECT COUNT(*) FROM ss_forum_threads  WHERE is_deleted = 0")->fetchColumn();
    $stats['replies']  = (int)$fdb->query("SELECT COUNT(*) FROM ss_forum_replies  WHERE is_deleted = 0")->fetchColumn();
} catch (Exception $e) { /* forum tables may not exist yet */ }
try {
    $stats['releases'] = (int)sc_db()->query("SELECT COUNT(*) FROM sc_releases")->fetchColumn();
} catch (Exception $e) {}
try {
    // Thomas the Bear discover stats — network totals across all pinging installs.
    // sc_thomas is populated by releases/thomas-ping.php.
    $thomas = sc_db()->query(
        "SELECT COALESCE(SUM(y_presses),0), COALESCE(SUM(z_presses),0), COALESCE(SUM(unique_finders),0), COUNT(*) FROM sc_thomas"
    )->fetch(PDO::FETCH_NUM);
    $stats['thomas_y']      = (int)$thomas[0];
    $stats['thomas_z']      = (int)$thomas[1];
    $stats['thomas_unique'] = (int)$thomas[2];
    $stats['thomas_sites']  = (int)$thomas[3];
} catch (Exception $e) { /* sc_thomas not yet created */ }

$latest_release = null;
try {
    $latest_release = sc_db()->query("SELECT version_full, released_at FROM sc_releases WHERE is_latest = 1 LIMIT 1")->fetch();
} catch (Exception $e) {}

require __DIR__ . '/sc-layout-top.php';
?>

<div class="sc-page-header">
  <span class="sc-page-title">Dashboard</span>
  <?php if ($latest_release): ?>
  <span class="sc-dim">Latest release: <?php echo htmlspecialchars($latest_release['version_full']); ?>
    &nbsp;&bull;&nbsp;<?php echo htmlspecialchars($latest_release['released_at']); ?></span>
  <?php endif; ?>
</div>

<div class="sc-stat-grid">
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['installs']; ?></div>
    <div class="sc-stat__label">Active Installs</div>
  </div>
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['threads']; ?></div>
    <div class="sc-stat__label">Forum Threads</div>
  </div>
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['replies']; ?></div>
    <div class="sc-stat__label">Forum Replies</div>
  </div>
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['releases']; ?></div>
    <div class="sc-stat__label">Releases Shipped</div>
  </div>
</div>

<?php if ($stats['thomas_y'] > 0 || $stats['thomas_z'] > 0): ?>
<div class="sc-stat-grid" style="margin-top:8px;">
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['thomas_y']; ?></div>
    <div class="sc-stat__label">Bears Spawned</div>
  </div>
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['thomas_z']; ?></div>
    <div class="sc-stat__label">Story Reads</div>
  </div>
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['thomas_unique']; ?></div>
    <div class="sc-stat__label">Unique Finders</div>
  </div>
  <div class="sc-stat">
    <div class="sc-stat__val"><?php echo $stats['thomas_sites']; ?></div>
    <div class="sc-stat__label">Sites w/ Discovers</div>
  </div>
</div>
<?php endif; ?>

<div class="sc-box">
  <div class="sc-box-header"><span class="sc-box-title">Quick Links</span></div>
  <div class="sc-box-body">
    <div class="sc-btn-row">
      <a href="sc-release.php" class="sc-btn">Release Packager</a>
      <a href="sc-forum.php"   class="sc-btn">Forum Admin</a>
    </div>
  </div>
</div>

<div class="sc-box">
  <div class="sc-box-header"><span class="sc-box-title">Maintenance</span></div>
  <div class="sc-box-body">
    <?php if ($sc_maint_msg):
        [$mtype, $mtext] = array_pad(explode('|', $sc_maint_msg, 2), 2, ''); ?>
      <p style="margin-top:0;color:<?php echo $mtype === 'ok' ? '#5a9a5a' : '#cc2200'; ?>;">
        <?php echo htmlspecialchars($mtext); ?></p>
    <?php endif; ?>
    <p class="sc-dim">Rebuild the Active Installs tally. Clears the phone-home table so
       stale spoke rows are removed; live hubs and standalone installs re-populate it on
       their next update check. Spokes are excluded by design and will not be double-counted.</p>
    <form method="post" onsubmit="return confirm('Clear the phone-home table and rebuild the fleet count?\n\nActive Installs will read low until installs check back in.');">
      <input type="hidden" name="maint_action" value="purge_phone_home">
      <button type="submit" class="sc-btn">Rebuild Fleet Count</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
<?php // ===== SNAPSMACK EOF =====
