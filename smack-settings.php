<?php
/**
 * SNAPSMACK - Global site configuration
 *
 * Manages site identity, branding, navigation, footer layout, and image processing parameters.
 * Handles logo and favicon uploads, timezone settings, and feature toggles.
 */

require_once 'core/auth.php';
require_once 'core/ste-client.php';

// --- SMACK THE ENEMY: REGISTER ACTION ---
// Handled before the main settings save so the new key is in DB before we reload settings.
$ste_msg = '';
$ste_err = '';
if (isset($_POST['ste_action']) && $_POST['ste_action'] === 'register') {
    $site_url = rtrim($settings['site_url'] ?? $_SERVER['HTTP_HOST'], '/');
    if (empty($site_url)) $site_url = 'https://' . $_SERVER['HTTP_HOST'];
    $display_name = $settings['site_title'] ?? 'SnapSmack Site';
    $post_count   = (int)($pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status='published'")->fetchColumn() ?? 0);

    $res = ste_client_register($site_url, $display_name, $post_count);
    if ($res['ok']) {
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('ste_api_key',?) ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)")->execute([$res['api_key']]);
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('ste_enabled','1') ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)")->execute();
        $ste_msg = 'Registered with SMACK THE ENEMY. Ready to roll.';
        // Reload settings to pick up the new key
        $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    } else {
        $ste_err = 'Registration failed: ' . ($res['error'] ?? 'unknown error');
    }
}

// Sync scores now if key exists and a manual sync was requested
if (isset($_POST['ste_action']) && $_POST['ste_action'] === 'sync_now') {
    $key    = $settings['ste_api_key'] ?? '';
    $cursor = $settings['ste_scores_cursor'] ?? '';
    $count  = ste_client_fetch_delta($pdo, $key, $cursor);
    $ste_msg = $count !== false ? "Synced {$count} score update(s) from the network." : 'Sync failed — check your API key and network connection.';
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Opt out
if (isset($_POST['ste_action']) && $_POST['ste_action'] === 'optout') {
    $key = $settings['ste_api_key'] ?? '';
    if ($key) _ste_request('POST', 'optout', [], $key);
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('ste_enabled','0') ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)")->execute();
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('ste_api_key','') ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val)")->execute();
    $ste_msg = 'Opted out. Your site has been removed from the network.';
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// --- FORM SUBMISSION HANDLER ---
// Processes logo and favicon uploads, then saves all settings via upsert.
if (isset($_POST['save_settings'])) {
    // Handle logo file upload to assets directory.
    if (!empty($_FILES['logo_upload']['name'])) {
        $target_dir = "assets/img/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        $ext = strtolower(pathinfo($_FILES['logo_upload']['name'], PATHINFO_EXTENSION));
        $target_file = $target_dir . "logo." . $ext;

        if (move_uploaded_file($_FILES['logo_upload']['tmp_name'], $target_file)) {
            $_POST['settings']['header_logo_url'] = "/" . $target_file;
        }
    }

    // Handle favicon upload with type validation.
    if (!empty($_FILES['favicon_upload']['name'])) {
        $target_dir = "assets/img/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0755, true);
        }
        $fav_ext = strtolower(pathinfo($_FILES['favicon_upload']['name'], PATHINFO_EXTENSION));
        $allowed_fav = ['ico', 'png', 'svg'];
        if (in_array($fav_ext, $allowed_fav)) {
            $fav_file = $target_dir . "favicon." . $fav_ext;
            if (move_uploaded_file($_FILES['favicon_upload']['tmp_name'], $fav_file)) {
                $_POST['settings']['favicon_url'] = "/" . $fav_file;
            }
        }
    }

    // Checkboxes that are unchecked send no POST value; default them to '0' before saving.
    $checkbox_keys = ['landing_only', 'ste_enabled'];
    foreach ($checkbox_keys as $ck) {
        if (!isset($_POST['settings'][$ck])) $_POST['settings'][$ck] = '0';
    }

    // Persist all settings, inserting or updating as needed.
    foreach ($_POST['settings'] as $key => $val) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute([$key, $val, $val]);
    }
    // --- REGENERATE ROBOTS.TXT ---
    // Build a robots.txt that reflects the AI training policy.
    $ai_policy = $_POST['settings']['ai_training_policy'] ?? 'no_opinion';
    $ai_bots   = ['GPTBot', 'ChatGPT-User', 'CCBot', 'Google-Extended', 'anthropic-ai', 'ClaudeBot', 'Bytespider'];

    $robots  = "# SNAPSMACK — auto-generated robots.txt\n";
    $robots .= "# Regenerated each time Global Configuration is saved.\n\n";
    $robots .= "User-agent: *\n";
    $robots .= "Disallow: /smack-*\n";
    $robots .= "Disallow: /core/\n";
    $robots .= "Disallow: /backups/\n";
    $robots .= "Disallow: /migrations/\n\n";

    if ($ai_policy === 'allow') {
        foreach ($ai_bots as $bot) {
            $robots .= "User-agent: {$bot}\n";
            $robots .= "Allow: /\n\n";
        }
    } elseif ($ai_policy === 'disallow') {
        foreach ($ai_bots as $bot) {
            $robots .= "User-agent: {$bot}\n";
            $robots .= "Disallow: /\n\n";
        }
    }
    // 'no_opinion' = no AI-specific directives at all.

    $robots .= "Sitemap: " . ($_POST['settings']['site_url'] ?? 'https://example.com/') . "sitemap.xml\n";

    file_put_contents(__DIR__ . '/robots.txt', $robots);

    $msg = "Engine parameters updated successfully.";
}

// Load all settings from database.
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

// Load available pages for navigation slot assignment.
try {
    $pages_list = $pdo->query("SELECT id, title FROM snap_pages WHERE is_active = 1 ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pages_list = [];
}

// Resolve active theme name from slug or manifest.
$active_slug = $settings['active_skin'] ?? 'new-horizon';
$active_skin_friendly = str_replace('_', ' ', ucfirst($active_slug));
if (file_exists("skins/{$active_slug}/manifest.php")) {
    $manifest = include "skins/{$active_slug}/manifest.php";
    if (isset($manifest['name'])) {
        $active_skin_friendly = $manifest['name'];
    }
}

// Date format options for display in the UI.
$date_options = [
    'F j, Y'          => 'February 1, 2026',
    'Y-m-d'           => '2026-02-01',
    'd/m/Y'           => '01/02/2026',
    'm.d.y'           => '02.01.26',
    'jS F Y'          => '1st February 2026',
    'D, M j, Y'       => 'Sun, Feb 1, 2026'
];

// Determines display state of footer slot (on, custom, off).
function footer_slot_state($settings, $key, $default = 'on') {
    return $settings[$key] ?? $default;
}

$page_title = "Configuration";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>GLOBAL ENGINE CONFIGURATION</h2>
    
    <?php if(isset($msg)): ?>
        <div class="alert">> <?php echo $msg; ?></div>
    <?php endif; ?>

    <form method="POST" id="config-form" enctype="multipart/form-data">
        
        <!-- ============================================================
             SITE IDENTITY & BRANDING — post-layout-grid (2-col)
             Left: 3 text fields. Right: 2 selectors + read-only.
             ============================================================ -->
        <div class="box">
            <h3>SITE IDENTITY & BRANDING</h3>
            <div class="post-layout-grid">
                <div class="post-col-left">
                    <label>BLOG NAME</label>
                    <input type="text" name="settings[site_name]" value="<?php echo htmlspecialchars($settings['site_name'] ?? ''); ?>">
                    
                    <label>TAGLINE</label>
                    <input type="text" name="settings[site_tagline]" value="<?php echo htmlspecialchars($settings['site_tagline'] ?? ''); ?>">

                    <label>SITE DESCRIPTION</label>
                    <textarea name="settings[site_description]" rows="3" placeholder="One or two sentences about this blog. Used for Open Graph link previews and photo-feed skin profiles."><?php echo htmlspecialchars($settings['site_description'] ?? ''); ?></textarea>
                    <span class="dim">USED FOR LINK PREVIEWS (OG) AND FEED SKIN PROFILE BIOS.</span>

                    <label>BASE SITE URL</label>
                    <input type="text" name="settings[site_url]" value="<?php echo htmlspecialchars($settings['site_url'] ?? 'https://example.com/'); ?>">

                    <label>SITE EMAIL</label>
                    <input type="email" name="settings[site_email]" value="<?php echo htmlspecialchars($settings['site_email'] ?? ''); ?>" placeholder="e.g. contact@example.com">
                </div>

                <div class="post-col-right">
                    <label>HEADER MODE</label>
                    <select name="settings[header_type]">
                        <option value="text" <?php echo (($settings['header_type'] ?? 'text') == 'text') ? 'selected' : ''; ?>>TEXT MODE</option>
                        <option value="image" <?php echo (($settings['header_type'] ?? 'text') == 'image') ? 'selected' : ''; ?>>IMAGE MODE (LOGO)</option>
                    </select>

                    <label>ACTIVE SKIN</label>
                    <div class="read-only-display">
                        <?php echo strtoupper(htmlspecialchars($active_skin_friendly)); ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             ARCHITECTURE & INTERACTION — post-layout-grid (2-col)
             4 toggles: 2 per column. Clean 2x2.
             ============================================================ -->
        <div class="box">
            <h3>ARCHITECTURE & INTERACTION</h3>
            <div class="config-grid">
                <div class="lens-input-wrapper">
                    <label>GLOBAL COMMENTS</label>
                    <select name="settings[global_comments_enabled]">
                        <option value="1" <?php echo (($settings['global_comments_enabled'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                        <option value="0" <?php echo (($settings['global_comments_enabled'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED (KILL-SWITCH)</option>
                    </select>
                    <span class="dim">MASTER OVERRIDE FOR ALL POSTS.</span>
                </div>

                <div class="lens-input-wrapper">
                    <label>SITE-WIDE SEARCH</label>
                    <select name="settings[search_enabled]">
                        <option value="0" <?php echo (($settings['search_enabled'] ?? '0') == '0') ? 'selected' : ''; ?>>DISABLED (DEFAULT)</option>
                        <option value="1" <?php echo (($settings['search_enabled'] ?? '0') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                    </select>
                    <span class="dim">ENABLES FULL-TEXT SEARCH ON SKINS THAT SUPPORT IT (E.G. PHOTOGRAM).</span>
                </div>

                <div class="lens-input-wrapper">
                    <label>AI TRAINING CRAWLERS</label>
                    <select name="settings[ai_training_policy]">
                        <option value="no_opinion" <?php echo (($settings['ai_training_policy'] ?? 'no_opinion') == 'no_opinion') ? 'selected' : ''; ?>>NO OPINION (DEFAULT)</option>
                        <option value="allow" <?php echo (($settings['ai_training_policy'] ?? 'no_opinion') == 'allow') ? 'selected' : ''; ?>>ALLOW</option>
                        <option value="disallow" <?php echo (($settings['ai_training_policy'] ?? 'no_opinion') == 'disallow') ? 'selected' : ''; ?>>DISALLOW</option>
                    </select>
                    <span class="dim">CONTROLS ROBOTS.TXT DIRECTIVES FOR GPTBOT, CLAUDEBOT, CCBOT, GOOGLE-EXTENDED, BYTESPIDER. REGENERATED ON SAVE.</span>
                </div>

                <div class="lens-input-wrapper">
                    <label>PUBLIC BLOGROLL</label>
                    <select name="settings[blogroll_enabled]">
                        <option value="1" <?php echo (($settings['blogroll_enabled'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                        <option value="0" <?php echo (($settings['blogroll_enabled'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED</option>
                    </select>
                    <span class="dim">CONTROLS NAV LINK AND PUBLIC PAGE ACCESS.</span>
                </div>

                <div class="lens-input-wrapper">
                    <label>COMMUNITY FORUM</label>
                    <select name="settings[forum_enabled]">
                        <option value="1" <?php echo (($settings['forum_enabled'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED (DEFAULT)</option>
                        <option value="0" <?php echo (($settings['forum_enabled'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED</option>
                    </select>
                    <span class="dim">SHOWS THE FORUM CLIENT IN YOUR ADMIN PANEL. CONNECTS TO THE SNAPSMACK COMMUNITY HUB.</span>
                </div>

                <!-- Forum URL is hardcoded to snapsmack.ca. Not user-configurable. -->

                <div class="lens-input-wrapper">
                    <label>HOMEPAGE MODE</label>
                    <select name="settings[homepage_mode]" id="homepage-mode-select">
                        <option value="latest_post" <?php echo (($settings['homepage_mode'] ?? 'latest_post') == 'latest_post') ? 'selected' : ''; ?>>LATEST POST (DEFAULT)</option>
                        <option value="skin_landing" <?php echo (($settings['homepage_mode'] ?? 'latest_post') == 'skin_landing') ? 'selected' : ''; ?>>SKIN LANDING PAGE</option>
                        <option value="static_page" <?php echo (($settings['homepage_mode'] ?? 'latest_post') == 'static_page') ? 'selected' : ''; ?>>STATIC PAGE</option>
                    </select>
                    <span class="dim">LATEST POST SHOWS NEWEST IMAGE. SKIN LANDING USES THE SKIN'S BUILT-IN SLIDER/GRID. STATIC PAGE USES A CUSTOM PAGE.</span>

                    <div class="lens-input-wrapper homepage-page-picker<?php echo (($settings['homepage_mode'] ?? 'latest_post') == 'static_page') ? '' : ' d-none'; ?>" id="homepage-page-picker">
                        <label>HOMEPAGE PAGE</label>
                        <select name="settings[homepage_page_id]">
                            <option value="0">SELECT A PAGE</option>
                            <?php foreach($pages_list as $p): ?>
                                <option value="<?php echo $p['id']; ?>" <?php echo (($settings['homepage_page_id'] ?? 0) == $p['id']) ? 'selected' : ''; ?>><?php echo strtoupper(htmlspecialchars($p['title'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="lens-input-wrapper homepage-blog-slug<?php echo (($settings['homepage_mode'] ?? 'latest_post') == 'latest_post') ? ' d-none' : ''; ?>" id="homepage-blog-slug">
                        <label>BLOG URL SLUG</label>
                        <input type="text" name="settings[blog_slug]" value="<?php echo htmlspecialchars($settings['blog_slug'] ?? 'blog'); ?>" placeholder="blog">
                        <span class="dim">THE URL WHERE VISITORS FIND YOUR IMAGE FEED (E.G. /BLOG, /FEED, /PHOTOS). APPEARS IN NAVIGATION.</span>
                    </div>

                    <?php $show_landing_only = in_array(($settings['homepage_mode'] ?? 'latest_post'), ['skin_landing', 'static_page']); ?>
                    <div class="lens-input-wrapper<?php echo $show_landing_only ? '' : ' d-none'; ?>" id="homepage-landing-only">
                        <label>LANDING PAGE ONLY</label>
                        <label class="toggle-switch">
                            <input type="checkbox" name="settings[landing_only]" value="1" <?php echo (($settings['landing_only'] ?? '0') === '1') ? 'checked' : ''; ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="dim">NO NAVIGATION, NO SKIN, NO CHROME — JUST THE PAGE CONTENT. USE FOR COMING SOON, SPLASH SCREENS, OR SINGLE-PAGE SITES.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             FOOTER CONFIGURATION — post-layout-grid (2-col)
             5 slots: 3 left, 2 right. Each has conditional custom field.
             ============================================================ -->
        <div class="box">
            <h3>FOOTER CONFIGURATION</h3>
            <p class="dim">Configure which elements appear in the public site footer. Each slot can be ON (default content), CUSTOM (your text), or OFF. RSS cannot be disabled.</p>

            <?php
            $footer_slots = [
                [
                    'key'         => 'copyright',
                    'label'       => 'COPYRIGHT',
                    'hint'        => 'Default: &copy; {YEAR} {BLOG NAME}',
                    'placeholder' => 'e.g. &copy; 2026 My Photo Blog',
                    'default'     => 'on',
                ],
                [
                    'key'         => 'email',
                    'label'       => 'EMAIL',
                    'hint'        => 'Default: reverse-encoded site email (spam protection).',
                    'placeholder' => 'e.g. contact@example.com',
                    'default'     => 'on',
                ],
                [
                    'key'         => 'theme',
                    'label'       => 'CURRENT THEME',
                    'hint'        => 'Default: shows active skin name.',
                    'placeholder' => 'e.g. Designed by Example Studio',
                    'default'     => 'off',
                ],
                [
                    'key'         => 'powered',
                    'label'       => 'POWERED BY',
                    'hint'        => 'Default: POWERED BY SNAPSMACK {VERSION}',
                    'placeholder' => 'e.g. Built with love and caffeine',
                    'default'     => 'on',
                ],
            ];
            ?>

            <div class="post-layout-grid">
                <div class="post-col-left">
                    <?php foreach (array_slice($footer_slots, 0, 2) as $slot):
                        $state_key  = 'footer_slot_' . $slot['key'];
                        $custom_key = 'footer_slot_' . $slot['key'] . '_custom';
                        $state      = footer_slot_state($settings, $state_key, $slot['default']);
                        $custom_val = $settings[$custom_key] ?? '';
                    ?>
                    <div class="lens-input-wrapper">
                        <label><?php echo $slot['label']; ?> SLOT</label>
                        <select name="settings[<?php echo $state_key; ?>]" class="footer-slot-toggle" data-target="<?php echo $custom_key; ?>">
                            <option value="on"     <?php echo ($state === 'on')     ? 'selected' : ''; ?>>ON (DEFAULT)</option>
                            <option value="custom" <?php echo ($state === 'custom') ? 'selected' : ''; ?>>CUSTOM TEXT</option>
                            <option value="off"    <?php echo ($state === 'off')    ? 'selected' : ''; ?>>OFF</option>
                        </select>
                        <span class="dim"><?php echo $slot['hint']; ?></span>
                        <div class="footer-custom-field<?php echo ($state === 'custom') ? '' : ' d-none'; ?>" id="field-<?php echo $custom_key; ?>">
                            <input type="text"
                                   name="settings[<?php echo $custom_key; ?>]"
                                   value="<?php echo htmlspecialchars($custom_val); ?>"
                                   placeholder="<?php echo $slot['placeholder']; ?>">
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="lens-input-wrapper">
                        <label>RSS SLOT</label>
                        <div class="read-only-display">ALWAYS ON — CANNOT BE DISABLED</div>
                        <span class="dim">Links to your site RSS feed.</span>
                    </div>
                </div>

                <div class="post-col-right">
                    <?php foreach (array_slice($footer_slots, 2, 2) as $slot):
                        $state_key  = 'footer_slot_' . $slot['key'];
                        $custom_key = 'footer_slot_' . $slot['key'] . '_custom';
                        $state      = footer_slot_state($settings, $state_key, $slot['default']);
                        $custom_val = $settings[$custom_key] ?? '';
                    ?>
                    <div class="lens-input-wrapper">
                        <label><?php echo $slot['label']; ?> SLOT</label>
                        <select name="settings[<?php echo $state_key; ?>]" class="footer-slot-toggle" data-target="<?php echo $custom_key; ?>">
                            <option value="on"     <?php echo ($state === 'on')     ? 'selected' : ''; ?>>ON (DEFAULT)</option>
                            <option value="custom" <?php echo ($state === 'custom') ? 'selected' : ''; ?>>CUSTOM TEXT</option>
                            <option value="off"    <?php echo ($state === 'off')    ? 'selected' : ''; ?>>OFF</option>
                        </select>
                        <span class="dim"><?php echo $slot['hint']; ?></span>
                        <div class="footer-custom-field<?php echo ($state === 'custom') ? '' : ' d-none'; ?>" id="field-<?php echo $custom_key; ?>">
                            <input type="text"
                                   name="settings[<?php echo $custom_key; ?>]"
                                   value="<?php echo htmlspecialchars($custom_val); ?>"
                                   placeholder="<?php echo $slot['placeholder']; ?>">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ============================================================
             NAVIGATION SLOT ASSIGNMENTS — post-layout-grid (2-col)
             ============================================================ -->
        <div class="box">
            <h3>NAVIGATION SLOT ASSIGNMENTS</h3>
            <div class="post-layout-grid">
                <div class="post-col-left">
                    <label>PRIMARY NAVIGATION (SLOT 1)</label>
                    <select name="settings[nav_slot_1]">
                        <option value="0">EMPTY</option>
                        <?php foreach($pages_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo (($settings['nav_slot_1'] ?? 0) == $p['id']) ? 'selected' : ''; ?>><?php echo strtoupper(htmlspecialchars($p['title'])); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>SECONDARY NAVIGATION (SLOT 2)</label>
                    <select name="settings[nav_slot_2]">
                        <option value="0">EMPTY</option>
                        <?php foreach($pages_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo (($settings['nav_slot_2'] ?? 0) == $p['id']) ? 'selected' : ''; ?>><?php echo strtoupper(htmlspecialchars($p['title'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="post-col-right">
                    <label>AUXILIARY NAVIGATION (SLOT 3)</label>
                    <select name="settings[nav_slot_3]">
                        <option value="0">EMPTY</option>
                        <?php foreach($pages_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo (($settings['nav_slot_3'] ?? 0) == $p['id']) ? 'selected' : ''; ?>><?php echo strtoupper(htmlspecialchars($p['title'])); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>SYSTEM NAVIGATION (SLOT 4)</label>
                    <select name="settings[nav_slot_4]">
                        <option value="0">EMPTY</option>
                        <?php foreach($pages_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo (($settings['nav_slot_4'] ?? 0) == $p['id']) ? 'selected' : ''; ?>><?php echo strtoupper(htmlspecialchars($p['title'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- ============================================================
             IMAGE ENGINE — post-layout-grid (2-col)
             Left: dimensions + copyright. Right: quality + uploads.
             ============================================================ -->
        <div class="box">
            <h3>IMAGE ENGINE (SERVER-SIDE PROCESSING)</h3>
            <div class="post-layout-grid">
                <div class="post-col-left">
                    <label>LANDSCAPE MAX WIDTH (PX)</label>
                    <input type="number" name="settings[max_width_landscape]" value="<?php echo htmlspecialchars($settings['max_width_landscape'] ?? 2500); ?>">

                    <label>PORTRAIT MAX HEIGHT (PX)</label>
                    <input type="number" name="settings[max_height_portrait]" value="<?php echo htmlspecialchars($settings['max_height_portrait'] ?? 1850); ?>">

                    <label>JPEG COMPRESSION (1-100)</label>
                    <input type="number" name="settings[jpeg_quality]" value="<?php echo htmlspecialchars($settings['jpeg_quality'] ?? 85); ?>">

                    <label>EXIF ARTIST TAG</label>
                    <input type="text" name="settings[exif_artist]" value="<?php echo htmlspecialchars($settings['exif_artist'] ?? ''); ?>" placeholder="e.g. Sean McCormick">
                    <span class="dim">WRITTEN INTO THE ARTIST FIELD OF EVERY JPEG UPLOAD. LEAVE BLANK TO SKIP.</span>

                    <label>EXIF COPYRIGHT TAG</label>
                    <input type="text" name="settings[exif_copyright]" value="<?php echo htmlspecialchars($settings['exif_copyright'] ?? ''); ?>" placeholder="e.g. © 2026 Sean McCormick. All rights reserved.">
                    <span class="dim">WRITTEN INTO THE COPYRIGHT FIELD OF EVERY JPEG UPLOAD. LEAVE BLANK TO SKIP.</span>
                </div>
                <div class="post-col-right">
                    <label>HEADER LOGO ASSET</label>
                    <div class="file-upload-wrapper" onclick="document.getElementById('logo-input').click()">
                        <div class="file-custom-btn">UPLOAD</div>
                        <div class="file-name-display" id="logo-name">
                            <?php echo !empty($settings['header_logo_url']) ? "CURRENT" : "SELECT FILE"; ?>
                        </div>
                        <input type="file" name="logo_upload" id="logo-input" accept="image/*" class="file-input-hidden" onchange="document.getElementById('logo-name').innerText = this.files[0].name;">
                    </div>

                    <label>FAVICON</label>
                    <div class="file-upload-wrapper" onclick="document.getElementById('favicon-input').click()">
                        <div class="file-custom-btn">UPLOAD</div>
                        <div class="file-name-display" id="favicon-name">
                            <?php echo !empty($settings['favicon_url']) ? "CURRENT: " . basename($settings['favicon_url']) : "SELECT FILE (.ICO, .PNG, .SVG)"; ?>
                        </div>
                        <input type="file" name="favicon_upload" id="favicon-input" accept=".ico,.png,.svg,image/x-icon,image/png,image/svg+xml" class="file-input-hidden" onchange="document.getElementById('favicon-name').innerText = this.files[0].name;">
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             TIME & LOCALIZATION — dash-grid (3-col)
             Exactly 3 items. Perfect fit.
             ============================================================ -->
        <div class="box box-flush-bottom">
            <h3>TIME & LOCALIZATION</h3>
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>TIMEZONE</label>
                    <select name="settings[timezone]" id="timezone-select">
                        <?php
                        $timezones = DateTimeZone::listIdentifiers();
                        $current_tz = $settings['timezone'] ?? 'America/Edmonton';
                        foreach ($timezones as $tz) {
                            $selected = ($current_tz == $tz) ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($tz) . "' $selected>" . strtoupper(htmlspecialchars($tz)) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="lens-input-wrapper">
                    <label>DATE DISPLAY FORMAT</label>
                    <select name="settings[date_format]" id="format-select">
                        <?php
                        $current_format = $settings['date_format'] ?? 'F j, Y';
                        foreach ($date_options as $code => $example) {
                            $selected = ($current_format == $code) ? 'selected' : '';
                            echo "<option value='$code' $selected>" . strtoupper($example) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="lens-input-wrapper">
                    <label>LIVE PREVIEW</label>
                    <div id="local-clock" class="read-only-display clock-display">SYNCING...</div>
                </div>
            </div>
        </div>

        <!-- ============================================================
             AI ASSISTANT
             ============================================================ -->
        <div class="box box-flush-bottom">
            <h3>AI ASSISTANT</h3>
            <p class="dim" style="margin:0 0 16px;">
                Powers the Spell/Grammar check and AI Assist panel in the post editor.
                Your API key is stored in the database and never exposed publicly.
            </p>

            <div class="dash-grid" style="grid-template-columns: 1fr 1fr;">
                <div class="lens-input-wrapper">
                    <label>PROVIDER</label>
                    <select name="settings[ai_provider]" id="ai-provider-select">
                        <?php
                        $ai_provider = $settings['ai_provider'] ?? 'none';
                        $ai_providers = [
                            'none'   => 'None (disabled)',
                            'claude' => 'Claude (Anthropic)',
                            'gemini' => 'Gemini (Google)',
                            'openai' => 'ChatGPT (OpenAI)',
                        ];
                        foreach ($ai_providers as $val => $label):
                            $sel = $ai_provider === $val ? 'selected' : '';
                        ?>
                        <option value="<?php echo $val; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="ai-key-fields">
                <div class="lens-input-wrapper ai-key-field" data-provider="claude" <?php echo ($ai_provider !== 'claude') ? 'style="display:none;"' : ''; ?>>
                    <label>CLAUDE API KEY</label>
                    <input type="password" id="ai_key_claude_input" name="settings[ai_key_claude]"
                           value="<?php echo htmlspecialchars($settings['ai_key_claude'] ?? ''); ?>"
                           placeholder="sk-ant-…" autocomplete="off">
                    <span class="field-hint">Get yours at <a href="https://console.anthropic.com/" target="_blank">console.anthropic.com</a></span>
                </div>
                <div class="lens-input-wrapper ai-key-field" data-provider="gemini" <?php echo ($ai_provider !== 'gemini') ? 'style="display:none;"' : ''; ?>>
                    <label>GEMINI API KEY</label>
                    <input type="password" id="ai_key_gemini_input" name="settings[ai_key_gemini]"
                           value="<?php echo htmlspecialchars($settings['ai_key_gemini'] ?? ''); ?>"
                           placeholder="AIza…" autocomplete="off">
                    <span class="field-hint">Get yours at <a href="https://aistudio.google.com/apikey" target="_blank">aistudio.google.com</a></span>
                </div>
                <div class="lens-input-wrapper ai-key-field" data-provider="openai" <?php echo ($ai_provider !== 'openai') ? 'style="display:none;"' : ''; ?>>
                    <label>OPENAI API KEY</label>
                    <input type="password" id="ai_key_openai_input" name="settings[ai_key_openai]"
                           value="<?php echo htmlspecialchars($settings['ai_key_openai'] ?? ''); ?>"
                           placeholder="sk-…" autocomplete="off">
                    <span class="field-hint">Get yours at <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com</a></span>
                </div>
            </div>

            <div style="margin-top:12px;">
                <button type="button" class="btn-smack btn-smack--sm" id="ai-test-btn"
                        style="display:<?php echo $ai_provider !== 'none' ? 'inline-block' : 'none'; ?>;">
                    TEST CONNECTION
                </button>
                <span id="ai-test-result" style="margin-left:12px; font-size:0.85em;"></span>
            </div>
        </div>

        <?php $_ui_pimpmobile = ($settings['ui_mode'] ?? 'bigwheel') === 'pimpmobile'; ?>
        <?php if ($_ui_pimpmobile): ?>
        <h3>SMACK THE ENEMY</h3>
        <?php if ($ste_msg): ?>
            <div class="alert alert-success mb-25">&gt; <?php echo htmlspecialchars($ste_msg); ?></div>
        <?php endif; ?>
        <?php if ($ste_err): ?>
            <div class="alert alert-danger mb-25">&gt; <?php echo htmlspecialchars($ste_err); ?></div>
        <?php endif; ?>

        <?php
        $ste_key     = $settings['ste_api_key']           ?? '';
        $ste_enabled = ($settings['ste_enabled']          ?? '0') === '1';
        $ste_thresh  = $settings['ste_auto_ban_threshold'] ?? 'red';
        $ste_cursor  = $settings['ste_scores_cursor']      ?? '';
        ?>

        <?php if ($ste_key === ''): ?>
            <p class="dim" style="font-size:0.85rem; margin-bottom:16px;">
                SMACK THE ENEMY is a voluntary network reputation system for SnapSmack blogs.
                Register to start receiving threat level scores on incoming comments.
                You can opt out at any time.
            </p>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="ste_action" value="register">
                <button type="submit" class="btn-smack">JOIN THE NETWORK</button>
            </form>
        <?php else: ?>
            <label>NETWORK STATUS</label>
            <div class="read-only-display highlight-green">REGISTERED</div>
            <label class="mt-20">API KEY</label>
            <div class="read-only-display" style="font-family:monospace; font-size:0.75rem; letter-spacing:0.05em;">
                <?php echo substr($ste_key, 0, 8); ?>…<?php echo substr($ste_key, -8); ?>
            </div>

            <label class="mt-20">PARTICIPATION</label>
            <div class="toggle-row">
                <input type="checkbox" id="ste_enabled" name="settings[ste_enabled]" value="1"
                       <?php echo $ste_enabled ? 'checked' : ''; ?>>
                <label for="ste_enabled">ACTIVE — report bans and receive threat scores</label>
            </div>

            <label class="mt-20">AUTO-BAN THRESHOLD</label>
            <select name="settings[ste_auto_ban_threshold]" class="styled-select">
                <?php
                $thresholds = [
                    'yellow' => 'YELLOW — ban anything flagged (1+ strike)',
                    'orange' => 'ORANGE — ban confirmed threats (2+ strikes)',
                    'red'    => 'RED — ban serious threats (3+ strikes)',
                    'black'  => 'BLACK — ban only the worst offenders (4+ strikes)',
                    'never'  => 'NEVER — receive scores but never auto-ban',
                ];
                foreach ($thresholds as $val => $label):
                ?>
                <option value="<?php echo $val; ?>" <?php echo $ste_thresh === $val ? 'selected' : ''; ?>>
                    <?php echo $label; ?>
                </option>
                <?php endforeach; ?>
            </select>
            <span class="dim" style="font-size:0.8rem; display:block; margin-top:6px;">
                Comments at or above this level are silently rejected. You still see them in Troll Control.
            </span>

            <label class="mt-20">SCORE CACHE</label>
            <div class="read-only-display"><?php echo $ste_cursor ? 'Last synced: ' . htmlspecialchars($ste_cursor) : 'Never synced — save settings then sync'; ?></div>
            <div class="action-grid-dual mt-15">
                <form method="POST" style="display:contents;">
                    <input type="hidden" name="ste_action" value="sync_now">
                    <button type="submit" class="btn-smack">SYNC SCORES NOW</button>
                </form>
                <form method="POST" style="display:contents;" onsubmit="return confirm('This will remove your site from the network and clear your API key. Continue?');">
                    <input type="hidden" name="ste_action" value="optout">
                    <button type="submit" class="btn-smack btn-danger">OPT OUT</button>
                </form>
            </div>
        <?php endif; ?>
        <?php endif; // pimpmobile ?>

        <button type="submit" name="save_settings" class="master-update-btn">SAVE GLOBAL ENGINE CONFIGURATION</button>

    </form>
</div>

<script>
// Toggle visibility of custom footer text fields based on slot selection.
document.querySelectorAll('.footer-slot-toggle').forEach(function(sel) {
    sel.addEventListener('change', function() {
        var targetId = 'field-' + this.getAttribute('data-target');
        var field = document.getElementById(targetId);
        if (field) {
            field.style.display = (this.value === 'custom') ? '' : 'none';
        }
    });
});

// Toggle homepage page picker visibility based on homepage mode.
var homepageMode = document.getElementById('homepage-mode-select');
if (homepageMode) {
    homepageMode.addEventListener('change', function() {
        var picker      = document.getElementById('homepage-page-picker');
        var blogSlug    = document.getElementById('homepage-blog-slug');
        var landingOnly = document.getElementById('homepage-landing-only');
        if (picker)      picker.classList.toggle('d-none', this.value !== 'static_page');
        if (blogSlug)    blogSlug.classList.toggle('d-none', this.value === 'latest_post');
        if (landingOnly) landingOnly.classList.toggle('d-none', this.value !== 'skin_landing' && this.value !== 'static_page');
    });
}
</script>

<script>
// Show/hide API key field based on provider selection.
(function () {
    var sel    = document.getElementById('ai-provider-select');
    var testBtn = document.getElementById('ai-test-btn');
    var testRes = document.getElementById('ai-test-result');
    if (!sel) return;

    sel.addEventListener('change', function () {
        document.querySelectorAll('.ai-key-field').forEach(function (el) {
            el.style.display = (el.dataset.provider === sel.value) ? '' : 'none';
        });
        if (testBtn) testBtn.style.display = sel.value !== 'none' ? 'inline-block' : 'none';
        if (testRes) testRes.textContent = '';
    });

    if (testBtn) {
        testBtn.addEventListener('click', function () {
            testRes.textContent = 'Testing…';
            testRes.style.color = '';

            // Gather current form values so the test works before saving
            var provider   = sel.value;
            var keyFieldId = { claude: 'ai_key_claude_input', gemini: 'ai_key_gemini_input', openai: 'ai_key_openai_input' }[provider] || '';
            var keyField   = keyFieldId ? document.getElementById(keyFieldId) : null;
            var apiKey     = keyField ? keyField.value.trim() : '';

            var fd = new FormData();
            fd.append('provider', provider);
            fd.append('api_key',  apiKey);

            fetch('smack-ai-test.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    testRes.textContent = d.ok ? '✓ ' + d.message : '✗ ' + d.error;
                    testRes.style.color = d.ok ? 'var(--color-ok, #4caf50)' : 'var(--color-danger, #e05252)';
                })
                .catch(function () {
                    testRes.textContent = '✗ Request failed';
                    testRes.style.color = 'var(--color-danger, #e05252)';
                });
        });
    }
}());
</script>
<script src="assets/js/ss-engine-admin-ui.js?v=<?php echo time(); ?>"></script>
<?php include 'core/admin-footer.php'; ?>
