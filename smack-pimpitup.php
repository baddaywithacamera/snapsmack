<?php
/**
 * SnapSmack - Global Appearance Settings
 * Version: 16.43 - Universal Action Row Integration
 * -------------------------------------------------------------------------
 * - FIXED: Wrapped button in .form-action-row to kill pt02 margin drift.
 * - FIXED: Robust null checks for $settings keys to prevent 500 errors.
 * - SYNCED: Alert classes for neon green theme pull.
 * -------------------------------------------------------------------------
 */

require_once 'core/auth.php';

// -------------------------------------------------------------------------
// 1. DATA DISCOVERY
// -------------------------------------------------------------------------

// --- PUBLIC SKIN DISCOVERY ---
$skin_dirs = array_filter(glob('skins/*'), 'is_dir');
$available_skins = [];
foreach ($skin_dirs as $dir) {
    $slug = basename($dir);
    if (file_exists($dir . '/manifest.php')) {
        $temp_manifest = include $dir . '/manifest.php';
        $available_skins[$slug] = $temp_manifest['name'] ?? ucfirst($slug);
    }
}

// Set active manifest reference
$current_db_active = (isset($settings['active_skin'])) ? $settings['active_skin'] : array_key_first($available_skins);
$manifest = include "skins/{$current_db_active}/manifest.php";

// --- ADMIN THEME DISCOVERY ---
$admin_themes = [];
$theme_dirs = array_filter(glob('assets/adminthemes/*'), 'is_dir');
foreach ($theme_dirs as $dir) {
    $slug = basename($dir);
    $manifest_path = "{$dir}/{$slug}-manifest.php";
    if (file_exists($manifest_path)) {
        $admin_themes[$slug] = include $manifest_path;
    }
}

$active_admin_slug = (isset($settings['active_theme'])) ? $settings['active_theme'] : 'midnight-lime';
$current_admin_meta = $admin_themes[$active_admin_slug] ?? [
    'name' => $active_admin_slug, 
    'description' => 'Admin theme manifest missing.', 
    'version' => '1.0', 
    'author' => 'System'
];

// -------------------------------------------------------------------------
// 2. HELPERS & COMPILER LOGIC
// -------------------------------------------------------------------------

function prefix_skin_css($css, $prefix) {
    if (empty($css)) return "";
    return preg_replace('/([^\\r\\n,{}]+)(?=[^}]*{)/', $prefix . ' $1', $css);
}

// -------------------------------------------------------------------------
// 3. GLOBAL SETTINGS HANDLER
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_global_appearance'])) {
    
    // Update Admin Theme Choice
    if (isset($_POST['active_admin_theme'])) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('active_theme', ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute([$_POST['active_admin_theme'], $_POST['active_admin_theme']]);
    }

    // Update Archive Logic (Numeric Settings)
    $v_settings = $_POST['settings'] ?? [];
    foreach ($v_settings as $vk => $vv) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute([$vk, $vv, $vv]);
    }

    // Update Specific Skin Options
    if (isset($_POST['skin_opt'])) {
        foreach ($_POST['skin_opt'] as $s_key => $s_val) {
            $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
            $stmt->execute([$s_key, $s_val, $s_val]);
        }
    }
    
    $all_settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // --- GENERATE PUBLIC CSS ---
    $generated_public = "/* SKIN_START */\n";
    foreach ($manifest['options'] as $key => $meta) {
        $val = (isset($all_settings[$key]) && $all_settings[$key] !== '') ? $all_settings[$key] : $meta['default'];
        $css_val = ($meta['type'] === 'range' || $meta['type'] === 'number') ? ((substr($meta['property'], 0, 2) === '--') ? $val : $val . "px") : $val;
        $generated_public .= "{$meta['selector']} { {$meta['property']}: {$css_val}; }\n";
    }
    $generated_public .= "/* SKIN_END */";

    $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'custom_css_public'")->execute([$generated_public]);

    header("Location: smack-pimpitup.php?msg=CALIBRATED");
    exit;
}

$page_title = "THE FULL PIMP";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>GLOBAL APPEARANCE SETTINGS</h2>

    <div class="box appearance-controller">
        <div class="skin-meta-wrap">
            <div class="theme-title-display">
                <?php echo strtoupper(htmlspecialchars($current_admin_meta['name'])); ?> 
                <span class="theme-version-tag">v<?php echo htmlspecialchars($current_admin_meta['version']); ?></span>
            </div>
            <p class="skin-desc-text">
                <?php echo htmlspecialchars($current_admin_meta['description']); ?>
            </p>
            <div class="dim">
                BY <?php echo strtoupper(htmlspecialchars($current_admin_meta['author'])); ?> 
                <?php if(!empty($current_admin_meta['support'])): ?>
                    | <a href="mailto:<?php echo htmlspecialchars($current_admin_meta['support']); ?>" style="color: #00ff00; text-decoration: none;">SUPPORT</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="skin-selector-wrap">
            <form method="POST">
                <label>CORE ADMIN THEME</label>
                <select name="active_admin_theme" onchange="this.form.submit()">
                    <?php foreach ($admin_themes as $slug => $meta): ?>
                        <option value="<?php echo $slug; ?>" <?php echo ($active_admin_slug == $slug) ? 'selected' : ''; ?>>
                            <?php echo strtoupper(htmlspecialchars($meta['name'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="save_global_appearance" value="1">
            </form>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?><div class="alert alert-success">> SYSTEM APPEARANCE CALIBRATED</div><?php endif; ?>

    <form method="POST">
        <div id="smack-skin-config-wrap">
            
            <div class="box">
                <h3>ARCHIVE GRID ARCHITECTURE</h3>
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <label>THUMBNAIL SIZE (PX)</label>
                        <input type="number" name="settings[thumb_size]" value="<?php echo htmlspecialchars($settings['thumb_size'] ?? 400); ?>">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>BROWSE COLUMNS</label>
                        <input type="number" name="settings[browse_cols]" value="<?php echo htmlspecialchars($settings['browse_cols'] ?? 4); ?>">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>WALL ENGINE LINK</label>
                        <select name="settings[show_wall_link]">
                            <option value="1" <?php echo (($settings['show_wall_link'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                            <option value="0" <?php echo (($settings['show_wall_link'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED</option>
                        </select>
                    </div>
                </div>
            </div>

            <?php 
            $grouped_opts = [];
            foreach ($manifest['options'] as $k => $o) { 
                if ($o['section'] === 'STATIC PAGE STYLING' || $o['section'] === 'WALL SPECIFIC') {
                    $grouped_opts[] = ['key' => $k, 'meta' => $o]; 
                }
            }
            ?>

            <div class="box">
                <h3>SKIN-SPECIFIC CALIBRATION</h3>
                <div class="post-layout-grid">
                    <div class="post-col-left">
                        <?php 
                        $half = ceil(count($grouped_opts) / 2);
                        for ($i = 0; $i < $half; $i++): 
                            $k = $grouped_opts[$i]['key']; $o = $grouped_opts[$i]['meta']; 
                            $val = (isset($settings[$k]) && $settings[$k] !== '') ? $settings[$k] : $o['default'];
                        ?>
                            <div class="lens-input-wrapper">
                                <label><?php echo strtoupper($o['label']); ?></label>
                                <?php if ($o['type'] === 'color'): ?>
                                    <div class="color-picker-container">
                                        <input type="color" name="skin_opt[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($val); ?>">
                                        <span class="hex-display"><?php echo strtoupper(htmlspecialchars($val)); ?></span>
                                    </div>
                                <?php elseif ($o['type'] === 'range'): ?>
                                    <div class="range-wrapper">
                                        <input type="range" name="skin_opt[<?php echo $k; ?>]" min="<?php echo $o['min']; ?>" max="<?php echo $o['max']; ?>" value="<?php echo htmlspecialchars($val); ?>" oninput="this.nextElementSibling.innerText = this.value + 'PX'">
                                        <span class="active-val"><?php echo strtoupper(htmlspecialchars($val)); ?>PX</span>
                                    </div>
                                <?php else: ?>
                                    <input type="text" name="skin_opt[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($val); ?>">
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <div class="post-col-right">
                        <?php 
                        for ($i = $half; $i < count($grouped_opts); $i++): 
                            $k = $grouped_opts[$i]['key']; $o = $grouped_opts[$i]['meta']; 
                            $val = (isset($settings[$k]) && $settings[$k] !== '') ? $settings[$k] : $o['default'];
                        ?>
                            <div class="lens-input-wrapper">
                                <label><?php echo strtoupper($o['label']); ?></label>
                                <?php if ($o['type'] === 'color'): ?>
                                    <div class="color-picker-container">
                                        <input type="color" name="skin_opt[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($val); ?>">
                                        <span class="hex-display"><?php echo strtoupper(htmlspecialchars($val)); ?></span>
                                    </div>
                                <?php elseif ($o['type'] === 'range'): ?>
                                    <div class="range-wrapper">
                                        <input type="range" name="skin_opt[<?php echo $k; ?>]" min="<?php echo $o['min']; ?>" max="<?php echo $o['max']; ?>" value="<?php echo htmlspecialchars($val); ?>" oninput="this.nextElementSibling.innerText = this.value + 'PX'">
                                        <span class="active-val"><?php echo strtoupper(htmlspecialchars($val)); ?>PX</span>
                                    </div>
                                <?php else: ?>
                                    <input type="text" name="skin_opt[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($val); ?>">
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-action-row">
            <button type="submit" name="save_global_appearance" class="master-update-btn">SAVE GLOBAL APPEARANCE SETTINGS</button>
        </div>
    </form>
</div>

<?php include 'core/admin-footer.php'; ?>