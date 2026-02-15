<?php
/**
 * SnapSmack - Skin Admin
 * Version: 7.9 - Metadata Mapping Fix
 */

require_once 'core/auth.php';

// --- 1. PUBLIC SKIN DISCOVERY (The primary focus of this page) ---
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
$target_skin = $_GET['s'] ?? $current_db_active;
if (!isset($available_skins[$target_skin])) $target_skin = array_key_first($available_skins);

// This is the manifest for the PUBLIC skin we are currently editing
$manifest = include "skins/{$target_skin}/manifest.php";

function prefix_skin_css($css, $prefix) {
    if (empty($css)) return "";
    return preg_replace('/([^\r\n,{}]+)(?=[^}]*{)/', $prefix . ' $1', $css);
}

// --- 2. THE ROBUST COMPILER ENGINE ---
if (isset($_POST['save_skin_settings'])) {
    if (isset($_POST['skin_opt'])) {
        foreach ($_POST['skin_opt'] as $s_key => $s_val) {
            $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
            $stmt->execute([$s_key, $s_val, $s_val]);
        }
    }
    
    $v_settings['active_skin'] = $_POST['active_skin_target'] ?? $target_skin;
    $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('active_skin', ?) ON DUPLICATE KEY UPDATE setting_val = ?");
    $stmt->execute([$v_settings['active_skin'], $v_settings['active_skin']]);

    // Regenerate FULL CSS to protect Global architecture
    $all_settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $generated_public = "/* SKIN_START */\n/* Generated for: {$manifest['name']} */\n";
    foreach ($manifest['options'] as $key => $meta) {
        $val = $all_settings[$key] ?? $meta['default'];
        if ($meta['type'] === 'range' || $meta['type'] === 'number') {
            $css_val = (substr($meta['property'], 0, 2) === '--') ? $val : $val . "px";
            $generated_public .= "{$meta['selector']} { {$meta['property']}: {$css_val}; }\n";
        } else {
            $generated_public .= "{$meta['selector']} { {$meta['property']}: {$val}; }\n";
        }
    }
    $generated_public .= "/* SKIN_END */";

    $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'custom_css_public'")->execute([$generated_public]);
    header("Location: smack-skin.php?s={$v_settings['active_skin']}&msg=updated");
    exit;
}

$page_title = "SKIN ADMIN";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>SKIN ADMIN</h2>
    
    <div class="box appearance-controller">
        <div class="skin-meta-wrap">
            <h4 class="skin-active-name"><?php echo strtoupper($manifest['name']); ?> <span class="v-tag">v<?php echo $manifest['version']; ?></span></h4>
            <p class="skin-desc-text"><?php echo $manifest['description']; ?></p>
            <div class="skin-author-line">
                BY <?php echo strtoupper($manifest['author']); ?> | 
                <a href="mailto:<?php echo $manifest['support']; ?>" class="support-link">SUPPORT</a>
            </div>
        </div>
        <div class="skin-selector-wrap">
            <form method="GET" class="inline-skin-form">
                <label>SKIN SELECTOR</label>
                <select name="s" onchange="this.form.submit()">
                    <?php foreach ($available_skins as $slug => $name): ?>
                        <option value="<?php echo $slug; ?>" <?php echo ($target_skin == $slug) ? 'selected' : ''; ?>><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if(isset($_GET['msg'])): ?><div class="msg">> SKIN ARCHITECTURE UPDATED</div><?php endif; ?>

    <form method="POST">
        <input type="hidden" name="active_skin_target" value="<?php echo $target_skin; ?>">
        <div id="smack-skin-config-wrap">
            <?php 
            $sec = [];
            foreach ($manifest['options'] as $k => $o) { 
                // Global sections are filtered out to smack-pimpitup.php
                if ($o['section'] === 'STATIC PAGE STYLING' || $o['section'] === 'WALL SPECIFIC') continue;
                $sec[$o['section'] ?? 'GENERAL'][$k] = $o; 
            }
            foreach ($sec as $title => $opts): ?>
                <div class="box">
                    <h3><?php echo $title; ?></h3>
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
        <button type="submit" name="save_skin_settings" class="master-update-btn">SAVE SKIN SPECIFIC TOOLS</button>
    </form>
</div>
<?php include 'core/admin-footer.php'; ?>