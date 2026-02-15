<?php
/**
 * SnapSmack - Global Appearance Settings
 * Version: 1.8 - Robust Compiler + New Admin Folder Logic
 */

require_once 'core/auth.php';

// --- 1. LOAD ACTIVE MANIFEST (Needed for Global Controls) ---
$skin_dirs = array_filter(glob('skins/*'), 'is_dir');
$available_skins = [];
foreach ($skin_dirs as $dir) {
    $slug = basename($dir);
    if (file_exists($dir . '/manifest.php')) {
        $temp_manifest = include $dir . '/manifest.php';
        $available_skins[$slug] = $temp_manifest['name'] ?? ucfirst($slug);
    }
}
$current_db_active = $settings['active_skin'] ?? array_key_first($available_skins);
$manifest = include "skins/{$current_db_active}/manifest.php";

function prefix_skin_css($css, $prefix) {
    if (empty($css)) return "";
    return preg_replace('/([^\r\n,{}]+)(?=[^}]*{)/', $prefix . ' $1', $css);
}

// --- 2. GLOBAL SETTINGS HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_global_appearance'])) {
    
    if (isset($_POST['active_admin_theme'])) {
        $stmt = $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'active_theme'");
        $stmt->execute([$_POST['active_admin_theme']]);
    }

    $v_settings = $_POST['settings'] ?? [];
    foreach ($v_settings as $vk => $vv) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute([$vk, $vv, $vv]);
    }

    if (isset($_POST['skin_opt'])) {
        foreach ($_POST['skin_opt'] as $s_key => $s_val) {
            $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
            $stmt->execute([$s_key, $s_val, $s_val]);
        }
    }
    
    $all_settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $generated_public = "/* SKIN_START */\n/* Generated for: {$manifest['name']} */\n";
    foreach ($manifest['options'] as $key => $meta) {
        $val = $all_settings[$key] ?? $meta['default'];
        if ($meta['property'] === 'custom-framing' && isset($meta['options'][$val]['css'])) {
            $generated_public .= "{$meta['selector']} {$meta['options'][$val]['css']}\n";
        } elseif ($meta['type'] === 'range' || $meta['type'] === 'number') {
            $css_val = (substr($meta['property'], 0, 2) === '--') ? $val : $val . "px";
            $link_fix = ($meta['selector'] === '.site-title-text') ? "text-decoration: none !important;" : "";
            $generated_public .= "{$meta['selector']} { {$meta['property']}: {$css_val}; {$link_fix} }\n";
        } elseif ($meta['property'] === 'background-image') {
            $generated_public .= "{$meta['selector']} { {$meta['property']}: url('{$val}'); }\n";
        } elseif ($meta['property'] === 'font-family') {
            $generated_public .= "{$meta['selector']} { font-family: \"{$val}\", serif; text-decoration: none !important; }\n";
        } else {
            $generated_public .= "{$meta['selector']} { {$meta['property']}: {$val}; }\n";
        }
    }
    $generated_public .= "/* SKIN_END */";

    $admin_css_raw = $manifest['admin_styling'] ?? "";
    $prefixed_admin = "/* SKIN_START */\n" . prefix_skin_css($admin_css_raw, "#smack-skin-config-wrap") . "\n/* SKIN_END */";

    $db_map = ['custom_css_public' => $generated_public, 'custom_css_admin' => $prefixed_admin];
    foreach ($db_map as $dk => $dv) {
        $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = ?");
        $stmt->execute([$dk]);
        $current_blob = $stmt->fetchColumn() ?: "";
        $pattern = '/\/\* SKIN_START \*\/.*?\/\* SKIN_END \*\//s';
        $final_block = preg_match($pattern, $current_blob) ? preg_replace($pattern, $dv, $current_blob) : $dv . "\n\n" . $current_blob;
        $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = ?")->execute([$final_block, $dk]);
    }

    header("Location: smack-pimpitup.php?msg=updated");
    exit;
}

// --- 3. ADMIN THEME DISCOVERY (New Folder/Manifest Structure) ---
$admin_themes = [];
$theme_dirs = array_filter(glob('assets/adminthemes/*'), 'is_dir');
foreach ($theme_dirs as $dir) {
    $slug = basename($dir);
    $manifest_path = "{$dir}/{$slug}-manifest.php";
    if (file_exists($manifest_path)) {
        $admin_themes[$slug] = include $manifest_path;
    }
}

$active_admin_slug = $settings['active_theme'] ?? 'midnight-lime';
$current_admin_meta = $admin_themes[$active_admin_slug] ?? [
    'name' => $active_admin_slug, 
    'description' => 'Admin theme manifest missing.', 
    'version' => '1.0', 
    'author' => 'System'
];

$page_title = "GLOBAL APPEARANCE SETTINGS";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>GLOBAL APPEARANCE SETTINGS</h2>

    <?php if (isset($_GET['msg'])): ?><div class="msg">> SYSTEM ARCHITECTURE UPDATED</div><?php endif; ?>

    <form method="POST">
        <div class="box appearance-controller">
            <div class="skin-meta-wrap">
                <h4 class="skin-active-name"><?php echo strtoupper($current_admin_meta['name']); ?> <span class="v-tag">v<?php echo $current_admin_meta['version']; ?></span></h4>
                <p class="skin-desc-text"><?php echo $current_admin_meta['description']; ?></p>
                <div class="skin-author-line">BY <?php echo strtoupper($current_admin_meta['author']); ?> | <a href="mailto:<?php echo $current_admin_meta['support']; ?>" class="support-link">SUPPORT</a></div>
            </div>
            <div class="skin-selector-wrap">
                <label>SKIN SELECTOR</label>
                <select name="active_admin_theme" onchange="this.form.submit()">
                    <?php foreach ($admin_themes as $slug => $meta): ?>
                        <option value="<?php echo $slug; ?>" <?php echo ($active_admin_slug == $slug) ? 'selected' : ''; ?>><?php echo htmlspecialchars($meta['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="save_global_appearance" value="1">
            </div>
        </div>

        <div id="smack-skin-config-wrap">
            <?php 
            $sec = [];
            foreach ($manifest['options'] as $k => $o) { 
                if ($o['section'] === 'STATIC PAGE STYLING' || $o['section'] === 'WALL SPECIFIC') {
                    $sec[$o['section']][$k] = $o; 
                }
            }
            uksort($sec, function($a, $b) {
                $m = ['STATIC PAGE STYLING' => 1, 'WALL SPECIFIC' => 2];
                return ($m[$a] ?? 99) <=> ($m[$b] ?? 99);
            });

            foreach ($sec as $title => $opts): ?>
                
                <?php if ($title === 'STATIC PAGE STYLING'): ?>
                    <div class="box">
                        <h3>ARCHIVE ARCHITECTURE</h3>
                        <div class="dash-grid">
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
                    <h3><?php echo ($title === 'WALL SPECIFIC') ? 'GALLERY WALL SETTINGS' : $title; ?></h3>
                    
                    <?php if ($title === 'WALL SPECIFIC'): ?>
                        <div class="control-group">
                            <label>Wall Engine Access</label>
                            <select name="settings[show_wall_link]">
                                <option value="1" <?php echo (($settings['show_wall_link'] ?? '1') == '1') ? 'selected' : ''; ?>>Enabled</option>
                                <option value="0" <?php echo (($settings['show_wall_link'] ?? '1') == '0') ? 'selected' : ''; ?>>Disabled</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php foreach ($opts as $k => $o): $current_val = $settings[$k] ?? $o['default']; ?>
                        <div class="control-group">
                            <label><?php echo $o['label']; ?></label>
                            <?php if ($o['type'] === 'color'): ?>
                                <div class="color-picker-container">
                                    <input type="color" name="skin_opt[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($current_val); ?>">
                                    <span class="hex-display"><?php echo htmlspecialchars($current_val); ?></span>
                                </div>
                            <?php elseif ($o['type'] === 'select'): ?>
                                <select name="skin_opt[<?php echo $k; ?>]">
                                    <?php foreach ($o['options'] as $sv => $sl): $opt_label = is_array($sl) ? $sl['label'] : $sl; ?>
                                        <option value="<?php echo $sv; ?>" <?php echo ($current_val == $sv) ? 'selected' : ''; ?>><?php echo $opt_label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($o['type'] === 'range'): ?>
                                <div class="control-group-flex">
                                    <input type="range" name="skin_opt[<?php echo $k; ?>]" min="<?php echo $o['min']; ?>" max="<?php echo $o['max']; ?>" value="<?php echo htmlspecialchars($current_val); ?>" oninput="this.nextElementSibling.innerText = this.value + 'px'">
                                    <span class="active-val"><?php echo htmlspecialchars($current_val); ?>px</span>
                                </div>
                            <?php else: ?>
                                <input type="text" name="skin_opt[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($current_val); ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="submit" name="save_global_appearance" class="master-update-btn">SAVE GLOBAL APPEARANCE SETTINGS</button>
    </form>
</div>
<?php include 'core/admin-footer.php'; ?>