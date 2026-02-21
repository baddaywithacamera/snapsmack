<?php
/**
 * SNAPSMACK - Skin Architect
 * Core engine for discovering, configuring, and compiling skin-specific CSS.
 * -------------------------------------------------------------------------
 * HOUSEKEEPING:
 * - Removed legacy versioning/session notes.
 * - Standardized Unit Handling: Range sliders now support CSS Variables without forcing 'PX'.
 * - Automated CSS Compilation: Writes variables directly to public/admin buffers.
 */

require_once 'core/auth.php';

// --- 1. SKIN DISCOVERY ---
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

$manifest = include "skins/{$target_skin}/manifest.php";

// --- 2. COMPILER & SAVE HANDLER ---
if (isset($_POST['save_skin_settings'])) {
    if (isset($_POST['skin_opt'])) {
        foreach ($_POST['skin_opt'] as $s_key => $s_val) {
            $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) 
                           ON DUPLICATE KEY UPDATE setting_val = ?")->execute([$s_key, $s_val, $s_val]);
        }
    }
    
    $active_skin = $_POST['active_skin_target'] ?? $target_skin;
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('active_skin', ?) 
                   ON DUPLICATE KEY UPDATE setting_val = ?")->execute([$active_skin, $active_skin]);

    $all_settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // BUILD CSS BUFFER
    $generated_public = "/* SNAPSMACK DYNAMIC CSS - DO NOT EDIT */\n";
    foreach ($manifest['options'] as $key => $meta) {
        $val = (isset($all_settings[$key]) && $all_settings[$key] !== '') ? $all_settings[$key] : $meta['default'];

        if ($meta['type'] === 'range' || $meta['type'] === 'number') {
            // UNIT AWARENESS: Use --variable as-is, otherwise append 'px'
            $unit = (substr($meta['property'], 0, 2) === '--') ? '' : 'px';
            $generated_public .= "{$meta['selector']} { {$meta['property']}: {$val}{$unit}; }\n";
        } elseif ($meta['property'] === 'font-family') {
            $generated_public .= "{$meta['selector']} { font-family: \"{$val}\", sans-serif; }\n";
        } else {
            $generated_public .= "{$meta['selector']} { {$meta['property']}: {$val}; }\n";
        }
    }

    $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'custom_css_public'")->execute([$generated_public]);

    header("Location: smack-skin.php?s={$active_skin}&msg=updated");
    exit;
}

$page_title = "Skin Architect";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="box appearance-controller">
        <div class="skin-meta-wrap">
            <div class="theme-title-display">
                <?php echo strtoupper($manifest['name']); ?>
            </div>
            <p class="skin-desc-text"><?php echo $manifest['description']; ?></p>
            <div class="dim">AUTHOR: <?php echo strtoupper($manifest['author']); ?></div>
        </div>

        <div class="skin-selector-wrap">
            <form method="GET">
                <label>SELECT ACTIVE SKIN</label>
                <select name="s" onchange="this.form.submit()">
                    <?php foreach ($available_skins as $slug => $name): ?>
                        <option value="<?php echo $slug; ?>" <?php echo ($target_skin == $slug) ? 'selected' : ''; ?>><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if(isset($_GET['msg'])): ?><div class="alert alert-success">> CALIBRATION STORED</div><?php endif; ?>

    <form method="POST">
        <input type="hidden" name="active_skin_target" value="<?php echo $target_skin; ?>">
        <div id="smack-skin-config-wrap">
            <?php 
            $sec = [];
            foreach ($manifest['options'] as $k => $o) { 
                $sec[$o['section'] ?? 'GENERAL'][] = ['key' => $k, 'meta' => $o]; 
            }
            foreach ($sec as $title => $opts): ?>
                <div class="box">
                    <h3><?php echo $title; ?></h3>
                    <?php foreach ($opts as $item): 
                        $k = $item['key']; 
                        $o = $item['meta']; 
                        $val = ($settings[$k] ?? '') !== '' ? $settings[$k] : $o['default']; 
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
                                    <input type="range" name="skin_opt[<?php echo $k; ?>]" 
                                           min="<?php echo $o['min']; ?>" max="<?php echo $o['max']; ?>" 
                                           value="<?php echo htmlspecialchars($val); ?>" 
                                           oninput="this.nextElementSibling.innerText = this.value">
                                    <span class="active-val"><?php echo strtoupper(htmlspecialchars($val)); ?></span>
                                </div>

                            <?php elseif ($o['type'] === 'select'): ?>
                                <select name="skin_opt[<?php echo $k; ?>]">
                                    <?php foreach ($o['options'] as $sv => $sl): ?>
                                        <option value="<?php echo $sv; ?>" <?php echo ($val == $sv) ? 'selected' : ''; ?>><?php echo (is_array($sl) ? $sl['label'] : $sl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="form-action-row">
            <button type="submit" name="save_skin_settings" class="master-update-btn">SAVE CONFIGURATION</button>
        </div>
    </form>
</div>

<?php include 'core/admin-footer.php'; ?>