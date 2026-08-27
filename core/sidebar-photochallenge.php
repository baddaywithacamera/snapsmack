<?php
/**
 * SNAPSMACK - Photo Challenge Admin Sidebar
 *
 * Reduced navigation for the PHOTOFRI.DAY / photo-challenge CMS profile.
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$_pc_sections = [
    'good-shit' => [
        'smack-admin.php', 'smack-post-solo.php', 'smack-manage.php',
        'smack-edit.php', 'smack-comments.php', 'smack-pages.php',
    ],
    'smackverse' => [
        'smack-fediverse.php', 'smack-dms.php', 'smack-smackverse.php',
        'smack-sv-followers.php', 'smack-sv-tools.php',
    ],
    'pimp' => [
        'smack-globalvibe.php', 'smack-masthead.php', 'smack-skin.php',
        'smack-menu.php', 'smack-social-dock.php', 'smack-css.php',
        'smack-scripts.php', 'smack-appearance-archive.php',
        'smack-appearance-solo.php', 'smack-appearance-static.php',
    ],
    'turbo-boost' => ['smack-photochallenge.php'],
    'boring' => [
        'smack-settings.php', 'smack-users.php', 'smack-2fa.php',
        'smack-maintenance.php', 'smack-fingerprints.php', 'smack-backup.php',
        'smack-disaster.php', 'smack-break-glass.php', 'smack-stats.php',
        'smack-update.php', 'smack-schema.php', 'smack-api-keys.php',
        'smack-back.php', 'smack-multisite.php',
    ],
];
$_pc_active = 'good-shit';
foreach ($_pc_sections as $_pc_section => $_pc_pages) {
    if (in_array($current_page, $_pc_pages, true)) {
        $_pc_active = $_pc_section;
        break;
    }
}
$_pc_active_class = static fn(string $page): string => $current_page === $page ? 'active' : '';
?>

<div class="sidebar">
    <div class="sidebar-top">
        <a href="smack-admin.php" class="sidebar-brand">SnapSmack</a>

        <nav class="sidebar-accordion">
            <div class="nav-section<?php echo $_pc_active === 'good-shit' ? ' open' : ''; ?>" data-section="good-shit">
                <button type="button" class="nav-section-toggle">
                    <span class="nav-section-label">THE GOOD SHIT</span>
                    <span class="nav-section-arrow"></span>
                </button>
                <ul class="nav-section-links">
                    <li class="<?php echo $_pc_active_class('smack-admin.php'); ?>"><a href="smack-admin.php">Dashboard</a></li>
                    <li class="<?php echo $_pc_active_class('smack-post-solo.php'); ?>"><a href="smack-post-solo.php">New Post</a></li>
                    <li class="<?php echo in_array($current_page, ['smack-manage.php', 'smack-edit.php'], true) ? 'active' : ''; ?>"><a href="smack-manage.php">Manage Posts</a></li>
                    <li class="<?php echo $_pc_active_class('smack-comments.php'); ?>"><a href="smack-comments.php">Signals</a></li>
                    <li class="<?php echo $_pc_active_class('smack-pages.php'); ?>"><a href="smack-pages.php">Static Pages</a></li>
                </ul>
            </div>

            <div class="nav-section<?php echo $_pc_active === 'smackverse' ? ' open' : ''; ?>" data-section="smackverse">
                <button type="button" class="nav-section-toggle">
                    <span class="nav-section-label">FEDIVERSE</span>
                    <span class="nav-section-arrow"></span>
                </button>
                <ul class="nav-section-links">
                    <li><a href="pixel.php" target="_blank" rel="noopener">Pixelfed &#8599;</a></li>
                    <li class="<?php echo $_pc_active_class('smack-fediverse.php'); ?>"><a href="smack-fediverse.php">Interactions</a></li>
                    <li class="<?php echo $_pc_active_class('smack-dms.php'); ?>"><a href="smack-dms.php">Messages</a></li>
                    <li class="<?php echo $_pc_active_class('smack-smackverse.php'); ?>"><a href="smack-smackverse.php">Federation</a></li>
                    <li class="<?php echo $_pc_active_class('smack-sv-followers.php'); ?>"><a href="smack-sv-followers.php">Followers</a></li>
                    <li class="<?php echo $_pc_active_class('smack-sv-tools.php'); ?>"><a href="smack-sv-tools.php">Push &amp; Tools</a></li>
                </ul>
            </div>

            <div class="nav-section<?php echo $_pc_active === 'pimp' ? ' open' : ''; ?>" data-section="pimp">
                <button type="button" class="nav-section-toggle">
                    <span class="nav-section-label">PIMP YOUR RIDE</span>
                    <span class="nav-section-arrow"></span>
                </button>
                <ul class="nav-section-links">
                    <li class="<?php echo $_pc_active_class('smack-globalvibe.php'); ?>"><a href="smack-globalvibe.php">Global Vibe</a></li>
                    <li class="<?php echo $_pc_active_class('smack-masthead.php'); ?>"><a href="smack-masthead.php">Masthead Cover</a></li>
                    <li class="<?php echo $_pc_active_class('smack-skin.php'); ?>"><a href="smack-skin.php">Smooth Your Skin</a></li>
                    <li class="<?php echo $_pc_active_class('smack-menu.php'); ?>"><a href="smack-menu.php">Menu Manager</a></li>
                    <li class="<?php echo $_pc_active_class('smack-social-dock.php'); ?>"><a href="smack-social-dock.php">Social Dock</a></li>
                    <li class="<?php echo $_pc_active_class('smack-css.php'); ?>"><a href="smack-css.php">Smack Your CSS Up!</a></li>
                    <li class="<?php echo $_pc_active_class('smack-scripts.php'); ?>"><a href="smack-scripts.php">Smack Your Scripts Up!</a></li>
                    <li class="<?php echo $_pc_active_class('smack-appearance-archive.php'); ?>"><a href="smack-appearance-archive.php">Archive Appearance</a></li>
                    <li class="<?php echo $_pc_active_class('smack-appearance-solo.php'); ?>"><a href="smack-appearance-solo.php">Solo Image Appearance</a></li>
                    <li class="<?php echo $_pc_active_class('smack-appearance-static.php'); ?>"><a href="smack-appearance-static.php">Static Page Appearance</a></li>
                </ul>
            </div>

            <div class="nav-section<?php echo $_pc_active === 'turbo-boost' ? ' open' : ''; ?>" data-section="turbo-boost">
                <button type="button" class="nav-section-toggle">
                    <span class="nav-section-label">CHALLENGE ME</span>
                    <span class="nav-section-arrow"></span>
                </button>
                <ul class="nav-section-links">
                    <li class="<?php echo $_pc_active_class('smack-photochallenge.php'); ?>"><a href="smack-photochallenge.php">Contest &amp; Feed</a></li>
                    <li><a href="smack-photochallenge.php#queue-contest-post">Queue Contest Post</a></li>
                </ul>
            </div>

            <div class="nav-section<?php echo $_pc_active === 'boring' ? ' open' : ''; ?>" data-section="boring">
                <button type="button" class="nav-section-toggle">
                    <span class="nav-section-label">BORING ASS STUFF</span>
                    <span class="nav-section-arrow"></span>
                </button>
                <ul class="nav-section-links">
                    <li class="<?php echo $_pc_active_class('smack-settings.php'); ?>"><a href="smack-settings.php">Configuration</a></li>
                    <li class="<?php echo $_pc_active_class('smack-users.php'); ?>"><a href="smack-users.php">User Manager</a></li>
                    <li class="<?php echo $_pc_active_class('smack-2fa.php'); ?>"><a href="smack-2fa.php">Two-Factor Auth</a></li>
                    <li class="<?php echo $_pc_active_class('smack-maintenance.php'); ?>"><a href="smack-maintenance.php">Maintenance</a></li>
                    <li class="<?php echo $_pc_active_class('smack-fingerprints.php'); ?>"><a href="smack-fingerprints.php">Troll Control</a></li>
                    <li class="<?php echo $_pc_active_class('smack-backup.php'); ?>"><a href="smack-backup.php">Backup &amp; Recovery</a></li>
                    <li class="<?php echo $_pc_active_class('smack-disaster.php'); ?>"><a href="smack-disaster.php">Disaster Recovery</a></li>
                    <li class="<?php echo $_pc_active_class('smack-break-glass.php'); ?>"><a href="smack-break-glass.php">Break the Glass</a></li>
                    <li class="<?php echo $_pc_active_class('smack-stats.php'); ?>"><a href="smack-stats.php">Traffic Stats</a></li>
                    <li class="<?php echo $_pc_active_class('smack-update.php'); ?>"><a href="smack-update.php">System Updates</a></li>
                    <li class="<?php echo $_pc_active_class('smack-schema.php'); ?>"><a href="smack-schema.php">Database Schema</a></li>
                    <li class="<?php echo $_pc_active_class('smack-back.php'); ?>"><a href="smack-back.php">SMACKBACK Security</a></li>
                    <li class="<?php echo $_pc_active_class('smack-api-keys.php'); ?>"><a href="smack-api-keys.php">API Keys</a></li>
                    <li class="<?php echo $_pc_active_class('smack-multisite.php'); ?>"><a href="smack-multisite.php">Multisite Management</a></li>
                </ul>
            </div>
        </nav>
    </div>

    <div class="sidebar-bottom">
        <a href="logout.php" class="logout">Logout</a>
        <div class="credits-admin">&copy; 2026 Sean McCormick</div>
    </div>
</div>
<?php // ===== SNAPSMACK EOF =====
