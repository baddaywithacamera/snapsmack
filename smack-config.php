<?php
/**
 * SnapSmack - Configuration Engine
 * Version: 16.38 - Triple Column Refactor
 * -------------------------------------------------------------------------
 * - UPDATED: "Architecture & Interaction" refactored to 3 columns.
 * - FIXED: Navigation Slot Assignments weight promoted to H3.
 * - ARCHITECTURE: matches v6.45 Core.
 * -------------------------------------------------------------------------
 */
require_once 'core/auth.php';

// [LOGIC PRESERVATION - IDENTICAL TO PREVIOUS VERSION]
if (isset($_POST['save_settings'])) {
    if (!empty($_FILES['logo_upload']['name'])) {
        $target_dir = "assets/img/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
        $ext = strtolower(pathinfo($_FILES['logo_upload']['name'], PATHINFO_EXTENSION));
        $target_file = $target_dir . "logo." . $ext;
        if (move_uploaded_file($_FILES['logo_upload']['tmp_name'], $target_file)) {
            $_POST['settings']['header_logo_url'] = "/" . $target_file;
        }
    }
    foreach ($_POST['settings'] as $key => $val) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute([$key, $val, $val]);
    }
    $msg = "Engine parameters updated successfully.";
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

try {
    $pages_list = $pdo->query("SELECT id, title FROM snap_pages WHERE is_active = 1 ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $pages_list = []; }

$active_slug = $settings['active_skin'] ?? 'new_horizon_dark';
$active_skin_friendly = str_replace('_', ' ', ucfirst($active_slug));
if (file_exists("skins/{$active_slug}/manifest.php")) {
    $manifest = include "skins/{$active_slug}/manifest.php";
    if (isset($manifest['name'])) { $active_skin_friendly = $manifest['name']; }
}

$date_options = [
    'F j, Y'          => 'February 1, 2026',
    'Y-m-d'           => '2026-02-01',
    'd/m/Y'           => '01/02/2026',
    'm.d.y'           => '02.01.26',
    'jS F Y'          => '1st February 2026',
    'D, M j, Y'       => 'Sun, Feb 1, 2026'
];

$page_title = "Configuration";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>GLOBAL ENGINE CONFIGURATION</h2>
    
    <?php if(isset($msg)): ?><div class="alert">> <?php echo $msg; ?></div><?php endif; ?>

    <form method="POST" id="config-form" enctype="multipart/form-data">
        
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

        <div class="box">
            <h3>ARCHITECTURE & INTERACTION</h3>
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>GLOBAL COMMENTS</label>
                    <select name="settings[global_comments_enabled]">
                        <option value="1" <?php echo (($settings['global_comments_enabled'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                        <option value="0" <?php echo (($settings['global_comments_enabled'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED (KILL-SWITCH)</option>
                    </select>
                    <span class="dim">MASTER OVERRIDE FOR ALL POSTS.</span>
                </div>

                <div class="lens-input-wrapper">
                    <label>BRANDING STYLE</label>
                    <select name="settings[footer_branding_style]">
                        <option value="standard" <?php echo (($settings['footer_branding_style'] ?? 'standard') == 'standard') ? 'selected' : ''; ?>>STANDARD</option>
                        <option value="minimal" <?php echo (($settings['footer_branding_style'] ?? 'standard') == 'minimal') ? 'selected' : ''; ?>>MINIMAL</option>
                        <option value="ghost" <?php echo (($settings['footer_branding_style'] ?? 'standard') == 'ghost') ? 'selected' : ''; ?>>GHOST</option>
                    </select>
                    <span class="dim">FOOTER LOGO VISIBILITY.</span>
                </div>

                <div class="lens-input-wrapper">
                    <label>COPYRIGHT OVERRIDE</label>
                    <input type="text" name="settings[footer_copyright_override]" placeholder="Default" value="<?php echo htmlspecialchars($settings['footer_copyright_override'] ?? ''); ?>">
                    <span class="dim">LEAVE BLANK FOR AUTOMATIC.</span>
                </div>
            </div>
        </div>

        <div class="box">
            <h3>NAVIGATION SLOT ASSIGNMENTS</h3>
            <div class="grid-25">
                <div>
                    <label>PRIMARY NAVIGATION (SLOT 1)</label>
                    <select name="settings[nav_slot_1]">
                        <option value="0">EMPTY</option>
                        <?php foreach($pages_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo (($settings['nav_slot_1'] ?? 0) == $p['id']) ? 'selected' : ''; ?>><?php echo strtoupper(htmlspecialchars($p['title'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>SECONDARY NAVIGATION (SLOT 2)</label>
                    <select name="settings[nav_slot_2]">
                        <option value="0">EMPTY</option>
                        <?php foreach($pages_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo (($settings['nav_slot_2'] ?? 0) == $p['id']) ? 'selected' : ''; ?>><?php echo strtoupper(htmlspecialchars($p['title'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>AUXILIARY NAVIGATION (SLOT 3)</label>
                    <select name="settings[nav_slot_3]">
                        <option value="0">EMPTY</option>
                        <?php foreach($pages_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo (($settings['nav_slot_3'] ?? 0) == $p['id']) ? 'selected' : ''; ?>><?php echo strtoupper(htmlspecialchars($p['title'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
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

        <div class="box">
            <h3>IMAGE ENGINE (SERVER-SIDE PROCESSING)</h3>
            <div class="post-layout-grid">
                <div class="post-col-left">
                    <label>LANDSCAPE MAX WIDTH (PX)</label>
                    <input type="number" name="settings[max_width_landscape]" value="<?php echo htmlspecialchars($settings['max_width_landscape'] ?? 2500); ?>">
                    
                    <label>PORTRAIT MAX HEIGHT (PX)</label>
                    <input type="number" name="settings[max_height_portrait]" value="<?php echo htmlspecialchars($settings['max_height_portrait'] ?? 1850); ?>">
                </div>
                <div class="post-col-right">
                    <label>JPEG COMPRESSION (1-100)</label>
                    <input type="number" name="settings[jpeg_quality]" value="<?php echo htmlspecialchars($settings['jpeg_quality'] ?? 85); ?>">

                    <label>HEADER LOGO ASSET</label>
                    <div class="file-upload-wrapper" onclick="document.getElementById('logo-input').click()">
                        <div class="file-custom-btn">UPLOAD</div>
                        <div class="file-name-display" id="logo-name">
                            <?php echo !empty($settings['header_logo_url']) ? "CURRENT" : "SELECT FILE"; ?>
                        </div>
                        <input type="file" name="logo_upload" id="logo-input" accept="image/*" style="display:none;" onchange="document.getElementById('logo-name').innerText = this.files[0].name;">
                    </div>
                </div>
            </div>
        </div>

        <div class="box box-flush-bottom">
            <h3>TIME & LOCALIZATION</h3>
            <div class="grid-33">
                <div>
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
                <div>
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
                <div>
                    <label>SYSTEM CLOCK PREVIEW</label>
                    <div class="read-only-display" id="local-clock"></div>
                </div>
            </div>
        </div>

        <button type="submit" name="save_settings" class="master-update-btn">SAVE GLOBAL ENGINE CONFIGURATION</button>

    </form>
</div>

<?php include 'core/admin-footer.php'; ?>