<?php
/**
 * SMACK CENTRAL - Dashboard
 */

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-dashboard.php';
$sc_page_title = 'Dashboard';

// Quick stats
$stats = ['installs' => 0, 'threads' => 0, 'replies' => 0, 'releases' => 0];
try {
    // Active installs: unique UIDs that phoned home in the last 90 days
    $stats['installs'] = (int)sc_db()->query(
        "SELECT COUNT(*) FROM sc_phone_home WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
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

<div class="sc-box">
  <div class="sc-box-header"><span class="sc-box-title">Quick Links</span></div>
  <div class="sc-box-body">
    <div class="sc-btn-row">
      <a href="sc-release.php" class="sc-btn">Release Packager</a>
      <a href="sc-forum.php"   class="sc-btn">Forum Admin</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/sc-layout-bottom.php'; ?>
<?php // ===== SNAPSMACK EOF =====
