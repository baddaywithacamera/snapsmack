<?php
/**
 * SnapSmack - Appearance & Architecture Master Admin
 * Version: 8.5 - Dual-Engine Restoration (Admin Themes + Public Compiler)
 * -------------------------------------------------------------------------
 * - RESTORED: All public skin compiler logic and manifest metadata processing.
 * - RESTORED: Archive Architecture grid logic (Thumb Size / Columns).
 * - RESTORED: Wall Specific parameters and Global Toggle logic.
 * - INTEGRATED: Canadian Admin Theme Discovery (admin-theme-colours-*.css).
 * - DIRECTIVE: FULL FILE OUTPUT. NO TRUNCATION.
 * -------------------------------------------------------------------------
 */

// Force error reporting for verification
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'core/auth.php';

// --- 1. DYNAMIC PUBLIC SKIN DISCOVERY ---
$skin_dirs = array_filter(glob('skins/*'), 'is_dir');
$available_skins = [];
foreach ($skin_dirs as $dir) {
    $slug = basename($dir);
    if (file_exists($dir . '/manifest.php')) {
        $temp_manifest = include $dir . '/manifest.php';
        $available_skins[$slug] = $temp_manifest['name'] ?? ucfirst($slug);
    }
}

// Determine target skin context (for public site editing)
$current_db_active_skin = $settings['active_skin'] ?? array_key_first($available_skins);
$target_skin = $_GET['s'] ?? $current_db_active_skin;
if (!isset($available_skins[$target_skin])) { $target_skin = array_key_first($available_skins); }

// Load target manifest for compiler metadata
$manifest = include "skins/{$target_skin}/manifest.php";

// --- 2. PREFIXING UTILITY (for Admin Previews) ---
function prefix_skin_css($css, $prefix) {
    if (empty($css)) return "";
    return preg_replace('/([^\r\n,{}]+)(?=[^}]*{)/', $prefix . ' $1', $css);
}

// --- 3. TARGETED ADMIN THEME DISCOVERY (Scanning assets/css/) ---
$admin_themes = [];
$admin_css_files = glob('assets/css/admin-theme-colours-*.css');
foreach ($admin_css_files as $file) {
    $slug = str_replace(['assets/css/admin-theme-colours-', '.css'], '', $file);
    $display_name = ucfirst($slug);
    $handle = fopen($file, 'r');
    $header_chunk = fread($handle, 250);
    fclose($handle);
    if (preg_match('/Theme Name:\s*(.*)$/mi', $header_chunk, $matches)) {
        $display_name = trim($matches[1]);
    }
    $admin_themes[$slug] = $display_name;
}

// --- 4. THE COMPILER & SETTINGS HANDLER ---
if (isset($_POST['save_appearance'])) {
    
    // 4.1 Save Admin Interface Theme choice
    if (isset($_POST['active_admin_theme'])) {
        $stmt = $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'active_theme'");
        $stmt->execute([$_POST['active_admin_theme']]);
    }

    // 4.2 Start public CSS generation
    $generated_public = "/* SKIN_START */\n/* Generated for: {$manifest['name']} */\n";
    
    if (isset($_POST['skin_opt'])) {
        foreach ($_POST['skin_opt'] as $s_key => $s_val) {
            // A. PERSISTENCE
            $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
            $stmt->execute([$s_key, $s_val, $s_val]);
            
            // B. COMPILER
            if (isset($manifest['options'][$s_key])) {
                $opt_meta = $manifest['options'][$s_key];
                
                if ($opt_meta['property'] === 'custom-framing' && isset($opt_meta['options'][$s_val]['css'])) {
                    $generated_public .= "{$opt_meta['selector']} {$opt_meta['options'][$s_val]['css']}\n";
                } 
                elseif ($opt_meta['type'] === 'range' || $opt_meta['type'] === 'number') {
                    if (substr($opt_meta['property'], 0, 2) === '--') {
                        $generated_public .= "{$opt_meta['selector']} { {$opt_meta['property']}: {$s_val}; }\n";
                    } else {
                        $link_fix = ($opt_meta['selector'] === '.site-title-text') ? "text-decoration: none !important;" : "";
                        $generated_public .= "{$opt_meta['selector']} { {$opt_meta['property']}: {$s_val}px; {$link_fix} }\n";
                    }
                } 
                elseif ($opt_meta['property'] === 'background-image') {
                    $generated_public .= "{$opt_meta['selector']} { {$opt_meta['property']}: url('{$s_val}'); }\n";
                } 
                elseif ($opt_meta['property'] === 'font-family') {
                    $generated_public .= "{$opt_meta['selector']} { font-family: \"{$s_val}\", serif; text-decoration: none !important; }\n";
                }
                else {
                    $generated_public .= "{$opt_meta['selector']} { {$opt_meta['property']}: {$s_val}; }\n";
                }
            }
        }
    }
    $generated_public .= "/* SKIN_END */";

    // 4.3 ADMIN PREVIEW GENERATION
    $admin_css_raw = $manifest['admin_styling'] ?? "";
    $prefixed_admin = "/* SKIN_START */\n" . prefix_skin_css($admin_css_raw, "#smack-skin-config-wrap") . "\n/* SKIN_END */";

    // 4.4 GLOBAL ARCHITECTURE SETTINGS (Thumb size, columns, active public skin)
    $v_settings = $_POST['settings'] ?? [];
    $v_settings['active_skin'] = $_POST['active_skin_target'] ?? $target_skin;
    
    foreach ($v_settings as $vk => $vv) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute([$vk, $vv, $vv]);
    }

    // 4.5 DATABASE COMMIT (CSS Blobs)
    $db_map = ['custom_css_public' => $generated_public, 'custom_css_admin' => $prefixed_admin];
    foreach ($db_map as $dk => $dv) {
        $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = ?");
        $stmt->execute([$dk]);
        $current_blob = $stmt->fetchColumn() ?: "";
        $pattern = '/\/\* SKIN_START \*\/.*?\/\* SKIN_END \*\//s';
        
        $final_block = preg_match($pattern, $current_blob) 
            ? preg_replace($pattern, $dv, $current_blob) 
            : $dv . "\n\n" . $current_blob;

        $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = ?")->execute([$final_block, $dk]);
    }

    header("Location: smack-appearance.php?s={$v_settings['active_skin']}&msg=updated");
    exit;
}

$page_title = "Appearance & Architecture";
include 'core/admin-header.php';
include 'core/sidebar.php';

$active_admin_theme = $settings['active_theme'] ?? 'smackdown-midnight-lime';
?>

<div class="main">
    <h2>APPEARANCE & ARCHITECTURE</h2>

    <div class="box appearance-controller">
        <div class="skin-meta-wrap">
            <h3 class="skin-active-name">ADMIN INTERFACE SKIN</h3>
            <p class="skin-desc-text">Select the visual layer for this admin console. This pulls metadata directly from the Canadian-spelt CSS headers in assets/css/.</p>
            <div class="skin-author-line">ARCHITECTED BY SEAN & GEMINI</div>
        </div>
        <div class="skin-selector-wrap">
            <form method="POST" class="inline-skin-form">
                <label>ACTIVE ADMIN THEME</label>
                <select name="active_admin_theme" onchange="this.form.submit()">
                    <?php foreach ($admin_themes as $slug => $name): ?>
                        <option value="<?php echo $slug; ?>" <?php echo ($active_admin_theme == $slug) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="save_appearance" value="1">
            </form>
        </div>
    </div>

    <div class="box appearance-controller">
        <div class="skin-meta-wrap">
            <h4 class="skin-active-name"><?php echo strtoupper($available_skins[$target_skin]); ?> <span class="v-tag">v<?php echo $manifest['version']; ?></span></h4>
            <p class="skin-desc-text"><?php echo $manifest['description']; ?></p>
            <div class="skin-author-line">
                BY <?php echo strtoupper($manifest['author']); ?> 
                <?php if(!empty($manifest['support'])): ?>
                    | <a href="mailto:<?php echo $manifest['support']; ?>" class="support-link">SUPPORT: <?php echo strtoupper($manifest['support']); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <div class="skin-selector-wrap">
            <form method="GET" class="inline-skin-form">
                <label>ACTIVE PUBLIC SITE SKIN</label>
                <select name="s" onchange="this.form.submit()">
                    <?php foreach ($available_skins as $slug => $name): ?>
                        <option value="<?php echo $slug; ?>" <?php echo ($target_skin == $slug) ? 'selected' : ''; ?>><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if(isset($_GET['msg'])): ?><div class="msg">> SYSTEM ARCHITECTURE UPDATED</div><?php endif; ?>

    <form method="POST">
        <input type="hidden" name="active_skin_target" value="<?php echo $target_skin; ?>">
        <input type="hidden" name="active_admin_theme" value="<?php echo $active_admin_theme; ?>">

        <div id="smack-skin-config-wrap">
            <?php 
            $sec = [];
            foreach ($manifest['options'] as $k => $o) { $sec[$o['section'] ?? 'GENERAL'][$k] = $o; }
            
            uksort($sec, function($a, $b) {
                $m = ['SKIN SPECIFIC' => 1, 'STATIC PAGE STYLING' => 3, 'WALL SPECIFIC' => 4];
                return ($m[$a] ?? 99) <=> ($m[$b] ?? 99);
            });

            foreach ($sec as $title => $opts): ?>
                
                <?php if ($title === 'STATIC PAGE STYLING'): ?>
                    <div class="box">
                        <h3>ARCHIVE ARCHITECTURE</h3>
                        <div class="engine-grid">
                            <div class="control-group">
                                <label>Thumbnail Width (px)</label>
                                <input type="number" name="settings[thumb_size]" value="<?php echo htmlspecialchars($settings['thumb_size'] ?? 400); ?>">
                            </div>
                            <div class="control-group">
                                <label>Browse Columns</label>
                                <input type="number" name="settings[browse_cols]" value="<?php echo htmlspecialchars($settings['browse_cols'] ?? 4); ?>">
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="box">
                    <h3><?php echo $title; ?></h3>
                    
                    <?php if ($title === 'WALL SPECIFIC'): ?>
                        <div class="control-group">
                            <label>Wall Engine Access</label>
                            <select name="settings[show_wall_link]">
                                <option value="1" <?php echo (($settings['show_wall_link'] ?? '1') == '1') ? 'selected' : ''; ?>>Enabled</option>
                                <option value="0" <?php echo (($settings['show_wall_link'] ?? '1') == '0') ? 'selected' : ''; ?>>Disabled</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($opts as $k => $o): 
                        $current_val = $settings[$k] ?? $o['default'];
                    ?>
                        <div class="control-group">
                            <label><?php echo $o['label']; ?></label>
                            
                            <?php if ($o['type'] === 'color'): ?>
                                <div class="color-picker-container">
                                    <input type="color" name="skin_opt[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($current_val); ?>">
                                    <span class="hex-display"><?php echo htmlspecialchars($current_val); ?></span>
                                </div>
                            
                            <?php elseif ($o['type'] === 'select'): ?>
                                <select name="skin_opt[<?php echo $k; ?>]">
                                    <?php foreach ($o['options'] as $sv => $sl): 
                                        $opt_label = is_array($sl) ? $sl['label'] : $sl; ?>
                                        <option value="<?php echo $sv; ?>" <?php echo ($current_val == $sv) ? 'selected' : ''; ?>><?php echo $opt_label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            
                            <?php elseif ($o['type'] === 'range'): ?>
                                <div class="control-group-flex">
                                    <input type="range" name="skin_opt[<?php echo $k; ?>]" 
                                           min="<?php echo $o['min']; ?>" max="<?php echo $o['max']; ?>" 
                                           value="<?php echo htmlspecialchars($current_val); ?>"
                                           oninput="this.nextElementSibling.innerText = this.value + 'px'">
                                    <span class="active-val"><?php echo htmlspecialchars($current_val); ?>px</span>
                                </div>
                            
                            <?php elseif ($o['type'] === 'number'): ?>
                                <input type="number" step="0.01" name="skin_opt[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($current_val); ?>">
                            
                            <?php else: ?>
                                <input type="text" name="skin_opt[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($current_val); ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <button type="submit" name="save_appearance" class="master-update-btn">SAVE APPEARANCE & ARCHITECTURE</button>
    </form>
</div>

<?php include 'core/admin-footer.php'; ?>