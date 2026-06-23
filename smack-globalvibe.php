<?php
/**
 * SNAPSMACK - Global appearance and theme settings
 *
 * Manages public and admin theme selection, discovery, and activation.
 * Per-theme customization and CSS overrides are handled in smack-skin.php.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';

// --- THEME DISCOVERY ---
// Scans available skins and admin themes, populating selector dropdowns.

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
$supports_wall    = !empty($manifest['features']['supports_wall']);
$wall_unavailable = $pimpotron_active || !$supports_wall;

// ── Footer slot helper ────────────────────────────────────────────────────────
function footer_slot_state($settings, $key, $default = 'on') {
    return $settings[$key] ?? $default;
}

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
    // Skip hidden system themes (e.g. pulsing alert themes) — not user-selectable.
    if (!empty($meta['hidden'])) continue;
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

    // B. Handle Global Settings (including Masthead, Image Engine, Footer, Wall)
    if (isset($_POST['settings']) && is_array($_POST['settings'])) {
        $v_settings = $_POST['settings'];

        // --- HEADER LOGO UPLOAD (Image Engine) ---
        if (!empty($_FILES['logo_upload']['name'])) {
            $target_dir = 'assets/img/';
            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
            $logo_allowed_ext  = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
            $logo_allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp'];
            $ext   = strtolower(pathinfo($_FILES['logo_upload']['name'], PATHINFO_EXTENSION));
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES['logo_upload']['tmp_name']);
            finfo_close($finfo);
            if (in_array($ext, $logo_allowed_ext) && in_array($mime, $logo_allowed_mime)) {
                if (move_uploaded_file($_FILES['logo_upload']['tmp_name'], $target_dir . 'logo.' . $ext)) {
                    $v_settings['header_logo_url'] = '/assets/img/logo.' . $ext;
                }
            }
        }

        // --- FAVICON UPLOAD (Image Engine) ---
        if (!empty($_FILES['favicon_upload']['name'])) {
            $target_dir = 'assets/img/';
            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
            $fav_allowed_ext  = ['ico', 'png', 'svg'];
            $fav_allowed_mime = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/svg+xml'];
            $fav_ext  = strtolower(pathinfo($_FILES['favicon_upload']['name'], PATHINFO_EXTENSION));
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $fav_mime = finfo_file($finfo, $_FILES['favicon_upload']['tmp_name']);
            finfo_close($finfo);
            if (in_array($fav_ext, $fav_allowed_ext) && in_array($fav_mime, $fav_allowed_mime)) {
                if (move_uploaded_file($_FILES['favicon_upload']['tmp_name'], $target_dir . 'favicon.' . $fav_ext)) {
                    $v_settings['favicon_url'] = '/assets/img/favicon.' . $fav_ext;
                }
            }
        }

        // --- MASTHEAD LOGO UPLOAD ---
        if (!empty($_FILES['site_logo_file']['name'])) {
            $upload_dir = 'assets/img/';
            if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }

            $mast_allowed_ext  = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
            $mast_allowed_mime = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp'];
            $mast_ext   = strtolower(pathinfo($_FILES['site_logo_file']['name'], PATHINFO_EXTENSION));
            $mast_finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mast_mime  = finfo_file($mast_finfo, $_FILES['site_logo_file']['tmp_name']);
            finfo_close($mast_finfo);

            if (in_array($mast_ext, $mast_allowed_ext) && in_array($mast_mime, $mast_allowed_mime)) {
                $file_name   = preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($_FILES['site_logo_file']['name']));
                $target_file = $upload_dir . time() . '_' . $file_name;
                if (move_uploaded_file($_FILES['site_logo_file']['tmp_name'], $target_file)) {
                    $v_settings['site_logo'] = $target_file;
                }
            }
        }

        foreach ($v_settings as $vk => $vv) {
            $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
            $stmt->execute([$vk, $vv, $vv]);
        }
    }

    // C. Handle Sticky Header Settings
    if (isset($_POST['sticky_header_section'])) {
        $sh_stmt = $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?"
        );

        $sh_enabled = isset($_POST['sticky_header_enabled']) ? '1' : '0';
        $sh_stmt->execute(['sticky_header_enabled', $sh_enabled, $sh_enabled]);

        $sh_opacity = max(0, min(100, (int)($_POST['sticky_header_opacity'] ?? 12)));
        $sh_stmt->execute(['sticky_header_opacity', $sh_opacity, $sh_opacity]);

        $sh_blur = max(0, min(30, (int)($_POST['sticky_header_blur'] ?? 14)));
        $sh_stmt->execute(['sticky_header_blur', $sh_blur, $sh_blur]);
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
                    | <a href="mailto:<?php echo htmlspecialchars($current_admin_meta['support']); ?>" class="support-link">SUPPORT</a>
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
                        <?php if (($settings['site_mode'] ?? 'photoblog') === 'carousel'): ?>
                        <div class="read-only-display">Plain Text — GramOfSmack mastheads are text-only</div>
                        <input type="hidden" name="settings[header_type]" value="text">
                        <?php else: ?>
                        <select name="settings[header_type]" onchange="document.getElementById('logo-upload-group').classList.toggle('d-none', this.value !== 'image');">
                            <option value="text" <?php echo (($settings['header_type'] ?? 'text') == 'text') ? 'selected' : ''; ?>>Plain Text (Public Skin Font)</option>
                            <option value="image" <?php echo (($settings['header_type'] ?? 'text') == 'image') ? 'selected' : ''; ?>>Custom Logo Image</option>
                        </select>
                        <?php endif; ?>
                    </div>

                    <div class="lens-input-wrapper<?php echo ((($settings['header_type'] ?? 'text') == 'image') && (($settings['site_mode'] ?? 'photoblog') !== 'carousel')) ? '' : ' d-none'; ?>" id="logo-upload-group">
                        <label>UPLOAD LOGO (PNG/SVG)</label>
                        <input type="file" name="site_logo_file" accept="image/*" class="file-input-raw">
                        <?php if (!empty($settings['site_logo'])): ?>
                            <div class="logo-preview-box">
                                <span class="dim">ACTIVE LOGO:</span><br>
                                <img src="<?php echo BASE_URL . htmlspecialchars($settings['site_logo']); ?>" class="logo-preview-img">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>


            <div class="box">
                <h3>STICKY HEADER</h3>
                <input type="hidden" name="sticky_header_section" value="1">
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <label>
                            <input type="checkbox" name="sticky_header_enabled" value="1" <?php echo ($settings['sticky_header_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                            ENABLE STICKY HEADER <span class="field-tip" data-tip="Header stays pinned to the top on scroll. Goes transparent while idle, snaps back opaque on hover. Skins with their own fixed headers are automatically excluded.">ⓘ</span>
                        </label>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>BACKGROUND OPACITY (TRANSPARENT STATE) <span class="field-tip" data-tip="0% = fully see-through, 100% = fully opaque.">ⓘ</span></label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="range" name="sticky_header_opacity" min="0" max="100" value="<?php echo htmlspecialchars($settings['sticky_header_opacity'] ?? '12'); ?>" style="flex: 1;" oninput="this.nextElementSibling.textContent = this.value + '%'">
                            <span style="min-width: 40px; font-family: monospace;"><?php echo htmlspecialchars($settings['sticky_header_opacity'] ?? '12'); ?>%</span>
                        </div>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>BACKDROP BLUR <span class="field-tip" data-tip="Glass-morphism blur. Higher = more frosted glass. 0 = no blur.">ⓘ</span></label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="range" name="sticky_header_blur" min="0" max="30" value="<?php echo htmlspecialchars($settings['sticky_header_blur'] ?? '14'); ?>" style="flex: 1;" oninput="this.nextElementSibling.textContent = this.value + 'px'">
                            <span style="min-width: 40px; font-family: monospace;"><?php echo htmlspecialchars($settings['sticky_header_blur'] ?? '14'); ?>px</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── FOOTER CONFIGURATION ──────────────────────────────────── -->
            <div class="box">
                <h3>FOOTER CONFIGURATION</h3>
                <p class="dim">Configure which elements appear in the public site footer. Each slot can be ON (default content), CUSTOM (your text), or OFF. RSS cannot be disabled.</p>

                <?php
                $footer_slots = [
                    ['key' => 'copyright', 'label' => 'COPYRIGHT',     'hint' => 'Default: &copy; {YEAR} {BLOG NAME}',                     'placeholder' => 'e.g. &copy; 2026 My Photo Blog',     'default' => 'on'],
                    ['key' => 'email',     'label' => 'EMAIL',         'hint' => 'Default: reverse-encoded site email (spam protection).', 'placeholder' => 'e.g. contact@example.com',            'default' => 'on'],
                    ['key' => 'theme',     'label' => 'CURRENT THEME', 'hint' => 'Default: shows active skin name.',                       'placeholder' => 'e.g. Designed by Example Studio',    'default' => 'off'],
                    ['key' => 'powered',   'label' => 'POWERED BY',    'hint' => 'Default: POWERED BY SNAPSMACK {VERSION}',                'placeholder' => 'e.g. Built with love and caffeine',   'default' => 'on'],
                ];
                ?>

                <div class="post-layout-grid">
                    <div class="post-col-left">
                        <?php foreach (array_slice($footer_slots, 0, 2) as $slot):
                            $state_key  = 'footer_slot_' . $slot['key'];
                            $custom_key = 'footer_slot_' . $slot['key'] . '_custom';
                            $state      = footer_slot_state($settings, $state_key, $slot['default']);
                            $custom_val = $settings[$custom_key] ?? '';
                        ?>
                        <div class="lens-input-wrapper">
                            <label><?php echo $slot['label']; ?> SLOT <span class="field-tip" data-tip="<?php echo htmlspecialchars(strip_tags($slot['hint'])); ?>">ⓘ</span></label>
                            <select name="settings[<?php echo $state_key; ?>]" class="footer-slot-toggle" data-target="<?php echo $custom_key; ?>">
                                <option value="on"     <?php echo ($state === 'on')     ? 'selected' : ''; ?>>ON (DEFAULT)</option>
                                <option value="custom" <?php echo ($state === 'custom') ? 'selected' : ''; ?>>CUSTOM TEXT</option>
                                <option value="off"    <?php echo ($state === 'off')    ? 'selected' : ''; ?>>OFF</option>
                            </select>
                            <div class="footer-custom-field<?php echo ($state === 'custom') ? '' : ' d-none'; ?>" id="field-<?php echo $custom_key; ?>">
                                <input type="text" name="settings[<?php echo $custom_key; ?>]"
                                       value="<?php echo htmlspecialchars($custom_val); ?>"
                                       placeholder="<?php echo $slot['placeholder']; ?>">
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <div class="lens-input-wrapper">
                            <label>RSS SLOT <span class="field-tip" data-tip="Links to your site RSS feed. Cannot be disabled.">ⓘ</span></label>
                            <div class="read-only-display">ALWAYS ON — CANNOT BE DISABLED</div>
                        </div>
                    </div>

                    <div class="post-col-right">
                        <?php foreach (array_slice($footer_slots, 2, 2) as $slot):
                            $state_key  = 'footer_slot_' . $slot['key'];
                            $custom_key = 'footer_slot_' . $slot['key'] . '_custom';
                            $state      = footer_slot_state($settings, $state_key, $slot['default']);
                            $custom_val = $settings[$custom_key] ?? '';
                        ?>
                        <div class="lens-input-wrapper">
                            <label><?php echo $slot['label']; ?> SLOT <span class="field-tip" data-tip="<?php echo htmlspecialchars(strip_tags($slot['hint'])); ?>">ⓘ</span></label>
                            <select name="settings[<?php echo $state_key; ?>]" class="footer-slot-toggle" data-target="<?php echo $custom_key; ?>">
                                <option value="on"     <?php echo ($state === 'on')     ? 'selected' : ''; ?>>ON (DEFAULT)</option>
                                <option value="custom" <?php echo ($state === 'custom') ? 'selected' : ''; ?>>CUSTOM TEXT</option>
                                <option value="off"    <?php echo ($state === 'off')    ? 'selected' : ''; ?>>OFF</option>
                            </select>
                            <div class="footer-custom-field<?php echo ($state === 'custom') ? '' : ' d-none'; ?>" id="field-<?php echo $custom_key; ?>">
                                <input type="text" name="settings[<?php echo $custom_key; ?>]"
                                       value="<?php echo htmlspecialchars($custom_val); ?>"
                                       placeholder="<?php echo $slot['placeholder']; ?>">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="lens-input-wrapper" style="margin-top:14px;">
                    <label>FOOTER TEXT CASE <span class="field-tip" data-tip="Render the entire footer bar in lowercase. Applies to every theme.">ⓘ</span></label>
                    <select name="settings[footer_lowercase]">
                        <option value="0" <?php echo (($settings['footer_lowercase'] ?? '0') === '1') ? '' : 'selected'; ?>>NORMAL (AS WRITTEN)</option>
                        <option value="1" <?php echo (($settings['footer_lowercase'] ?? '0') === '1') ? 'selected' : ''; ?>>all lowercase</option>
                    </select>
                </div>
            </div>

            <!-- ── IMAGE ENGINE ───────────────────────────────────────────── -->
            <div class="box">
                <h3>IMAGE ENGINE (SERVER-SIDE PROCESSING)</h3>
                <div class="post-layout-grid">
                    <div class="post-col-left">
                        <label>LANDSCAPE MAX WIDTH (PX)</label>
                        <input type="number" name="settings[max_width_landscape]" value="<?php echo htmlspecialchars($settings['max_width_landscape'] ?? 2500); ?>">

                        <label>PORTRAIT MAX HEIGHT (PX)</label>
                        <input type="number" name="settings[max_height_portrait]" value="<?php echo htmlspecialchars($settings['max_height_portrait'] ?? 1850); ?>">

                        <label>JPEG COMPRESSION (1-100)</label>
                        <input type="number" name="settings[jpeg_quality]" value="<?php echo htmlspecialchars($settings['jpeg_quality'] ?? 85); ?>">

                        <?php if (($settings['site_mode'] ?? 'photoblog') === 'photoblog'): /* EXIF tags are SMACKONEOUT-only — GramOfSmack & SmackTalk don't write EXIF (IG strips it) */ ?>
                        <label>EXIF ARTIST TAG <span class="field-tip" data-tip="Written into the Artist field of every JPEG upload. Leave blank to skip.">ⓘ</span></label>
                        <input type="text" name="settings[exif_artist]" value="<?php echo htmlspecialchars($settings['exif_artist'] ?? ''); ?>" placeholder="e.g. Sean McCormick">

                        <label>EXIF COPYRIGHT TAG <span class="field-tip" data-tip="Written into the Copyright field of every JPEG upload. Leave blank to skip.">ⓘ</span></label>
                        <input type="text" name="settings[exif_copyright]" value="<?php echo htmlspecialchars($settings['exif_copyright'] ?? ''); ?>" placeholder="e.g. © 2026 Sean McCormick. All rights reserved.">
                        <?php endif; ?>
                    </div>
                    <div class="post-col-right">
                        <label>HEADER LOGO ASSET</label>
                        <div class="file-upload-wrapper" onclick="document.getElementById('logo-input').click()">
                            <div class="file-custom-btn">UPLOAD</div>
                            <div class="file-name-display" id="logo-name">
                                <?php echo !empty($settings['header_logo_url']) ? "CURRENT" : "SELECT FILE"; ?>
                            </div>
                            <input type="file" name="logo_upload" id="logo-input" accept="image/*" class="file-input-hidden" onchange="document.getElementById('logo-name').innerText = this.files[0].name;">
                        </div>

                        <label>FAVICON</label>
                        <div class="file-upload-wrapper" onclick="document.getElementById('favicon-input').click()">
                            <div class="file-custom-btn">UPLOAD</div>
                            <div class="file-name-display" id="favicon-name">
                                <?php echo !empty($settings['favicon_url']) ? "CURRENT: " . basename($settings['favicon_url']) : "SELECT FILE (.ICO, .PNG, .SVG)"; ?>
                            </div>
                            <input type="file" name="favicon_upload" id="favicon-input" accept=".ico,.png,.svg,image/x-icon,image/png,image/svg+xml" class="file-input-hidden" onchange="document.getElementById('favicon-name').innerText = this.files[0].name;">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── FLOATING GALLERY ───────────────────────────────────────── -->
            <div class="box">
                <h3>FLOATING GALLERY</h3>
                <div class="dash-grid">

                    <div class="lens-input-wrapper">
                        <label>ENABLE FLOATING GALLERY <span class="field-tip" data-tip="Enables the gallery-wall.php page. Add the link to your nav via Menu Manager.">ⓘ</span></label>
                        <?php if ($wall_unavailable): ?>
                            <select disabled class="select-locked"><option>DISABLED BY SKIN</option></select>
                            <input type="hidden" name="settings[show_wall_link]" value="0">
                            <span class="dim"><?php echo $pimpotron_active ? 'PIMPOTRON IS ACTIVE &mdash; FLOATING GALLERY IS INCOMPATIBLE.' : 'ACTIVE SKIN DOES NOT SUPPORT THE FLOATING GALLERY.'; ?></span>
                        <?php else: ?>
                            <select name="settings[show_wall_link]">
                                <option value="1" <?php echo (($settings['show_wall_link'] ?? '1') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                                <option value="0" <?php echo (($settings['show_wall_link'] ?? '1') == '0') ? 'selected' : ''; ?>>DISABLED</option>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>ROWS</label>
                        <?php if ($wall_unavailable): ?>
                            <select disabled class="select-locked"><option>DISABLED BY SKIN</option></select>
                            <input type="hidden" name="settings[wall_rows]" value="2">
                        <?php else: ?>
                            <select name="settings[wall_rows]">
                                <?php foreach ([2, 3, 4, 5] as $r): ?>
                                    <option value="<?php echo $r; ?>" <?php echo (($settings['wall_rows'] ?? '2') == $r) ? 'selected' : ''; ?>><?php echo $r; ?> ROWS</option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>IMAGE GAP</label>
                        <?php if ($wall_unavailable): ?>
                            <input type="range" disabled min="4" max="120" value="24">
                            <input type="hidden" name="settings[wall_gap]" value="24">
                        <?php else: ?>
                            <div style="display:flex; align-items:center; gap:12px;">
                                <input type="range" name="settings[wall_gap]" min="4" max="120" step="2"
                                       value="<?php echo htmlspecialchars($settings['wall_gap'] ?? '24'); ?>"
                                       oninput="this.nextElementSibling.textContent = this.value + 'px'">
                                <span><?php echo htmlspecialchars($settings['wall_gap'] ?? '24'); ?>px</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>SCROLL FRICTION <span class="field-tip" data-tip="Higher = more coast. Lower = stops faster.">ⓘ</span></label>
                        <?php if ($wall_unavailable): ?>
                            <input type="range" disabled min="0.80" max="0.99" value="0.96">
                            <input type="hidden" name="settings[wall_friction]" value="0.96">
                        <?php else: ?>
                            <div style="display:flex; align-items:center; gap:12px;">
                                <input type="range" name="settings[wall_friction]" min="0.80" max="0.99" step="0.01"
                                       value="<?php echo htmlspecialchars($settings['wall_friction'] ?? '0.96'); ?>"
                                       oninput="this.nextElementSibling.textContent = this.value">
                                <span><?php echo htmlspecialchars($settings['wall_friction'] ?? '0.96'); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>DRAG WEIGHT <span class="field-tip" data-tip="How heavy the drag feels. Higher = more sluggish.">ⓘ</span></label>
                        <?php if ($wall_unavailable): ?>
                            <input type="range" disabled min="0.5" max="5.0" value="2.5">
                            <input type="hidden" name="settings[wall_dragweight]" value="2.5">
                        <?php else: ?>
                            <div style="display:flex; align-items:center; gap:12px;">
                                <input type="range" name="settings[wall_dragweight]" min="0.5" max="5.0" step="0.1"
                                       value="<?php echo htmlspecialchars($settings['wall_dragweight'] ?? '2.5'); ?>"
                                       oninput="this.nextElementSibling.textContent = this.value">
                                <span><?php echo htmlspecialchars($settings['wall_dragweight'] ?? '2.5'); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>REFLECTION <span class="field-tip" data-tip="Reflects the gallery off the floor.">ⓘ</span></label>
                        <?php if ($wall_unavailable): ?>
                            <select disabled class="select-locked"><option>DISABLED BY SKIN</option></select>
                            <input type="hidden" name="settings[wall_reflect]" value="0">
                        <?php else: ?>
                            <select name="settings[wall_reflect]">
                                <option value="1" <?php echo (($settings['wall_reflect'] ?? '0') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                                <option value="0" <?php echo (($settings['wall_reflect'] ?? '0') == '0') ? 'selected' : ''; ?>>DISABLED</option>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="lens-input-wrapper">
                        <label>WALL BACKGROUND COLOUR</label>
                        <?php if ($wall_unavailable): ?>
                            <input type="color" disabled value="#000000">
                        <?php else: ?>
                            <div class="color-picker-container">
                                <input type="color" name="settings[wall_theme]" value="<?php echo htmlspecialchars($settings['wall_theme'] ?? '#000000'); ?>">
                                <span class="hex-display"><?php echo strtoupper(htmlspecialchars($settings['wall_theme'] ?? '#000000')); ?></span>
                            </div>
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

<script>
document.querySelectorAll('.footer-slot-toggle').forEach(function(sel) {
    sel.addEventListener('change', function() {
        var field = document.getElementById('field-' + this.getAttribute('data-target'));
        if (field) field.style.display = (this.value === 'custom') ? '' : 'none';
    });
});
</script>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
