<?php
/**
 * SMACK CENTRAL - Dashboard
 * Alpha v0.7.3a
 */

require_once __DIR__ . '/sc-auth.php';
$sc_active_nav = 'sc-dashboard.php';
$sc_page_title = 'Dashboard';

// Quick stats from the forum db
$stats = ['installs' => 0, 'threads' => 0, 'replies' => 0, 'releases' => 0];
try {
    $db = sc_db();
    $stats['installs'] = (int)$db->query("SELECT COUNT(*) FROM ss_forum_installs WHERE is_banned = 0")->fetchColumn();
    $stats['threads']  = (int)$db->query("SELECT COUNT(*) FROM ss_forum_threads  WHERE is_deleted = 0")->fetchColumn();
    $stats['replies']  = (int)$db->query("SELECT COUNT(*) FROM ss_forum_replies  WHERE is_deleted = 0")->fetchColumn();
    $stats['releases'] = (int)$db->query("SELECT COUNT(*) FROM sc_releases")->fetchColumn();
} catch (Exception $e) { /* tables may not exist yet */ }

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
