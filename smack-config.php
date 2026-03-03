<?php
/**
 * SNAPSMACK - Global site configuration
 * Alpha v0.6
 *
 * Manages site identity, branding, navigation, footer layout, and image processing parameters.
 * Handles logo and favicon uploads, timezone settings, and feature toggles.
 */

require_once 'core/auth.php';

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

    // Persist all settings, inserting or updating as needed.
    foreach ($_POST['settings'] as $key => $val) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute([$key, $val, $val]);
    }
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
$active_slug = $settings['active_skin'] ?? 'new_horizon_dark';
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

                    <label>BASE SITE URL</label>
                    <input type="text" name="settings[site_url]" value="<?php echo htmlspecialchars($settings['site_url'] ?? 'https://iswa.ca/'); ?>">
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
            <div class="post-layout-grid">
                <div class="post-col-left">
                    <div class="lens-input-wrapper">
                        <label>GLOBAL COMMENTS</label>
                        <select name="settings[global_comments_enabled]">
                            <option value="1" <?php echo (($settings['global_comments_enabled'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                            <option value="0" <?php echo (($settings['global_comments_enabled'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED (KILL-SWITCH)</option>
                        </select>
                        <span class="dim">MASTER OVERRIDE FOR ALL POSTS.</span>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>EXIF / TECHNICAL SPECS</label>
                        <select name="settings[exif_display_enabled]">
                            <option value="1" <?php echo (($settings['exif_display_enabled'] ?? '1') == '1') ? 'selected' : ''; ?>>SHOW ON PUBLIC POSTS</option>
                            <option value="0" <?php echo (($settings['exif_display_enabled'] ?? '1') == '0') ? 'selected' : ''; ?>>HIDDEN FROM PUBLIC</option>
                        </select>
                        <span class="dim">HIDES TECHNICAL SPECIFICATIONS PANEL. DATA IS STILL STORED.</span>
                    </div>
                </div>

                <div class="post-col-right">
                    <div class="lens-input-wrapper">
                        <label>GLOBAL DOWNLOADS</label>
                        <select name="settings[global_downloads_enabled]">
                            <option value="1" <?php echo (($settings['global_downloads_enabled'] ?? '0') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                            <option value="0" <?php echo (($settings['global_downloads_enabled'] ?? '0') == '0') ? 'selected' : ''; ?>>DISABLED (KILL-SWITCH)</option>
                        </select>
                        <span class="dim">MASTER OVERRIDE. PER-POST TOGGLE ALSO REQUIRED.</span>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>PUBLIC BLOGROLL</label>
                        <select name="settings[blogroll_enabled]">
                            <option value="1" <?php echo (($settings['blogroll_enabled'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                            <option value="0" <?php echo (($settings['blogroll_enabled'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED</option>
                        </select>
                        <span class="dim">CONTROLS NAV LINK AND PUBLIC PAGE ACCESS.</span>
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
                        <div class="footer-custom-field" id="field-<?php echo $custom_key; ?>" style="<?php echo ($state === 'custom') ? '' : 'display:none;'; ?> margin-top: 8px;">
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
                        <div class="footer-custom-field" id="field-<?php echo $custom_key; ?>" style="<?php echo ($state === 'custom') ? '' : 'display:none;'; ?> margin-top: 8px;">
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
             Left: dimensions. Right: quality + uploads.
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
                </div>
                <div class="post-col-right">
                    <label>HEADER LOGO ASSET</label>
                    <div class="file-upload-wrapper" onclick="document.getElementById('logo-input').click()">
                        <div class="file-custom-btn">UPLOAD</div>
                        <div class="file-name-display" id="logo-name">
                            <?php echo !empty($settings['header_logo_url']) ? "CURRENT" : "SELECT FILE"; ?>
                        </div>
                        <input type="file" name="logo_upload" id="logo-input" accept="image/*" style="display:none;" onchange="document.getElementById('logo-name').innerText = this.files[0].name;">
                    </div>

                    <label>FAVICON</label>
                    <div class="file-upload-wrapper" onclick="document.getElementById('favicon-input').click()">
                        <div class="file-custom-btn">UPLOAD</div>
                        <div class="file-name-display" id="favicon-name">
                            <?php echo !empty($settings['favicon_url']) ? "CURRENT: " . basename($settings['favicon_url']) : "SELECT FILE (.ICO, .PNG, .SVG)"; ?>
                        </div>
                        <input type="file" name="favicon_upload" id="favicon-input" accept=".ico,.png,.svg,image/x-icon,image/png,image/svg+xml" style="display:none;" onchange="document.getElementById('favicon-name').innerText = this.files[0].name;">
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
                    <div id="local-clock" class="read-only-display" style="padding: 8px 12px; font-size: 0.95rem; letter-spacing: 0.5px;">SYNCING...</div>
                </div>
            </div>
        </div>

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
</script>

<script src="assets/js/ss-engine-admin-ui.js?v=<?php echo time(); ?>"></script>
<?php include 'core/admin-footer.php'; ?>
