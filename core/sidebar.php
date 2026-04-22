<?php
/**
 * SNAPSMACK - Admin Sidebar Navigation
 *
 * Accordion-style sidebar with four collapsible sections.
 * "The Good Shit" opens by default; whichever section contains
 * the current page auto-opens instead when navigating.
 * Only one section is open at a time.
 */

$current_page = basename($_SERVER['PHP_SELF']);

// --- UI MODE ---
$_ui_pimpmobile = ($settings['ui_mode'] ?? 'bigwheel') === 'pimpmobile';

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
    'good-shit'  => ['smack-admin.php','smack-post.php','smack-manage.php','smack-edit.php','smack-cats.php','smack-albums.php','smack-media.php','smack-gallery.php','smack-comments.php','smack-blogroll.php','smack-pages.php','smack-community-settings.php','smack-community-users.php','smack-tools.php'],
    'pimp'       => ['smack-globalvibe.php','smack-skin.php','smack-pimpotron.php','smack-social-dock.php','smack-css.php','smack-scripts.php','smack-appearance-archive.php','smack-appearance-solo.php','smack-appearance-static.php'],
    'boring'     => ['smack-settings.php','smack-users.php','smack-maintenance.php','smack-fingerprints.php','smack-backup.php','smack-disaster.php','smack-ftp.php','smack-cloud.php','smack-verify.php','smack-update.php','smack-stats.php','smack-api-keys.php','smack-multisite.php','smack-multisite-comments.php','smack-multisite-posts.php','smack-multisite-backup.php','smack-multisite-stats.php','smack-multisite-crosspost.php','smack-multisite-blogroll.php'],
    'help'       => ['smack-help.php','smack-forum.php'],
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
                    <?php if ($_ui_pimpmobile): ?>
                    <li class="<?php echo ($current_page == 'smack-media.php') ? 'active' : ''; ?>">
                        <a href="smack-media.php">Media Library</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-gallery.php') ? 'active' : ''; ?>">
                        <a href="smack-gallery.php">Media Gallery</a>
                    </li>
                    <?php endif; ?>

                    <li class="<?php echo ($current_page == 'smack-comments.php') ? 'active' : ''; ?>">
                        <a href="smack-comments.php">Signals</a>
                    </li>

                    <?php if ($_ui_pimpmobile): ?>
                    <li class="<?php echo ($current_page == 'smack-blogroll.php') ? 'active' : ''; ?>">
                        <a href="smack-blogroll.php">Blogroll</a>
                    </li>
                    <li class="<?php echo in_array($current_page, ['smack-community-settings.php','smack-community-users.php']) ? 'active' : ''; ?>">
                        <a href="smack-community-settings.php">Interaction</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-pages.php') ? 'active' : ''; ?>">
                        <a href="smack-pages.php">Static Pages</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-tools.php') ? 'active' : ''; ?>">
                        <a href="smack-tools.php">Companion Tools</a>
                    </li>
                    <?php endif; ?>
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
                    <?php if ($_ui_pimpmobile && $_sidebar_pimpotron): ?>
                    <li class="<?php echo ($current_page == 'smack-pimpotron.php') ? 'active' : ''; ?>">
                        <a href="smack-pimpotron.php">Pimpotron</a>
                    </li>
                    <?php endif; ?>
                    <?php if ($_ui_pimpmobile): ?>
                    <li class="<?php echo ($current_page == 'smack-social-dock.php') ? 'active' : ''; ?>">
                        <a href="smack-social-dock.php">Social Dock</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-css.php') ? 'active' : ''; ?>">
                        <a href="smack-css.php">Smack Your CSS Up!</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-scripts.php') ? 'active' : ''; ?>">
                        <a href="smack-scripts.php">Smack Your Scripts Up!</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-appearance-archive.php') ? 'active' : ''; ?>">
                        <a href="smack-appearance-archive.php">Archive Appearance</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-appearance-solo.php') ? 'active' : ''; ?>">
                        <a href="smack-appearance-solo.php">Solo Image Appearance</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-appearance-static.php') ? 'active' : ''; ?>">
                        <a href="smack-appearance-static.php">Static Page Appearance</a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- SECTION 3: Boring Ass Stuff (Pimpmobile) / Settings (Big Wheel) -->
            <div class="nav-section<?php echo ($_active_section === 'boring') ? ' open' : ''; ?>" data-section="boring">
                <button type="button" class="nav-section-toggle">
                    <span class="nav-section-label"><?php echo $_ui_pimpmobile ? 'Boring Ass Stuff' : 'Settings'; ?></span>
                    <span class="nav-section-arrow"></span>
                </button>
                <ul class="nav-section-links">
                    <li class="<?php echo ($current_page == 'smack-settings.php') ? 'active' : ''; ?>">
                        <a href="smack-settings.php">Configuration</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-update.php') ? 'active' : ''; ?>">
                        <a href="smack-update.php">System Updates</a>
                    </li>
                    <?php if ($_ui_pimpmobile): ?>
                    <li class="<?php echo ($current_page == 'smack-users.php') ? 'active' : ''; ?>">
                        <a href="smack-users.php">User Manager</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-maintenance.php') ? 'active' : ''; ?>">
                        <a href="smack-maintenance.php">Maintenance</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-fingerprints.php') ? 'active' : ''; ?>">
                        <a href="smack-fingerprints.php">Troll Control</a>
                    </li>
                    <li class="<?php echo in_array($current_page, ['smack-backup.php','smack-ftp.php','smack-verify.php']) ? 'active' : ''; ?>">
                        <a href="smack-backup.php">Backup &amp; Recovery</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-cloud.php') ? 'active' : ''; ?>">
                        <a href="smack-cloud.php">Cloud Backup</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-disaster.php') ? 'active' : ''; ?>">
                        <a href="smack-disaster.php">Disaster Recovery</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-stats.php') ? 'active' : ''; ?>">
                        <a href="smack-stats.php">Traffic Stats</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-api-keys.php') ? 'active' : ''; ?>">
                        <a href="smack-api-keys.php">Oh Snap! API Keys</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-multisite.php') ? 'active' : ''; ?>">
                        <a href="smack-multisite.php">Multisite Management</a>
                    </li>
                    <?php if (!empty($settings['multisite_role'])) : ?>
                    <li class="<?php echo ($current_page == 'smack-multisite-comments.php') ? 'active' : ''; ?>">
                        <a href="smack-multisite-comments.php">Spoke Signals</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-multisite-posts.php') ? 'active' : ''; ?>">
                        <a href="smack-multisite-posts.php">Spoke Posts</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-multisite-backup.php') ? 'active' : ''; ?>">
                        <a href="smack-multisite-backup.php">Backup Dock</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-multisite-stats.php') ? 'active' : ''; ?>">
                        <a href="smack-multisite-stats.php">Fleet Stats</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-multisite-crosspost.php') ? 'active' : ''; ?>">
                        <a href="smack-multisite-crosspost.php">Cross-Post</a>
                    </li>
                    <li class="<?php echo ($current_page == 'smack-multisite-blogroll.php') ? 'active' : ''; ?>">
                        <a href="smack-multisite-blogroll.php">Blogroll Sync</a>
                    </li>
                    <?php endif; // multisite ?>
                    <?php endif; // pimpmobile ?>
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
                    <li class="<?php echo ($current_page == 'smack-forum.php') ? 'active' : ''; ?>">
                        <a href="smack-forum.php">Community Forum</a>
                    </li>
                </ul>
            </div>

        </nav>

        <!-- MODE TOGGLE — outside accordion, between nav and sidebar-bottom -->
        <form method="POST" action="smack-admin.php" class="mode-toggle-form">
            <input type="hidden" name="pimpmobile_action"
                   value="<?php echo $_ui_pimpmobile ? 'switch_to_bigwheel' : 'switch_to_pimpmobile'; ?>">
            <button type="submit" class="mode-toggle-btn">
                <?php echo $_ui_pimpmobile ? 'Switch to Big Wheel' : 'Unlock Pimpmobile'; ?>
            </button>
        </form>

    </div>

    <div class="sidebar-bottom">
        <a href="logout.php" class="logout">Logout</a>
        <div class="credits-admin">&copy; 2026 Sean McCormick</div>
    </div>
</div>