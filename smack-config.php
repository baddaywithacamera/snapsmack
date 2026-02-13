<?php
/**
 * SnapSmack - Configuration Engine
 * Version: 7.23 - "Fortress" Build (Full Output)
 * -------------------------------------------------------------------------
 * - RESTORED: Full commenting and readability structures.
 * - RESTORED: Copyright Preview in the label.
 * - UPDATED: Footer warning is now grey, no exclamation, standard styling.
 * - FIXED: Zero truncation.
 * -------------------------------------------------------------------------
 */
require_once 'core/auth.php';

// -------------------------------------------------------------------------
// 1. SETTINGS & UPLOAD PERSISTENCE
// -------------------------------------------------------------------------
if (isset($_POST['save_settings'])) {
    
    // --- LOGO UPLOAD HANDLER ---
    // Checks for a file, creates directory if missing, moves file, saves URL.
    if (!empty($_FILES['logo_upload']['name'])) {
        $target_dir = "assets/img/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }
        
        $ext = strtolower(pathinfo($_FILES['logo_upload']['name'], PATHINFO_EXTENSION));
        $target_file = $target_dir . "logo." . $ext;

        if (move_uploaded_file($_FILES['logo_upload']['tmp_name'], $target_file)) {
            $_POST['settings']['header_logo_url'] = "/" . $target_file;
        }
    }

    // --- GENERIC SETTINGS LOOP ---
    // Saves all other form fields directly to the database.
    foreach ($_POST['settings'] as $key => $val) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute([$key, $val, $val]);
    }
    
    $msg = "Engine parameters and site identity updated successfully.";
    
    // Refresh settings immediately after save to show new values
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// -------------------------------------------------------------------------
// 2. DATA RETRIEVAL
// -------------------------------------------------------------------------
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

try {
    // Fetch active pages for the Navigation Slot selectors
    $pages_list = $pdo->query("SELECT id, title FROM snap_pages WHERE is_active = 1 ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $pages_list = []; }

// -------------------------------------------------------------------------
// 3. SKIN DISCOVERY
// -------------------------------------------------------------------------
// Looks for the manifest file to display the "Friendly Name" of the active skin.
$active_slug = $settings['active_skin'] ?? 'new_horizon_dark';
$active_skin_friendly = str_replace('_', ' ', ucfirst($active_slug));
if (file_exists("skins/{$active_slug}/manifest.php")) {
    $manifest = include "skins/{$active_slug}/manifest.php";
    if (isset($manifest['name'])) { $active_skin_friendly = $manifest['name']; }
}

// -------------------------------------------------------------------------
// 4. REGISTRIES & DEFAULTS
// -------------------------------------------------------------------------
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
    <h2 class="tactical-green">GLOBAL ENGINE CONFIGURATION</h2>
    
    <?php if(isset($msg)): ?><div class="msg">> <?php echo $msg; ?></div><?php endif; ?>

    <form method="POST" id="config-form" enctype="multipart/form-data">
        
        <div class="box">
            <h3 class="tactical-green-header">SITE IDENTITY & BRANDING</h3>
            
            <div class="control-group">
                <label>BLOG NAME</label>
                <input type="text" name="settings[site_name]" value="<?php echo htmlspecialchars($settings['site_name'] ?? ''); ?>">
            </div>
            
            <div class="control-group">
                <label>TAGLINE</label>
                <input type="text" name="settings[site_tagline]" value="<?php echo htmlspecialchars($settings['site_tagline'] ?? ''); ?>">
            </div>

            <div class="control-group">
                <label>BASE SITE URL (INCLUDE TRAILING SLASH)</label>
                <input type="text" name="settings[site_url]" value="<?php echo htmlspecialchars($settings['site_url'] ?? 'https://iswa.ca/'); ?>">
            </div>

            <label class="mt-20">NAVIGATION SLOT ASSIGNMENTS</label>
            <div class="engine-grid slots-4">
                <?php for($i=1; $i<=4; $i++): ?>
                <div class="control-group">
                    <label class="slot-label">SLOT <?php echo $i; ?></label>
                    <select name="settings[nav_slot_<?php echo $i; ?>]">
                        <option value="0">-- EMPTY --</option>
                        <?php foreach($pages_list as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo (($settings['nav_slot_'.$i] ?? 0) == $p['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endfor; ?>
            </div>

            <div class="engine-grid slots-2 mt-20">
                <div class="control-group">
                    <label>HEADER MODE</label>
                    <select name="settings[header_type]">
                        <option value="text" <?php echo (($settings['header_type'] ?? 'text') == 'text') ? 'selected' : ''; ?>>Text Mode</option>
                        <option value="image" <?php echo (($settings['header_type'] ?? 'text') == 'image') ? 'selected' : ''; ?>>Image Mode (Logo)</option>
                    </select>
                </div>
                <div class="control-group">
                    <label>ACTIVE SKIN (READ ONLY)</label>
                    <div class="preview-box">
                        <?php echo htmlspecialchars($active_skin_friendly); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="box">
            <h3 class="tactical-green-header">INTERACTION & FOOTER ARCHITECTURE</h3>
            
            <div class="control-group">
                <label>GLOBAL COMMENTS</label>
                <select name="settings[global_comments_enabled]">
                    <option value="1" <?php echo (($settings['global_comments_enabled'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                    <option value="0" <?php echo (($settings['global_comments_enabled'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED (KILL-SWITCH)</option>
                </select>
                <p class="input-hint">Master switch to turn off comments across the entire engine.</p>
            </div>

            <div class="engine-grid slots-2 mt-20">
                <div class="control-group">
                    <label>BRANDING STYLE</label>
                    <select name="settings[footer_branding_style]">
                        <option value="standard" <?php echo (($settings['footer_branding_style'] ?? 'standard') == 'standard') ? 'selected' : ''; ?>>Standard (Powered by SnapSmack)</option>
                        <option value="minimal" <?php echo (($settings['footer_branding_style'] ?? 'standard') == 'minimal') ? 'selected' : ''; ?>>Minimal (Icon Only)</option>
                        <option value="ghost" <?php echo (($settings['footer_branding_style'] ?? 'standard') == 'ghost') ? 'selected' : ''; ?>>Ghost (Completely Hidden)</option>
                    </select>
                </div>
                <div class="control-group">
                    <label>COPYRIGHT OVERRIDE (CURRENT: <?php echo htmlspecialchars($settings['footer_copyright_override'] ?? '© '.date("Y").' '.$settings['site_name']); ?>)</label>
                    <input type="text" name="settings[footer_copyright_override]" 
                           placeholder="Leave blank for automatic default"
                           value="<?php echo htmlspecialchars($settings['footer_copyright_override'] ?? ''); ?>">
                </div>
            </div>

            <div class="control-group mt-20">
                <label>CUSTOM FOOTER / SCRIPT INJECTION</label>
                <textarea name="settings[footer_injection_scripts]" rows="4" style="font-family: monospace; background: #000; color: #39FF14; border: 1px solid #333; width: 100%; padding: 10px;"><?php echo htmlspecialchars($settings['footer_injection_scripts'] ?? ''); ?></textarea>
                <p class="input-hint" style="color: #888;">NOTE: Entering text or code here will disable and replace the entire system footer.</p>
            </div>
        </div>

        <div class="box">
            <h3 class="tactical-green-header">BRANDING ASSETS</h3>
            <div class="control-group">
                <label>HEADER LOGO ASSET</label>
                <div class="file-upload-wrapper">
                    <label for="logo-input" class="file-custom-btn">CHOOSE LOGO FILE</label>
                    <div class="file-name-display" id="logo-name">
                        <?php echo !empty($settings['header_logo_url']) ? "CURRENT: " . $settings['header_logo_url'] : "Ready for upload..."; ?>
                    </div>
                    <input type="file" name="logo_upload" id="logo-input" accept="image/*" style="display:none;" 
                           onchange="document.getElementById('logo-name').innerText = '[SELECTED]: ' + this.files[0].name; document.getElementById('logo-name').style.color = '#39FF14';">
                </div>
            </div>
        </div>

        <div class="box">
            <h3 class="tactical-green-header">IMAGE ENGINE (SERVER-SIDE PROCESSING)</h3>
            <div class="engine-grid slots-3">
                <div class="control-group">
                    <label>LANDSCAPE MAX WIDTH (PX)</label>
                    <input type="number" name="settings[max_width_landscape]" value="<?php echo htmlspecialchars($settings['max_width_landscape'] ?? 2500); ?>">
                </div>
                <div class="control-group">
                    <label>PORTRAIT MAX HEIGHT (PX)</label>
                    <input type="number" name="settings[max_height_portrait]" value="<?php echo htmlspecialchars($settings['max_height_portrait'] ?? 1850); ?>">
                </div>
                <div class="control-group">
                    <label>JPEG COMPRESSION (1-100)</label>
                    <input type="number" name="settings[jpeg_quality]" value="<?php echo htmlspecialchars($settings['jpeg_quality'] ?? 85); ?>">
                </div>
            </div>
        </div>

        <div class="box box-flush-bottom">
            <h3 class="tactical-green-header">TIME & LOCALIZATION</h3>
            
            <label>TIMEZONE</label>
            <select name="settings[timezone]" id="timezone-select">
                <?php
                $timezones = DateTimeZone::listIdentifiers();
                $current_tz = $settings['timezone'] ?? 'America/Edmonton';
                foreach ($timezones as $tz) {
                    $dt = new DateTime('now', new DateTimeZone($tz));
                    $offset = $dt->format('P'); 
                    $selected = ($current_tz == $tz) ? 'selected' : '';
                    echo "<option value='" . htmlspecialchars($tz) . "' $selected>(UTC $offset) " . htmlspecialchars($tz) . "</option>";
                }
                ?>
            </select>
            
            <label class="mt-20">DATE DISPLAY FORMAT</label>
            <select name="settings[date_format]" id="format-select">
                <?php
                $current_format = $settings['date_format'] ?? 'F j, Y';
                foreach ($date_options as $code => $example) {
                    $selected = ($current_format == $code) ? 'selected' : '';
                    echo "<option value='$code' $selected>$example</option>";
                }
                ?>
            </select>
            
            <label class="mt-20">SYSTEM CLOCK PREVIEW</label>
            <div class="preview-box clock-preview">
                <span id="local-clock" class="highlight-green">
                    <?php 
                    $date = new DateTime("now", new DateTimeZone($current_tz));
                    echo strtoupper($date->format('D, M j, Y — H:i:s')); 
                    ?>
                </span>
            </div>
        </div>

        <button type="submit" name="save_settings" class="master-update-btn">SAVE GLOBAL ENGINE CONFIGURATION</button>

    </form>
</div>

<script src="assets/js/smack-ui.js?v=13.7"></script>
<?php include 'core/admin-footer.php'; ?>