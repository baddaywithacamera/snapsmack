<?php
/**
 * SNAPSMACK - Admin Sidebar Navigation
 * Alpha v0.7
 *
 * Renders the admin dashboard sidebar with sections for content management,
 * skin customization, and system configuration. The Pimpotron link appears
 * conditionally based on the active skin's manifest declaration.
 */

$current_page = basename($_SERVER['PHP_SELF']);

// --- CONDITIONAL PIMPOTRON DETECTION ---
// Check if the active skin declares support for the Pimpotron engine
$_sidebar_pimpotron = false;
if (!empty($settings['active_skin'])) {
    $_sidebar_skin_slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $settings['active_skin']);
    $_sidebar_manifest_path = "skins/{$_sidebar_skin_slug}/manifest.php";
    if (file_exists($_sidebar_manifest_path)) {
        $_sidebar_manifest = include $_sidebar_manifest_path;
        $_sidebar_pimpotron = !empty($_sidebar_manifest['engines']['pimpotron']);
    }
}
?>

<div class="sidebar">
    <div class="sidebar-top">
        <h2>SnapSmack</h2>
        <ul>
            <li class="nav-group">
                <strong>The Good Shit</strong>
                <ul class="sub-nav">
                    <li class="<?php echo ($current_page == 'smack-admin.php') ? 'active' : ''; ?>">
                        <a href="smack-admin.php">Dashboard</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-post.php') ? 'active' : ''; ?>">
                        <a href="smack-post.php">New Post</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-manage.php' || $current_page == 'smack-edit.php') ? 'active' : ''; ?>">
                        <a href="smack-manage.php">Manage Archive</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-cats.php') ? 'active' : ''; ?>">
                        <a href="smack-cats.php">Categories</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-albums.php') ? 'active' : ''; ?>">
                        <a href="smack-albums.php">Albums</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-media.php') ? 'active' : ''; ?>">
                        <a href="smack-media.php">Media Library</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-comments.php') ? 'active' : ''; ?>">
                        <a href="smack-comments.php">Signals</a>
                    </li>
                </ul>
            </li>

            <li class="nav-group">
                <strong>Pimp Your Ride</strong>
                <ul class="sub-nav">
                    <li class="<?php echo ($current_page == 'smack-globalvibe.php') ? 'active' : ''; ?>">
                        <a href="smack-globalvibe.php">Global Vibe</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-skin.php') ? 'active' : ''; ?>">
                        <a href="smack-skin.php">Smooth Your Skin</a>
                    </li>
                    <?php if ($_sidebar_pimpotron): ?>
                    <li class="<?php echo ($current_page == 'smack-pimpotron.php') ? 'active' : ''; ?>">
                        <a href="smack-pimpotron.php">Pimpotron</a>
                    </li>
                    <?php endif; ?>
                    <li class="<?php echo ($current_page == 'smack-css.php') ? 'active' : ''; ?>">
                        <a href="smack-css.php">Smack Your CSS Up!</a>
                    </li>
                </ul>
            </li>

            <li class="nav-group">
                <strong>Boring Ass Stuff</strong>
                <ul class="sub-nav">
                    <li class="<?php echo ($current_page == 'smack-config.php') ? 'active' : ''; ?>">
                        <a href="smack-config.php">Configuration</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-users.php') ? 'active' : ''; ?>">
                        <a href="smack-users.php">User Manager</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-maintenance.php') ? 'active' : ''; ?>">
                        <a href="smack-maintenance.php">Maintenance</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-backup.php') ? 'active' : ''; ?>">
                        <a href="smack-backup.php">Backup & Recovery</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-update.php') ? 'active' : ''; ?>">
                        <a href="smack-update.php">System Updates</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-help.php') ? 'active' : ''; ?>">
                        <a href="smack-help.php">Man Pages</a>
                    </li>
                </ul>
            </li>
        </ul>
    </div>

    <div class="sidebar-bottom">
        <a href="logout.php" class="logout">Logout</a>
        <div class="credits-admin">&copy; 2026 Sean McCormick</div>
    </div>
</div>
