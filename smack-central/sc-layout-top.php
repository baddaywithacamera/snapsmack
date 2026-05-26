<?php
/**
 * SMACK CENTRAL - Page top (header + sidebar)
 *
 * Set $sc_active_nav to the current page filename before requiring this file.
 * e.g. $sc_active_nav = 'sc-release.php';
 */
$sc_active_nav = $sc_active_nav ?? '';
if (file_exists(__DIR__ . '/sc-version.php')) require_once __DIR__ . '/sc-version.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($sc_page_title) ? htmlspecialchars($sc_page_title) . ' — ' : ''; ?>SMACK CENTRAL</title>
<?php $sc_v = defined('SC_VERSION') ? SC_VERSION : '0'; ?>
<link rel="stylesheet" href="assets/css/sc-geometry.css?v=<?php echo $sc_v; ?>">
<link rel="stylesheet" href="assets/css/sc-colours.css?v=<?php echo $sc_v; ?>">
<link rel="stylesheet" href="assets/css/sc-admin.css?v=<?php echo $sc_v; ?>">
</head>
<body>
<div class="sc-shell">

  <aside class="sc-sidebar">
    <a href="sc-dashboard.php" class="sc-sidebar-brand">SMACK CENTRAL</a>
    <nav class="sc-nav">
      <span class="sc-nav-label">Operations</span>
      <a href="sc-dashboard.php"
         class="<?php echo $sc_active_nav === 'sc-dashboard.php' ? 'active' : ''; ?>">Dashboard</a>
      <a href="sc-release.php"
         class="<?php echo $sc_active_nav === 'sc-release.php'   ? 'active' : ''; ?>">Release Packager</a>
      <a href="sc-assets.php"
         class="<?php echo $sc_active_nav === 'sc-assets.php'    ? 'active' : ''; ?>">Asset Repository</a>
      <a href="sc-skins.php"
         class="<?php echo $sc_active_nav === 'sc-skins.php'     ? 'active' : ''; ?>">Skin Packager</a>
      <span class="sc-nav-label" style="margin-top:16px;">Community</span>
      <a href="sc-forum.php"
         class="<?php echo $sc_active_nav === 'sc-forum.php'         ? 'active' : ''; ?>">Forum Admin</a>
      <a href="sc-enemy-admin.php"
         class="<?php echo $sc_active_nav === 'sc-enemy-admin.php'   ? 'active' : ''; ?>">SMACKATTACK</a>
      <span class="sc-nav-label" style="margin-top:16px;">Security</span>
      <a href="sc-network-alert.php"
         class="<?php echo $sc_active_nav === 'sc-network-alert.php' ? 'active' : ''; ?>">Network Alert</a>
      <span class="sc-nav-label" style="margin-top:16px;">System</span>
      <a href="sc-update.php"
         class="<?php echo $sc_active_nav === 'sc-update.php'         ? 'active' : ''; ?>">Update</a>
      <a href="sc-schema.php"
         class="<?php echo $sc_active_nav === 'sc-schema.php'         ? 'active' : ''; ?>">Schema Manager</a>
      <a href="sc-help-release.php"
         class="<?php echo $sc_active_nav === 'sc-help-release.php'   ? 'active' : ''; ?>">Release Guide</a>
    </nav>
    <div class="sc-sidebar-bottom">
      <?php if (defined('SC_VERSION')): ?>
        <span class="sc-sidebar-version">v<?php echo htmlspecialchars(SC_VERSION); ?></span>
      <?php endif; ?>
      <a href="sc-logout.php">Log Out</a>
    </div>
  </aside>

  <main class="sc-main">
<?php // ===== SNAPSMACK EOF =====
