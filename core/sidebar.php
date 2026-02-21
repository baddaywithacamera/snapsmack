<?php
/**
 * SnapSmack - Sidebar Navigation
 * Version: 7.1 - Trinity Structure Sync
 */

$current_page = basename($_SERVER['PHP_SELF']); 
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
					<li class="<?php echo ($current_page == 'smack-blogroll.php') ? 'active' : ''; ?>">
                        <a href="smack-blogroll.php">Blogroll</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-comments.php') ? 'active' : ''; ?>">
                        <a href="smack-comments.php">Transmissions</a>
                    </li>
                </ul>
            </li>

            <li class="nav-group">
                <strong>Pimp Your Ride</strong>
                <ul class="sub-nav">
                    <li class="<?php echo ($current_page == 'smack-pimpitup.php') ? 'active' : ''; ?>">
                        <a href="smack-pimpitup.php">Global Vibe</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-skin.php') ? 'active' : ''; ?>">
                        <a href="smack-skin.php">Smooth Your Skin</a>
                    </li>
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
                </ul>
            </li>
        </ul>
    </div>

    <div class="sidebar-bottom">
        <a href="logout.php" class="logout">Logout</a>
        <div class="credits-admin">© 2026 Sean McCormick</div>
    </div>
</div>