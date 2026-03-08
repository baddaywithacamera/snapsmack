<?php
/**
 * SNAPSMACK - Admin Sidebar Navigation
 * Alpha v0.7
 *
 * Accordion-style sidebar with four collapsible sections.
 * "The Good Shit" opens by default; whichever section contains
 * the current page auto-opens instead when navigating.
 * Only one section is open at a time.
 */

$current_page = basename($_SERVER['PHP_SELF']);

// --- CONDITIONAL PIMPOTRON DETECTION ---
$_sidebar_pimpotron = false;
if (!empty($settings['active_skin'])) {
    $_sidebar_skin_slug = preg_replace('/[^a-zA-Z0-9_-]/', '', $settings['active_skin']);
    $_sidebar_manifest_path = "skins/{$_sidebar_skin_slug}/manifest.php";
    if (file_exists($_sidebar_manifest_path)) {
        $_sidebar_manifest = include $_sidebar_manifest_path;
        $_sidebar_pimpotron = !empty($_sidebar_manifest['engines']['pimpotron']);
    }
}

// --- SECTION / PAGE MAP ---
// Determine which accordion section to auto-open based on the current page.
$_section_map = [
    'good-shit'  => ['smack-admin.php','smack-post.php','smack-manage.php','smack-edit.php','smack-cats.php','smack-albums.php','smack-media.php','smack-comments.php','smack-pages.php'],
    'pimp'       => ['smack-globalvibe.php','smack-skin.php','smack-pimpotron.php','smack-social-dock.php','smack-css.php'],
    'boring'     => ['smack-config.php','smack-users.php','smack-maintenance.php','smack-backup.php','smack-ftp.php','smack-cloud.php','smack-verify.php','smack-update.php'],
    'help'       => ['smack-help.php'],
];
$_active_section = 'good-shit'; // default
foreach ($_section_map as $sec => $_sec_pages) {
    if (in_array($current_page, $_sec_pages)) {
        $_active_section = $sec;
        break;
    }
}
?>

<div class="sidebar">
    <div class="sidebar-top">
        <a href="smack-admin.php" class="sidebar-brand">SnapSmack</a>

        <nav class="sidebar-accordion">

            <!-- SECTION 1: The Good Shit -->
            <div class="nav-section<?php echo ($_active_section === 'good-shit') ? ' open' : ''; ?>" data-section="good-shit">
                <button type="button" class="nav-section-toggle">
                    <span class="nav-section-label">The Good Shit</span>
                    <span class="nav-section-arrow"></span>
                </button>
                <ul class="nav-section-links">
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
                    <li class="<?php echo ($current_page == 'smack-pages.php') ? 'active' : ''; ?>">
                        <a href="smack-pages.php">Static Pages</a>
                    </li>
                </ul>
            </div>

            <!-- SECTION 2: Pimp Your Ride -->
            <div class="nav-section<?php echo ($_active_section === 'pimp') ? ' open' : ''; ?>" data-section="pimp">
                <button type="button" class="nav-section-toggle">
                    <span class="nav-section-label">Pimp Your Ride</span>
                    <span class="nav-section-arrow"></span>
                </button>
                <ul class="nav-section-links">
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
                    <li class="<?php echo ($current_page == 'smack-social-dock.php') ? 'active' : ''; ?>">
                        <a href="smack-social-dock.php">Social Dock</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-css.php') ? 'active' : ''; ?>">
                        <a href="smack-css.php">Smack Your CSS Up!</a>
                    </li>
                </ul>
            </div>

            <!-- SECTION 3: Boring Ass Stuff -->
            <div class="nav-section<?php echo ($_active_section === 'boring') ? ' open' : ''; ?>" data-section="boring">
                <button type="button" class="nav-section-toggle">
                    <span class="nav-section-label">Boring Ass Stuff</span>
                    <span class="nav-section-arrow"></span>
                </button>
                <ul class="nav-section-links">
                    <li class="<?php echo ($current_page == 'smack-config.php') ? 'active' : ''; ?>">
                        <a href="smack-config.php">Configuration</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-users.php') ? 'active' : ''; ?>">
                        <a href="smack-users.php">User Manager</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-maintenance.php') ? 'active' : ''; ?>">
                        <a href="smack-maintenance.php">Maintenance</a>
                    </li>
                    <li class="<?php echo in_array($current_page, ['smack-backup.php','smack-ftp.php','smack-cloud.php','smack-verify.php']) ? 'active' : ''; ?>">
                        <a href="smack-backup.php">Backup &amp; Recovery</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-update.php') ? 'active' : ''; ?>">
                        <a href="smack-update.php">System Updates</a>
                    </li>
                </ul>
            </div>

            <!-- SECTION 4: Help -->
            <div class="nav-section<?php echo ($_active_section === 'help') ? ' open' : ''; ?>" data-section="help">
                <button type="button" class="nav-section-toggle">
                    <span class="nav-section-label">Help, I Need Somebody!</span>
                    <span class="nav-section-arrow"></span>
                </button>
                <ul class="nav-section-links">
                    <li class="<?php echo ($current_page == 'smack-help.php') ? 'active' : ''; ?>">
                        <a href="smack-help.php">User Manual</a>
                    </li>
                </ul>
            </div>

        </nav>
    </div>

    <div class="sidebar-bottom">
        <a href="logout.php" class="logout">Logout</a>
        <div class="credits-admin">&copy; 2026 Sean McCormick</div>
    </div>
</div>
</content>
</invoke>