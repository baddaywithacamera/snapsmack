<?php
/**
 * SnapSmack - Global Appearance Settings
 * Version: 17.0 - Renamed to smack-globalvibe.php
 * -------------------------------------------------------------------------
 * - RENAMED: smack-pimpitup.php → smack-globalvibe.php
 * - FIXED: POST redirect updated to smack-globalvibe.php
 * - FIXED: $_SESSION['user_preferred_skin'] (from v16.49)
 * - RESTORED: Global Branding / Masthead Mode / Logo Upload (from v16.49)
 * - ADDED: Pimpotron engine detection from skin manifest
 * - ADDED: Wall Engine Link renders as DISABLED BY SKIN when Pimpotron active
 * - MERGED: Robust null checks and array guards throughout
 * - DIRECTIVE: FULL FILE OUTPUT. NO TRUNCATION. NO CONDENSATION.
 * -------------------------------------------------------------------------
 */

require_once 'core/auth.php';

// -------------------------------------------------------------------------
// 1. DATA DISCOVERY
// -------------------------------------------------------------------------

$active_skin_db  = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'active_skin'")->fetchColumn();
$active_theme_db = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'active_theme'")->fetchColumn();

// --- PUBLIC SKIN DISCOVERY ---
$skin_dirs       = array_filter(glob('skins/*'), 'is_dir');
$available_skins = [];
foreach ($skin_dirs as $dir) {
    $slug = basename($dir);
    if (file_exists($dir . '/manifest.php')) {
        $temp_manifest = include $dir . '/manifest.php';
        if (is_array($temp_manifest)) {
            $available_skins[$slug] = $temp_manifest['name'] ?? ucfirst($slug);
        }
    }
}

$current_db_active = $active_skin_db ?: array_key_first($available_skins);
$manifest = [];
if ($current_db_active && file_exists("skins/{$current_db_active}/manifest.php")) {
    $manifest = include "skins/{$current_db_active}/manifest.php";
}

// --- PIMPOTRON ENGINE DETECTION ---
// If the active skin manifest declares engines.pimpotron, the Wall Engine
// Link control is disabled — Pimpotron owns the hero stage exclusively.
$pimpotron_active = !empty($manifest['engines']['pimpotron']);

// --- ADMIN THEME DISCOVERY ---
$admin_themes = [];
$theme_dirs   = array_filter(glob('assets/adminthemes/*'), 'is_dir');
foreach ($theme_dirs as $dir) {
    $slug          = basename($dir);
    $manifest_path = "{$dir}/{$slug}-manifest.php";
    $meta          = [];
    if (file_exists($manifest_path)) {
        $meta = include $manifest_path;
    }
    if (!is_array($meta)) {
        $meta = [
            'name'        => strtoupper(str_replace('-', ' ', $slug)),
            'version'     => '1.0',
            'description' => 'No valid manifest found. Fallback engaged.',
            'author'      => 'System'
        ];
    }
    $admin_themes[$slug] = $meta;
}

$session_theme     = $_SESSION['user_preferred_skin'] ?? null;
$db_admin_theme    = $session_theme ?: ($active_theme_db ? trim($active_theme_db) : 'midnight-lime');
$active_admin_slug = array_key_exists($db_admin_theme, $admin_themes) ? $db_admin_theme : 'midnight-lime';

$current_admin_meta = $admin_themes[$active_admin_slug] ?? [
    'name'        => $active_admin_slug,
    'description' => 'Admin theme manifest missing.',
    'version'     => '1.0',
    'author'      => 'System'
];

// -------------------------------------------------------------------------
// 2. POST HANDLER
// -------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_global_appearance'])) {

    // A. Handle Admin Theme Change
    if (isset($_POST['active_admin_theme'])) {
        $chosen_theme = $_POST['active_admin_theme'];

        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('active_theme', ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        $stmt->execute([$chosen_theme, $chosen_theme]);

        $stmt = $pdo->prepare("UPDATE snap_users SET preferred_skin = ? WHERE username = ?");
        $stmt->execute([$chosen_theme, $_SESSION['user_login']]);

        $_SESSION['user_preferred_skin'] = $chosen_theme;
    }

    // B. Handle Global Settings (including Masthead)
    if (isset($_POST['settings']) && is_array($_POST['settings'])) {
        $v_settings = $_POST['settings'];

        // --- LOGO UPLOAD ---
        if (!empty($_FILES['site_logo_file']['name'])) {
            $upload_dir = 'assets/img/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }

            $file_name   = preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($_FILES['site_logo_file']['name']));
            $target_file = $upload_dir . time() . '_' . $file_name;

            if (move_uploaded_file($_FILES['site_logo_file']['tmp_name'], $target_file)) {
                $v_settings['site_logo'] = $target_file;
            }
        }

        foreach ($v_settings as $vk => $vv) {
            $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
            $stmt->execute([$vk, $vv, $vv]);
        }
    }

    // C. Handle Dynamic Skin Options
    if (isset($_POST['skin_opt']) && is_array($_POST['skin_opt'])) {
        foreach ($_POST['skin_opt'] as $s_key => $s_val) {
            $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
            $stmt->execute([$s_key, $s_val, $s_val]);
        }
    }

    // D. Recompile Public CSS
    $all_settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    $generated_public = "/* SKIN_START */\n";
    if (isset($manifest['options']) && is_array($manifest['options'])) {
        foreach ($manifest['options'] as $key => $meta) {
            $val     = (isset($all_settings[$key]) && $all_settings[$key] !== '') ? $all_settings[$key] : ($meta['default'] ?? '');
            $css_val = (isset($meta['type']) && ($meta['type'] === 'range' || $meta['type'] === 'number'))
                        ? ((isset($meta['property']) && substr($meta['property'], 0, 2) === '--') ? $val : $val . "px")
                        : $val;
            if (isset($meta['selector']) && isset($meta['property'])) {
                $generated_public .= "{$meta['selector']} { {$meta['property']}: {$css_val}; }\n";
            }
        }
    }
    $generated_public .= "/* SKIN_END */";

    $pdo->prepare("UPDATE snap_settings SET setting_val = ? WHERE setting_key = 'custom_css_public'")->execute([$generated_public]);

    header("Location: smack-globalvibe.php?msg=CALIBRATED");
    exit;
}

// -------------------------------------------------------------------------
// 3. RENDER
// -------------------------------------------------------------------------

$page_title = "Global Vibe";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>GLOBAL APPEARANCE SETTINGS</h2>

    <!-- ADMIN THEME SELECTOR -->
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
                <?php if (!empty($current_admin_meta['support'])): ?>
                    | <a href="mailto:<?php echo htmlspecialchars($current_admin_meta['support']); ?>" style="color: #247AA2; text-decoration: none;">SUPPORT</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="skin-selector-wrap">
            <form method="POST">
                <label>CORE ADMIN THEME</label>
                <select name="active_admin_theme" onchange="this.form.submit()">
                    <?php foreach ($admin_themes as $slug => $meta): ?>
                        <option value="<?php echo htmlspecialchars($slug); ?>" <?php echo ($active_admin_slug == $slug) ? 'selected' : ''; ?>>
                            <?php echo strtoupper(htmlspecialchars($meta['name'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="save_global_appearance" value="1">
            </form>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">> SYSTEM APPEARANCE CALIBRATED</div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div id="smack-skin-config-wrap">

            <!-- GLOBAL BRANDING -->
            <div class="box">
                <h3>GLOBAL BRANDING (MASTHEAD)</h3>
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <label>MASTHEAD MODE</label>
                        <select name="settings[masthead_type]" onchange="document.getElementById('logo-upload-group').style.display = (this.value === 'logo') ? 'block' : 'none';">
                            <option value="text" <?php echo (($settings['masthead_type'] ?? 'text') == 'text') ? 'selected' : ''; ?>>Plain Text (Public Skin Font)</option>
                            <option value="logo" <?php echo (($settings['masthead_type'] ?? 'text') == 'logo') ? 'selected' : ''; ?>>Custom Logo Image</option>
                        </select>
                    </div>

                    <div class="lens-input-wrapper" id="logo-upload-group" style="display: <?php echo (($settings['masthead_type'] ?? 'text') == 'logo') ? 'block' : 'none'; ?>;">
                        <label>UPLOAD LOGO (PNG/SVG)</label>
                        <input type="file" name="site_logo_file" accept="image/*" style="width: 100%; box-sizing: border-box; padding: 5px; background: #000; color: #ccc; border: 1px solid #333;">
                        <?php if (!empty($settings['site_logo'])): ?>
                            <div style="margin-top: 10px; padding: 10px; background: #111; border: 1px solid #333;">
                                <span class="dim">ACTIVE LOGO:</span><br>
                                <img src="<?php echo BASE_URL . htmlspecialchars($settings['site_logo']); ?>" style="max-height: 50px; margin-top: 10px;">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ARCHIVE GRID -->
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

                        <?php if ($pimpotron_active): ?>
                            <!-- Pimpotron owns the hero stage — wall is incompatible -->
                            <select disabled style="opacity: 0.4; cursor: not-allowed;">
                                <option>DISABLED BY SKIN</option>
                            </select>
                            <!-- Force value to 0 so it saves correctly even though dropdown is dead -->
                            <input type="hidden" name="settings[show_wall_link]" value="0">
                            <p class="dim" style="margin-top: 6px; font-size: 0.75em;">
                                PIMPOTRON IS ACTIVE &mdash; WALL ENGINE IS INCOMPATIBLE WITH THIS SKIN.
                            </p>
                        <?php else: ?>
                            <select name="settings[show_wall_link]">
                                <option value="1" <?php echo (($settings['show_wall_link'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                                <option value="0" <?php echo (($settings['show_wall_link'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED</option>
                            </select>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- SKIN-SPECIFIC CALIBRATION (Static Page Styling + Wall Specific from manifest) -->
            <?php
            $grouped_opts = [];
            if (isset($manifest['options']) && is_array($manifest['options'])) {
                foreach ($manifest['options'] as $k => $o) {
                    if (isset($o['section']) && ($o['section'] === 'STATIC PAGE STYLING' || $o['section'] === 'WALL SPECIFIC')) {
                        $grouped_opts[] = ['key' => $k, 'meta' => $o];
                    }
                }
            }
            if (!empty($grouped_opts)):
            ?>
            <div class="box">
                <h3>SKIN-SPECIFIC CALIBRATION</h3>
                <div class="post-layout-grid">
                    <div class="post-col-left">
                        <?php
                        $half = ceil(count($grouped_opts) / 2);
                        for ($i = 0; $i < $half; $i++):
                            $k   = $grouped_opts[$i]['key'];
                            $o   = $grouped_opts[$i]['meta'];
                            $val = (isset($settings[$k]) && $settings[$k] !== '') ? $settings[$k] : ($o['default'] ?? '');
                        ?>
                            <div class="lens-input-wrapper">
                                <label><?php echo strtoupper(htmlspecialchars($o['label'] ?? $k)); ?></label>
                                <?php if (isset($o['type']) && $o['type'] === 'color'): ?>
                                    <div class="color-picker-container">
                                        <input type="color" name="skin_opt[<?php echo htmlspecialchars($k); ?>]" value="<?php echo htmlspecialchars($val); ?>">
                                        <span class="hex-display"><?php echo strtoupper(htmlspecialchars($val)); ?></span>
                                    </div>
                                <?php elseif (isset($o['type']) && $o['type'] === 'range'): ?>
                                    <div class="range-wrapper">
                                        <input type="range"
                                               name="skin_opt[<?php echo htmlspecialchars($k); ?>]"
                                               min="<?php echo htmlspecialchars($o['min'] ?? 0); ?>"
                                               max="<?php echo htmlspecialchars($o['max'] ?? 100); ?>"
                                               value="<?php echo htmlspecialchars($val); ?>"
                                               oninput="this.nextElementSibling.innerText = this.value + 'PX'">
                                        <span class="active-val"><?php echo strtoupper(htmlspecialchars($val)); ?>PX</span>
                                    </div>
                                <?php elseif (isset($o['type']) && $o['type'] === 'select' && isset($o['options'])): ?>
                                    <select name="skin_opt[<?php echo htmlspecialchars($k); ?>]">
                                        <?php foreach ($o['options'] as $ov => $ol): ?>
                                            <?php $label = is_array($ol) ? ($ol['label'] ?? $ov) : $ol; ?>
                                            <option value="<?php echo htmlspecialchars($ov); ?>" <?php echo ($val == $ov) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="skin_opt[<?php echo htmlspecialchars($k); ?>]" value="<?php echo htmlspecialchars($val); ?>">
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <div class="post-col-right">
                        <?php
                        for ($i = $half; $i < count($grouped_opts); $i++):
                            $k   = $grouped_opts[$i]['key'];
                            $o   = $grouped_opts[$i]['meta'];
                            $val = (isset($settings[$k]) && $settings[$k] !== '') ? $settings[$k] : ($o['default'] ?? '');
                        ?>
                            <div class="lens-input-wrapper">
                                <label><?php echo strtoupper(htmlspecialchars($o['label'] ?? $k)); ?></label>
                                <?php if (isset($o['type']) && $o['type'] === 'color'): ?>
                                    <div class="color-picker-container">
                                        <input type="color" name="skin_opt[<?php echo htmlspecialchars($k); ?>]" value="<?php echo htmlspecialchars($val); ?>">
                                        <span class="hex-display"><?php echo strtoupper(htmlspecialchars($val)); ?></span>
                                    </div>
                                <?php elseif (isset($o['type']) && $o['type'] === 'range'): ?>
                                    <div class="range-wrapper">
                                        <input type="range"
                                               name="skin_opt[<?php echo htmlspecialchars($k); ?>]"
                                               min="<?php echo htmlspecialchars($o['min'] ?? 0); ?>"
                                               max="<?php echo htmlspecialchars($o['max'] ?? 100); ?>"
                                               value="<?php echo htmlspecialchars($val); ?>"
                                               oninput="this.nextElementSibling.innerText = this.value + 'PX'">
                                        <span class="active-val"><?php echo strtoupper(htmlspecialchars($val)); ?>PX</span>
                                    </div>
                                <?php elseif (isset($o['type']) && $o['type'] === 'select' && isset($o['options'])): ?>
                                    <select name="skin_opt[<?php echo htmlspecialchars($k); ?>]">
                                        <?php foreach ($o['options'] as $ov => $ol): ?>
                                            <?php $label = is_array($ol) ? ($ol['label'] ?? $ov) : $ol; ?>
                                            <option value="<?php echo htmlspecialchars($ov); ?>" <?php echo ($val == $ov) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <input type="text" name="skin_opt[<?php echo htmlspecialchars($k); ?>]" value="<?php echo htmlspecialchars($val); ?>">
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <div class="form-action-row">
            <button type="submit" name="save_global_appearance" class="master-update-btn">SAVE GLOBAL APPEARANCE SETTINGS</button>
        </div>
    </form>
</div>

<?php include 'core/admin-footer.php'; ?>
