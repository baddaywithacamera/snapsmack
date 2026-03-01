<?php
/**
 * SNAPSMACK - Global appearance settings.
 * Manages admin themes and global branding assets (masthead, archive grid).
 * Per-skin options and CSS compilation live in smack-skin.php.
 * Git Version Official Alpha 0.5
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

            <div class="box">
                <h3>ARCHIVE GRID ARCHITECTURE</h3>
                <div class="dash-grid">

                    <?php
                    // --- ARCHIVE LAYOUT MODE (Gated by Skin Manifest) ---
                    $all_layouts = [
                        'square'    => 'Square Grid (1:1 Cropped)',
                        'cropped'   => 'Cropped Grid (Max 3:2 Aspect)',
                        'masonry'   => 'Justified (Flickr-Style Row Fill)',
                    ];
                    $supported_layouts = $manifest['features']['archive_layouts'] ?? ['square'];
                    $current_layout    = $settings['archive_layout'] ?? 'square';

                    // If current layout isn't supported by this skin, force to first supported
                    if (!in_array($current_layout, $supported_layouts)) {
                        $current_layout = $supported_layouts[0];
                    }

                    $layout_locked = (count($supported_layouts) === 1);
                    ?>

                    <div class="lens-input-wrapper">
                        <label>ARCHIVE DISPLAY MODE</label>

                        <?php if ($layout_locked): ?>
                            <select disabled style="opacity: 0.4; cursor: not-allowed;">
                                <option><?php echo strtoupper($all_layouts[$supported_layouts[0]]); ?></option>
                            </select>
                            <input type="hidden" name="settings[archive_layout]" value="<?php echo htmlspecialchars($supported_layouts[0]); ?>">
                            <p class="dim" style="margin-top: 6px; font-size: 0.75em;">
                                ACTIVE SKIN ONLY SUPPORTS THIS LAYOUT MODE.
                            </p>
                        <?php else: ?>
                            <select name="settings[archive_layout]">
                                <?php foreach ($supported_layouts as $layout_key): ?>
                                    <?php if (isset($all_layouts[$layout_key])): ?>
                                        <option value="<?php echo htmlspecialchars($layout_key); ?>" <?php echo ($current_layout === $layout_key) ? 'selected' : ''; ?>>
                                            <?php echo strtoupper($all_layouts[$layout_key]); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>THUMBNAIL SIZE</label>
                        <select name="settings[thumb_size]">
                            <?php
                            $size_steps = [
                                'xs' => 'XS — Extra Small',
                                's'  => 'S — Small',
                                'm'  => 'M — Medium',
                                'l'  => 'L — Large',
                                'xl' => 'XL — Extra Large',
                            ];
                            $current_size = $settings['thumb_size'] ?? 'm';
                            // Backwards compat: if old pixel value is stored, map to closest step
                            if (is_numeric($current_size)) {
                                $px = (int)$current_size;
                                if ($px <= 130) $current_size = 'xs';
                                elseif ($px <= 170) $current_size = 's';
                                elseif ($px <= 230) $current_size = 'm';
                                elseif ($px <= 290) $current_size = 'l';
                                else $current_size = 'xl';
                            }
                            foreach ($size_steps as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo ($current_size === $key) ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="lens-input-wrapper">
                        <label>BROWSE COLUMNS</label>
                        <input type="number" name="settings[browse_cols]" value="<?php echo htmlspecialchars($settings['browse_cols'] ?? 4); ?>">
                    </div>
                    <div class="lens-input-wrapper">
                        <label>WALL ENGINE LINK</label>

                        <?php
                        $supports_wall    = !empty($manifest['features']['supports_wall']);
                        $wall_unavailable = $pimpotron_active || !$supports_wall;
                        ?>

                        <?php if ($wall_unavailable): ?>
                            <select disabled style="opacity: 0.4; cursor: not-allowed;">
                                <option>DISABLED BY SKIN</option>
                            </select>
                            <input type="hidden" name="settings[show_wall_link]" value="0">
                            <p class="dim" style="margin-top: 6px; font-size: 0.75em;">
                                <?php if ($pimpotron_active): ?>
                                    PIMPOTRON IS ACTIVE &mdash; WALL ENGINE IS INCOMPATIBLE WITH THIS SKIN.
                                <?php else: ?>
                                    ACTIVE SKIN DOES NOT SUPPORT THE GALLERY WALL.
                                <?php endif; ?>
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


        </div>

        <div class="form-action-row">
            <button type="submit" name="save_global_appearance" class="master-update-btn">SAVE GLOBAL APPEARANCE SETTINGS</button>
        </div>
    </form>
</div>

<?php include 'core/admin-footer.php'; ?>