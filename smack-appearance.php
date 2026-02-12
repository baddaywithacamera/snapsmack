<?php
/**
 * SnapSmack - Appearance & Architecture Master Admin
 * Version: 6.2 - Support Link Alignment & Metadata Restoration
 * -------------------------------------------------------------------------
 * - FIXED: Support Email pulls from manifest.php 'support' key.
 * - FIXED: Support Link moved inline with Author name (Top Left Box).
 * - FIXED: Inline pipe separator and hyperlinked email in author font.
 * - FIXED: Typography logic. Values with spaces now wrapped in quotes.
 * - FIXED: Branding Underline. Forced text-decoration:none for .site-title-text.
 * - FIXED: UI Persistence. Sliders and dropdowns now stay where you set them.
 * - FIXED: Page title variable restored to remove header warning.
 * - FIXED: Explicit Payload Extraction for Frame Library (Manifest v3.1).
 * - RESTORED: Full Archive Architecture Grid logic (Thumb Width / Columns).
 * - RESTORED: Wall Specific parameters and Global Toggle logic.
 * - DIRECTIVE: FULL FILE OUTPUT. NO TRUNCATION. NO CONDENSATION.
 * -------------------------------------------------------------------------
 */

// Force error reporting for verification
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'core/auth.php';

// --- 1. DYNAMIC SKIN DISCOVERY ---
$skin_dirs = array_filter(glob('skins/*'), 'is_dir');
$available_skins = [];
foreach ($skin_dirs as $dir) {
    $slug = basename($dir);
    if (file_exists($dir . '/manifest.php')) {
        $temp_manifest = include $dir . '/manifest.php';
        $available_skins[$slug] = $temp_manifest['name'] ?? ucfirst($slug);
    }
}

// Determine target skin context
$current_db_active = $settings['active_skin'] ?? array_key_first($available_skins);
$target_skin = $_GET['s'] ?? $current_db_active;
if (!isset($available_skins[$target_skin])) { $target_skin = array_key_first($available_skins); }

// Load target manifest
$manifest = include "skins/{$target_skin}/manifest.php";

// --- 2. PREFIXING UTILITY ---
function prefix_skin_css($css, $prefix) {
    if (empty($css)) return "";
    return preg_replace('/([^\r\n,{}]+)(?=[^}]*{)/', $prefix . ' $1', $css);
}

// --- 3. THE COMPILER & SETTINGS HANDLER ---
if (isset($_POST['save_appearance'])) {
    
    // Start public CSS generation
    $generated_public = "/* SKIN_START */\n/* Generated for: {$manifest['name']} */\n";
    
    // 3.1 PROCESS SKIN OPTIONS (Choices & CSS Generation)
    if (isset($_POST['skin_opt'])) {
        foreach ($_POST['skin_opt'] as $s_key => $s_val) {
            
            // A. PERSISTENCE: Save the individual choice to DB so the UI stays set
            $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
            $stmt->execute([$s_key, $s_val, $s_val]);
            
            // B. COMPILER: Look up manifest metadata for this key to generate CSS
            if (isset($manifest['options'][$s_key])) {
                $opt_meta = $manifest['options'][$s_key];
                
                // Case 1: Payload Extraction (Frames)
                if ($opt_meta['property'] === 'custom-framing' && isset($opt_meta['options'][$s_val]['css'])) {
                    $generated_public .= "{$opt_meta['selector']} {$opt_meta['options'][$s_val]['css']}\n";
                } 
                // Case 2: Range/Number processing
                elseif ($opt_meta['type'] === 'range' || $opt_meta['type'] === 'number') {
                    if (substr($opt_meta['property'], 0, 2) === '--') {
                        $generated_public .= "{$opt_meta['selector']} { {$opt_meta['property']}: {$s_val}; }\n";
                    } else {
                        // Apply link cleanup if this range is targeting the site title
                        $link_fix = ($opt_meta['selector'] === '.site-title-text') ? "text-decoration: none !important;" : "";
                        $generated_public .= "{$opt_meta['selector']} { {$opt_meta['property']}: {$s_val}px; {$link_fix} }\n";
                    }
                } 
                // Case 3: Image properties
                elseif ($opt_meta['property'] === 'background-image') {
                    $generated_public .= "{$opt_meta['selector']} { {$opt_meta['property']}: url('{$s_val}'); }\n";
                } 
                // Case 4: Typography (NEW: Handle font names with spaces and clear link underlines)
                elseif ($opt_meta['property'] === 'font-family') {
                    $generated_public .= "{$opt_meta['selector']} { font-family: \"{$s_val}\", serif; text-decoration: none !important; }\n";
                }
                // Case 5: Standard values (Colors, Simple Strings)
                else {
                    $generated_public .= "{$opt_meta['selector']} { {$opt_meta['property']}: {$s_val}; }\n";
                }
            }
        }
    }
    $generated_public .= "/* SKIN_END */";

    // 3.2 ADMIN PREVIEW GENERATION
    $admin_css_raw = $manifest['admin_styling'] ?? "";
    $prefixed_admin = "/* SKIN_START */\n" . prefix_skin_css($admin_css_raw, "#smack-skin-config-wrap") . "\n/* SKIN_END */";

    // 3.3 GLOBAL ARCHITECTURE & SYSTEM SETTINGS
    $v_settings = $_POST['settings'] ?? [];
    // Ensure the skin we are editing becomes the active skin
    $v_settings['active_skin'] = $_POST['active_skin_target'] ?? $target_skin;
    
    foreach ($v_settings as $vk => $vv) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute([$vk, $vv, $vv]);
    }

    // 3.4 DATABASE COMMIT (Generated CSS Overrides)
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

    $msg = "Visual architecture updated. Typography and Branding locks applied.";
    
    // Refresh local settings array immediately
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// RESTORED: Fixed Header Undefined Variable Warning
$page_title = "Appearance & Architecture";

include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>APPEARANCE & ARCHITECTURE</h2>

    <div class="box appearance-controller">
        <div class="skin-meta-wrap">
            <h4 class="skin-active-name"><?php echo strtoupper($available_skins[$target_skin]); ?> <span class="v-tag">v<?php echo $manifest['version']; ?></span></h4>
            <p class="skin-desc-text"><?php echo $manifest['description']; ?></p>
            <div class="skin-author-line">
                BY <?php echo strtoupper($manifest['author']); ?> 
                <?php if(!empty($manifest['support'])): ?>
                    | <a href="mailto:<?php echo $manifest['support']; ?>" style="color:inherit; text-decoration:none;">SUPPORT: <?php echo strtoupper($manifest['support']); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <div class="skin-selector-wrap">
            <form method="GET" class="inline-skin-form">
                <label>ACTIVE SYSTEM SKIN</label>
                <select name="s" onchange="this.form.submit()">
                    <?php foreach ($available_skins as $slug => $name): ?>
                        <option value="<?php echo $slug; ?>" <?php echo ($target_skin == $slug) ? 'selected' : ''; ?>><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if(isset($msg)): ?><div class="msg">> <?php echo $msg; ?></div><?php endif; ?>

    <form method="POST">
        <input type="hidden" name="active_skin_target" value="<?php echo $target_skin; ?>">

        <div id="smack-skin-config-wrap">
            <?php 
            $sec = [];
            foreach ($manifest['options'] as $k => $o) { $sec[$o['section'] ?? 'GENERAL'][$k] = $o; }
            
            // UI ORDER: Skin Specific -> Archive Architecture -> Static Pages -> Wall Engine
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
                        // Fetch the current saved choice from DB, fallback to manifest default
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