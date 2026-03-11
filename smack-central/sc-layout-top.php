<?php
/**
 * SMACK CENTRAL - Page top (header + sidebar)
 *
 * Set $sc_active_nav to the current page filename before requiring this file.
 * e.g. $sc_active_nav = 'sc-release.php';
 */
$sc_active_nav = $sc_active_nav ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($sc_page_title) ? htmlspecialchars($sc_page_title) . ' — ' : ''; ?>SMACK CENTRAL</title>
<link rel="stylesheet" href="assets/css/sc-geometry.css">
<link rel="stylesheet" href="assets/css/sc-admin.css">
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
      <span class="sc-nav-label" style="margin-top:16px;">Community</span>
      <a href="sc-forum.php"
         class="<?php echo $sc_active_nav === 'sc-forum.php'     ? 'active' : ''; ?>">Forum Admin</a>
    </nav>
    <div class="sc-sidebar-bottom">
      <a href="sc-logout.php">Log Out</a>
    </div>
  </aside>

  <main class="sc-main">
