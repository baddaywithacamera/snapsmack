<?php
/**
 * SNAPSMACK - Skin Administration & Gallery
 * Alpha v0.7
 *
 * Two-tab interface for managing skins:
 *
 *   CUSTOMIZE — Configures active theme-specific options and CSS generation.
 *               Manages color schemes, fonts, and other skin-level customizations.
 *
 *   GALLERY   — Browse the remote skin registry, install new skins, update
 *               existing ones, or remove skins you no longer need. Skins with
 *               "development" status are visible but cannot be installed.
 */

require_once 'core/auth.php';
require_once 'core/skin-registry.php';

// --- SETTINGS BOOTSTRAP ---
// Must load BEFORE skin discovery so active_skin is available.
if (!isset($settings)) {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// --- TAB ROUTING ---
// Determine which tab is active: 'customize' (default) or 'gallery'
$active_tab = $_GET['tab'] ?? 'customize';
if (!in_array($active_tab, ['customize', 'gallery'])) $active_tab = 'customize';

// --- GALLERY ACTION HANDLERS ---
// Process install/remove requests before any output is sent.
$gallery_msg = '';
$gallery_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gallery_action'])) {

    // CSRF check: reuse session token
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $gallery_err = 'Invalid security token. Please reload the page.';
    } else {
        $action = $_POST['gallery_action'];
        $slug   = $_POST['skin_slug'] ?? '';
        $active = $settings['active_skin'] ?? '';

        if ($action === 'install' || $action === 'update') {
            $download_url = $_POST['download_url'] ?? '';
            $signature    = $_POST['signature'] ?? '';
            $public_key   = $settings['update_public_key'] ?? '';

            if (empty($download_url)) {
                $gallery_err = 'No download URL provided for this skin.';
            } else {
                $result = skin_registry_install($slug, $download_url, $signature, $public_key);
                if ($result['success']) {
                    skin_registry_clear_cache();
                    $gallery_msg = $result['message'];
                } else {
                    $gallery_err = $result['message'];
                }
            }
        } elseif ($action === 'remove') {
            $result = skin_registry_remove($slug, $active);
            if ($result['success']) {
                skin_registry_clear_cache();
                $gallery_msg = $result['message'];
            } else {
                $gallery_err = $result['message'];
            }
        }
    }

    // Redirect to avoid form resubmission
    if (!empty($gallery_msg)) {
        $_SESSION['gallery_flash'] = $gallery_msg;
        header("Location: smack-skin.php?tab=gallery");
        exit;
    }
}

// Pick up flash messages from redirects
if (isset($_SESSION['gallery_flash'])) {
    $gallery_msg = $_SESSION['gallery_flash'];
    unset($_SESSION['gallery_flash']);
}

// Generate CSRF token for gallery forms
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- 1. LOAD GLOBAL INVENTORY ---
// Pulls the master list of available scripts and engines.
$global_inventory = (function() { return include 'core/manifest-inventory.php'; })();

// --- 2. PUBLIC SKIN DISCOVERY ---
// Scans the skins directory for valid manifests to populate the selector.
$skin_dirs       = array_filter(glob('skins/*'), 'is_dir');
$available_skins = [];
foreach ($skin_dirs as $dir) {
    $slug = basename($dir);
    if (file_exists($dir . '/manifest.php')) {
        $temp = include $dir . '/manifest.php';
        $available_skins[$slug] = $temp['name'] ?? ucfirst($slug);
    }
}

$current_db_active = $settings['active_skin'] ?? array_key_first($available_skins);
$target_skin       = $_GET['s'] ?? $current_db_active;
if (!isset($available_skins[$target_skin])) $target_skin = array_key_first($available_skins);
$manifest = include "skins/{$target_skin}/manifest.php";

// --- 3. ENGINE RESOLUTION ---
// Identify which engines the selected skin requires based on its manifest.
$required_engines = $manifest['require_scripts'] ?? [];

$resolved_engines = [];
foreach ($required_engines as $engine_key) {
    if (isset($global_inventory['scripts'][$engine_key])) {
        $resolved_engines[$engine_key] = $global_inventory['scripts'][$engine_key];
    }
}

// --- 4. SAVE HANDLER (Customize tab) ---
if (isset($_POST['save_skin_settings'])) {

    // 4a. Persistence: Save individual skin and engine control values.
    if (isset($_POST['skin_opt'])) {
        foreach ($_POST['skin_opt'] as $s_key => $s_val) {
            $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?")
                ->execute([$s_key, $s_val, $s_val]);
        }
    }

    // 4a-ii. Variant persistence (skin palette, if manifest declares variants).
    if (isset($_POST['active_skin_variant'])) {
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('active_skin_variant', ?) ON DUPLICATE KEY UPDATE setting_val = ?")
            ->execute([$_POST['active_skin_variant'], $_POST['active_skin_variant']]);
    }

    $active_skin = $_POST['active_skin_target'] ?? $target_skin;
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('active_skin', ?) ON DUPLICATE KEY UPDATE setting_val = ?")
        ->execute([$active_skin, $active_skin]);

    // 4b. Refresh local settings cache for CSS compilation.
    $all_settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    // 4c. Public CSS Compilation.
    $generated_public = "/* SKIN_START */\n";

    // Map manifest options to CSS properties or custom payloads.
    foreach ($manifest['options'] as $key => $meta) {
        $val = ($all_settings[$key] ?? '') !== '' ? $all_settings[$key] : $meta['default'];
        $prop = $meta['property'] ?? '';

        // Skip data-attributes — read by JS engines, not CSS
        if (strpos($prop, 'data-') === 0) {
            continue;
        }

        // Custom properties (custom-framing, custom-cols) — only emit the css block
        if (strpos($prop, 'custom-') === 0) {
            if ($meta['type'] === 'select' && isset($meta['options'][$val]['css'])) {
                $generated_public .= "{$meta['selector']} {$meta['options'][$val]['css']}\n";
            }
            continue;
        }

        if ($meta['type'] === 'select' && isset($meta['options'][$val]['css'])) {
            $generated_public .= "{$meta['selector']} {$meta['options'][$val]['css']}\n";
        } elseif ($prop === 'font-family') {
            // Smart fallback: monospace stack for DotMatrix/mono fonts, sans-serif for others
            $fallback = 'sans-serif';
            if (stripos($val, 'DotMatrix') !== false || stripos($val, 'Mono') !== false
                || stripos($val, 'Courier') !== false || stripos($val, 'Tiny5') !== false
                || stripos($val, 'Anonymous') !== false) {
                $fallback = "'Courier New', monospace";
            }
            $generated_public .= "{$meta['selector']} { font-family: \"{$val}\", {$fallback}; }\n";
        } elseif ($meta['type'] === 'range' || $meta['type'] === 'number') {
            $unit = (substr($prop, 0, 2) === '--') ? '' : 'px';
            // Handle comma-separated properties (e.g. 'padding-left, padding-right')
            $props = array_map('trim', explode(',', $prop));
            $declarations = [];
            foreach ($props as $p) {
                $declarations[] = "{$p}: {$val}{$unit}";
            }
            $generated_public .= "{$meta['selector']} { " . implode('; ', $declarations) . "; }\n";
        } else {
            // Same split for generic properties
            $props = array_map('trim', explode(',', $prop));
            $declarations = [];
            foreach ($props as $p) {
                $declarations[] = "{$p}: {$val}";
            }
            $generated_public .= "{$meta['selector']} { " . implode('; ', $declarations) . "; }\n";
        }
    }

    // Process Engine-specific CSS variables (e.g. Glitch Engine).
    foreach ($resolved_engines as $engine_key => $engine) {
        if (!empty($engine['controls'])) {
            $generated_public .= "/* ENGINE: {$engine_key} */\n";
            foreach ($engine['controls'] as $ctrl_key => $ctrl) {
                $val  = ($all_settings[$ctrl_key] ?? '') !== '' ? $all_settings[$ctrl_key] : ($ctrl['default'] ?? '');

                if ($engine_key === 'smack-glitch') {
                    if ($ctrl_key === 'glitch_enabled') {
                        $generated_public .= ".post-image { --glitch-enabled: {$val}; }\n";
                    } elseif ($ctrl_key === 'glitch_intensity') {
                        $generated_public .= ".post-image { --glitch-intensity: {$val}px; }\n";
                    } elseif ($ctrl_key === 'glitch_speed') {
                        $generated_public .= ".post-image { --glitch-ms: {$val}ms; }\n";
                    }
                }
            }
        }
    }

    $generated_public .= "/* SKIN_END */";

    // Surgical Update: Replace only the SKIN block within the public CSS blob.
    $existing_blob  = $all_settings['custom_css_public'] ?? '';
    $skin_pattern   = '/\/\* SKIN_START \*\/.*?\/\* SKIN_END \*\//s';
    $final_public   = preg_match($skin_pattern, $existing_blob)
        ? preg_replace($skin_pattern, $generated_public, $existing_blob)
        : $generated_public . "\n\n" . trim($existing_blob);

    $pdo->prepare("REPLACE INTO snap_settings (setting_key, setting_val) VALUES ('custom_css_public', ?)")
        ->execute([$final_public]);

    // 4d. ENGINE HANDSHAKE: Build the script injection block.
    $v         = time();
    $injection = '';

    // 4d-i. Google Font CDN links for any active font-family selections
    $google_catalog = $global_inventory['fonts'] ?? [];
    if (!empty($google_catalog)) {
        $google_needed = [];
        foreach ($manifest['options'] as $opt_key => $opt_meta) {
            if (($opt_meta['property'] ?? '') === 'font-family') {
                $active_val = ($all_settings[$opt_key] ?? '') !== '' ? $all_settings[$opt_key] : ($opt_meta['default'] ?? '');
                if ($active_val !== '' && isset($google_catalog[$active_val])) {
                    $google_needed[$active_val] = true;
                }
            }
        }
        if (!empty($google_needed)) {
            $families = [];
            foreach (array_keys($google_needed) as $fam) {
                $families[] = str_replace(' ', '+', $fam) . ':wght@400;700';
            }
            $gf_url = 'https://fonts.googleapis.com/css2?' . implode('&', array_map(fn($f) => "family={$f}", $families)) . '&display=swap';
            $injection .= '<link rel="stylesheet" href="' . htmlspecialchars($gf_url) . '">' . "\n";
        }
    }

    // 4d-ii. Engine script and CSS injection
    foreach ($resolved_engines as $engine_key => $engine) {
        if (!empty($engine['css'])) {
            $injection .= '<link rel="stylesheet" href="' . BASE_URL . $engine['css'] . '?v=' . $v . '">' . "\n";
        }
        if (!empty($engine['path'])) {
            $injection .= '<script src="' . BASE_URL . $engine['path'] . '?v=' . $v . '"></script>' . "\n";
        }
    }

    $pdo->prepare("REPLACE INTO snap_settings (setting_key, setting_val) VALUES ('footer_injection_scripts', ?)")
        ->execute([$injection]);

    // 4e. Admin Styling: Injected from manifest if defined.
    if (isset($manifest['admin_styling'])) {
        $generated_admin = "/* SKIN_START */\n" . trim($manifest['admin_styling']) . "\n/* SKIN_END */";
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('custom_css_admin', ?) ON DUPLICATE KEY UPDATE setting_val = ?")
            ->execute([$generated_admin, $generated_admin]);
    }

    header("Location: smack-skin.php?s={$active_skin}&msg=updated");
    exit;
}

$page_title = "SKIN ADMIN";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<style>
/* --- SKIN PAGE: TAB NAVIGATION --- */
.skin-tabs {
    display: flex;
    gap: 0;
    margin-bottom: 24px;
    border-bottom: 1px solid #333;
}
.skin-tab {
    padding: 10px 24px;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: #666;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    transition: color 0.2s, border-color 0.2s;
}
.skin-tab:hover { color: #aaa; }
.skin-tab.active {
    opacity: 1;
    font-weight: 900;
    border-bottom-color: currentColor;
}

/* --- GALLERY: Skin cards grid --- */
.gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.skin-card {
    background: #1a1a1a;
    border: 1px solid #333;
    border-radius: 4px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.skin-card-screenshot {
    width: 100%;
    height: 180px;
    background: #111;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
}
.skin-card-screenshot img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.skin-card-screenshot .no-preview {
    color: #444;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 2px;
}
.skin-card-body {
    padding: 16px;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.skin-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}
.skin-card-name {
    font-size: 0.95rem;
    font-weight: 700;
    color: #eee;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.skin-card-version {
    font-size: 0.7rem;
    color: #666;
    font-family: monospace;
}
.skin-card-desc {
    font-size: 0.8rem;
    color: #888;
    line-height: 1.5;
    margin-bottom: 12px;
    flex: 1;
}
.skin-card-meta {
    font-size: 0.7rem;
    color: #555;
    margin-bottom: 12px;
}
.skin-card-features {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.feature-tag {
    font-size: 0.65rem;
    padding: 2px 8px;
    border-radius: 3px;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 600;
}
.feature-tag.wall    { background: #1a2a1a; color: #6f6; border: 1px solid #363; }
.feature-tag.no-wall { background: #2a1a1a; color: #f66; border: 1px solid #633; }
.feature-tag.layout  { background: #1a1a2a; color: #99f; border: 1px solid #336; }

/* --- STATUS BADGES --- */
.status-badge {
    display: inline-block;
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 3px;
}
.status-badge.stable     { background: #1a3a1a; color: #6f6; border: 1px solid #3a3; }
.status-badge.beta       { background: #3a3a1a; color: #ff6; border: 1px solid #993; }
.status-badge.development { background: #3a1a1a; color: #f66; border: 1px solid #933; }

/* Installed badge */
.installed-badge {
    display: inline-block;
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 3px;
    background: #1a2a3a;
    color: #6cf;
    border: 1px solid #369;
}
.active-badge {
    background: #1a3a1a;
    color: #6f6;
    border: 1px solid #3a3;
}

/* --- GALLERY BUTTONS --- */
.skin-card-actions {
    display: flex;
    gap: 8px;
    margin-top: auto;
}
.skin-card-actions form { margin: 0; }
.gallery-btn {
    display: inline-block;
    padding: 6px 14px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    transition: opacity 0.2s;
}
.gallery-btn:hover { opacity: 0.8; }
.gallery-btn.install  { background: #666; color: #fff; }
.gallery-btn.update   { background: #ffcc00; color: #000; }
.gallery-btn.remove   { background: #333;    color: #f66; border: 1px solid #633; }
.gallery-btn.disabled {
    background: #222;
    color: #555;
    cursor: not-allowed;
    border: 1px solid #333;
}

/* --- GALLERY ERROR/SUCCESS --- */
.gallery-alert {
    padding: 10px 16px;
    margin-bottom: 16px;
    border-radius: 3px;
    font-size: 0.8rem;
}
.gallery-alert.success { background: #1a3a1a; color: #6f6; border: 1px solid #3a3; }
.gallery-alert.error   { background: #3a1a1a; color: #f66; border: 1px solid #933; }

/* --- REGISTRY INFO --- */
.registry-info {
    font-size: 0.75rem;
    color: #555;
    margin-bottom: 16px;
}
.registry-info a { text-decoration: underline; }
</style>

<div class="main">
    <div class="header-row">
        <h2>SKIN ADMIN</h2>
    </div>

    <!-- ============================================================
         TAB NAVIGATION
         ============================================================ -->
    <div class="skin-tabs">
        <a href="smack-skin.php?tab=customize&s=<?php echo urlencode($target_skin); ?>"
           class="skin-tab <?php echo ($active_tab === 'customize') ? 'active' : ''; ?>">
            CUSTOMIZE
        </a>
        <a href="smack-skin.php?tab=gallery"
           class="skin-tab <?php echo ($active_tab === 'gallery') ? 'active' : ''; ?>">
            GALLERY
        </a>
    </div>

<?php if ($active_tab === 'customize'): ?>
    <!-- ============================================================
         TAB 1: CUSTOMIZE (existing skin customization UI)
         ============================================================ -->

    <div class="box appearance-controller">
        <div class="skin-meta-wrap">
            <div class="theme-title-display">
                <?php echo strtoupper(htmlspecialchars($manifest['name'])); ?>
                <span class="theme-version-tag">v<?php echo htmlspecialchars($manifest['version']); ?></span>
                <?php if (!empty($manifest['status'])): ?>
                    <span class="status-badge <?php echo htmlspecialchars($manifest['status']); ?>">
                        <?php echo strtoupper(htmlspecialchars($manifest['status'])); ?>
                    </span>
                <?php endif; ?>
            </div>
            <p class="skin-desc-text"><?php echo htmlspecialchars($manifest['description']); ?></p>
            <div class="dim">
                BY <?php echo strtoupper(htmlspecialchars($manifest['author'])); ?>
                <?php if (!empty($manifest['support'])): ?>
                    | <a href="mailto:<?php echo htmlspecialchars($manifest['support']); ?>" class="support-link">SUPPORT</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="skin-selector-wrap">
            <form method="GET">
                <input type="hidden" name="tab" value="customize">
                <label>SKIN SELECTOR</label>
                <select name="s" onchange="this.form.submit()">
                    <?php foreach ($available_skins as $slug => $name): ?>
                        <option value="<?php echo $slug; ?>" <?php echo ($target_skin == $slug) ? 'selected' : ''; ?>>
                            <?php echo $name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">> SKIN ARCHITECTURE CALIBRATED</div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="active_skin_target" value="<?php echo $target_skin; ?>">

        <div id="smack-skin-config-wrap">

            <?php
            // --- COLOUR VARIANT: Only if manifest declares variants ---
            if (!empty($manifest['variants'])):
                $current_variant = $settings['active_skin_variant'] ?? ($manifest['default_variant'] ?? array_key_first($manifest['variants']));
            ?>
            <div class="box">
                <h3>COLOUR VARIANT</h3>
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <label>SKIN PALETTE</label>
                        <select name="active_skin_variant">
                            <?php foreach ($manifest['variants'] as $v_key => $v_label): ?>
                                <option value="<?php echo htmlspecialchars($v_key); ?>" <?php echo ($current_variant === $v_key) ? 'selected' : ''; ?>>
                                    <?php echo strtoupper(htmlspecialchars($v_label)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="dim field-hint">COLOUR PALETTE FOR THE ACTIVE SKIN. GEOMETRY AND LAYOUT ARE UNCHANGED.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php
            // --- SKIN OPTIONS: Grouped by manifest sections ---
            $sec = [];
            foreach ($manifest['options'] as $k => $o) {
                $sec[$o['section'] ?? 'GENERAL'][] = ['key' => $k, 'meta' => $o];
            }
            foreach ($sec as $title => $opts):
            ?>
                <div class="box">
                    <h3><?php echo $title; ?></h3>
                    <div class="dash-grid">
                    <?php foreach ($opts as $item):
                        $k   = $item['key'];
                        $o   = $item['meta'];
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
                                        oninput="this.nextElementSibling.innerText = this.value + 'PX'">
                                    <span class="active-val"><?php echo strtoupper(htmlspecialchars($val)); ?>PX</span>
                                </div>
                            <?php elseif ($o['type'] === 'select'): ?>
                                <select name="skin_opt[<?php echo $k; ?>]">
                                    <?php foreach ($o['options'] as $sv => $sl): ?>
                                        <option value="<?php echo $sv; ?>" <?php echo ($val == $sv) ? 'selected' : ''; ?>>
                                            <?php echo is_array($sl) ? $sl['label'] : $sl; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" name="skin_opt[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($val); ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php
            // --- ENGINE CONTROLS: Global protocols surfaced by required engines ---
            $engine_controls = [];
            foreach ($resolved_engines as $engine_key => $engine) {
                if (!empty($engine['has_settings']) && !empty($engine['controls'])) {
                    foreach ($engine['controls'] as $ctrl_key => $ctrl_meta) {
                        $engine_controls[$ctrl_key] = array_merge($ctrl_meta, ['_engine' => $engine_key]);
                    }
                }
            }

            if (!empty($engine_controls)):
            ?>
            <div class="box">
                <h3>SYSTEM ENGINE PROTOCOLS</h3>
                <div class="dash-grid">
                <?php foreach ($engine_controls as $k => $o):
                    $val = ($settings[$k] ?? '') !== '' ? $settings[$k] : ($o['default'] ?? '');
                ?>
                    <div class="lens-input-wrapper">
                        <label><?php echo strtoupper(htmlspecialchars($o['label'] ?? $k)); ?></label>
                        <?php if (($o['type'] ?? '') === 'range'):
                            $step = $o['step'] ?? '1';
                        ?>
                            <div class="range-wrapper">
                                <input type="range" name="skin_opt[<?php echo htmlspecialchars($k); ?>]"
                                    min="<?php echo htmlspecialchars($o['min'] ?? 0); ?>"
                                    max="<?php echo htmlspecialchars($o['max'] ?? 100); ?>"
                                    step="<?php echo htmlspecialchars($step); ?>"
                                    value="<?php echo htmlspecialchars($val); ?>"
                                    oninput="this.nextElementSibling.innerText = this.value">
                                <span class="active-val"><?php echo htmlspecialchars($val); ?></span>
                            </div>
                        <?php elseif (($o['type'] ?? '') === 'select'): ?>
                            <select name="skin_opt[<?php echo htmlspecialchars($k); ?>]">
                                <?php foreach (($o['options'] ?? []) as $opt_val => $opt_label): ?>
                                    <option value="<?php echo htmlspecialchars($opt_val); ?>" <?php echo ($val == $opt_val) ? 'selected' : ''; ?>>
                                        <?php echo strtoupper(htmlspecialchars($opt_label)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" name="skin_opt[<?php echo htmlspecialchars($k); ?>]" value="<?php echo htmlspecialchars($val); ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <div class="form-action-row">
            <button type="submit" name="save_skin_settings" class="master-update-btn">SAVE SKIN SPECIFIC CALIBRATION</button>
        </div>
    </form>

<?php elseif ($active_tab === 'gallery'): ?>
    <!-- ============================================================
         TAB 2: GALLERY (browse, install, update, remove skins)
         ============================================================ -->

    <?php if (!empty($gallery_msg)): ?>
        <div class="gallery-alert success">> <?php echo htmlspecialchars($gallery_msg); ?></div>
    <?php endif; ?>
    <?php if (!empty($gallery_err)): ?>
        <div class="gallery-alert error">> <?php echo htmlspecialchars($gallery_err); ?></div>
    <?php endif; ?>

    <?php
    // Fetch registry and local skin data
    $registry_url = $settings['skin_registry_url'] ?? SKIN_REGISTRY_DEFAULT_URL;
    $registry     = skin_registry_fetch($registry_url);
    $local_skins  = skin_registry_local();

    if (isset($registry['error'])):
    ?>
        <div class="box">
            <h3>SKIN REGISTRY</h3>
            <div class="gallery-alert error">> <?php echo htmlspecialchars($registry['error']); ?></div>
            <p class="dim mt-10">
                REGISTRY URL: <span class="dim"><?php echo htmlspecialchars($registry_url); ?></span>
            </p>

            <!-- Fallback: show locally installed skins only -->
            <h3 class="mt-24">INSTALLED SKINS</h3>
            <div class="gallery-grid">
                <?php foreach ($local_skins as $slug => $skin): ?>
                    <div class="skin-card">
                        <div class="skin-card-screenshot">
                            <?php if (file_exists("skins/{$slug}/screenshot.png")): ?>
                                <img src="skins/<?php echo htmlspecialchars($slug); ?>/screenshot.png" alt="<?php echo htmlspecialchars($skin['name']); ?>">
                            <?php else: ?>
                                <span class="no-preview">NO PREVIEW</span>
                            <?php endif; ?>
                        </div>
                        <div class="skin-card-body">
                            <div class="skin-card-header">
                                <span class="skin-card-name"><?php echo htmlspecialchars($skin['name']); ?></span>
                                <span class="skin-card-version">v<?php echo htmlspecialchars($skin['version']); ?></span>
                            </div>
                            <div class="mb-8">
                                <span class="installed-badge <?php echo ($current_db_active === $slug) ? 'active-badge' : ''; ?>">
                                    <?php echo ($current_db_active === $slug) ? 'ACTIVE' : 'INSTALLED'; ?>
                                </span>
                                <span class="status-badge <?php echo htmlspecialchars($skin['status']); ?>">
                                    <?php echo strtoupper($skin['status']); ?>
                                </span>
                            </div>
                            <div class="skin-card-desc"><?php echo htmlspecialchars($skin['description']); ?></div>
                            <div class="skin-card-meta">BY <?php echo strtoupper(htmlspecialchars($skin['author'])); ?></div>
                            <?php if ($current_db_active !== $slug): ?>
                                <div class="skin-card-actions">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="gallery_action" value="remove">
                                        <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                        <button type="submit" class="gallery-btn remove"
                                                onclick="return confirm('Remove skin \'<?php echo htmlspecialchars($skin['name']); ?>\'? This deletes the skin directory.');">
                                            REMOVE
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php else: ?>
        <?php
        // Registry loaded successfully — compare with local
        $gallery_skins = skin_registry_compare($registry, $local_skins);
        ?>
        <div class="registry-info">
            REGISTRY: <?php echo htmlspecialchars($registry_url); ?>
            &nbsp;|&nbsp;
            <?php echo count($gallery_skins); ?> SKIN<?php echo count($gallery_skins) !== 1 ? 'S' : ''; ?> AVAILABLE
            &nbsp;|&nbsp;
            <a href="smack-skin.php?tab=gallery&refresh=1">REFRESH</a>
        </div>

        <?php
        // Handle manual cache refresh
        if (isset($_GET['refresh'])) {
            skin_registry_clear_cache();
            header("Location: smack-skin.php?tab=gallery");
            exit;
        }
        ?>

        <div class="gallery-grid">
            <?php foreach ($gallery_skins as $slug => $skin): ?>
                <div class="skin-card">
                    <!-- Screenshot -->
                    <div class="skin-card-screenshot">
                        <?php if (!empty($skin['screenshot'])): ?>
                            <img src="<?php echo htmlspecialchars($skin['screenshot']); ?>"
                                 alt="<?php echo htmlspecialchars($skin['name']); ?>"
                                 loading="lazy"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                            <span class="no-preview d-none">PREVIEW UNAVAILABLE</span>
                        <?php elseif (file_exists("skins/{$slug}/screenshot.png")): ?>
                            <img src="skins/<?php echo htmlspecialchars($slug); ?>/screenshot.png"
                                 alt="<?php echo htmlspecialchars($skin['name']); ?>">
                        <?php else: ?>
                            <span class="no-preview">NO PREVIEW</span>
                        <?php endif; ?>
                    </div>

                    <!-- Body -->
                    <div class="skin-card-body">
                        <div class="skin-card-header">
                            <span class="skin-card-name"><?php echo htmlspecialchars($skin['name'] ?? $slug); ?></span>
                            <span class="skin-card-version">v<?php echo htmlspecialchars($skin['version'] ?? '?'); ?></span>
                        </div>

                        <!-- Status + Installed badges -->
                        <div class="skin-badge-row">
                            <span class="status-badge <?php echo htmlspecialchars($skin['status'] ?? 'stable'); ?>">
                                <?php echo strtoupper($skin['status'] ?? 'STABLE'); ?>
                            </span>
                            <?php if ($skin['installed']): ?>
                                <span class="installed-badge <?php echo ($current_db_active === $slug) ? 'active-badge' : ''; ?>">
                                    <?php echo ($current_db_active === $slug) ? 'ACTIVE' : 'INSTALLED'; ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($skin['update_available']): ?>
                                <span class="status-badge beta">UPDATE: v<?php echo htmlspecialchars($skin['version']); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="skin-card-desc"><?php echo htmlspecialchars($skin['description'] ?? ''); ?></div>

                        <div class="skin-card-meta">
                            BY <?php echo strtoupper(htmlspecialchars($skin['author'] ?? 'Unknown')); ?>
                            <?php if (!empty($skin['download_size'])): ?>
                                &nbsp;|&nbsp; <?php echo round($skin['download_size'] / 1024); ?>KB
                            <?php endif; ?>
                        </div>

                        <!-- Feature tags -->
                        <?php if (!empty($skin['features'])): ?>
                            <div class="skin-card-features">
                                <?php if (!empty($skin['features']['supports_wall'])): ?>
                                    <span class="feature-tag wall">WALL</span>
                                <?php else: ?>
                                    <span class="feature-tag no-wall">NO WALL</span>
                                <?php endif; ?>
                                <?php foreach (($skin['features']['archive_layouts'] ?? []) as $layout): ?>
                                    <span class="feature-tag layout"><?php echo strtoupper(htmlspecialchars($layout)); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Action buttons -->
                        <div class="skin-card-actions">
                            <?php if ($skin['status'] === 'development'): ?>
                                <!-- Development skins: visible but not installable -->
                                <button class="gallery-btn disabled" disabled title="This skin is under development and cannot be installed yet.">
                                    UNDER DEVELOPMENT
                                </button>

                            <?php elseif ($skin['update_available']): ?>
                                <!-- Update available -->
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="gallery_action" value="update">
                                    <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                    <input type="hidden" name="download_url" value="<?php echo htmlspecialchars($skin['download_url'] ?? ''); ?>">
                                    <input type="hidden" name="signature" value="<?php echo htmlspecialchars($skin['signature'] ?? ''); ?>">
                                    <button type="submit" class="gallery-btn update"
                                            onclick="return confirm('Update \'<?php echo htmlspecialchars($skin['name']); ?>\' from v<?php echo htmlspecialchars($skin['local_version']); ?> to v<?php echo htmlspecialchars($skin['version']); ?>?');">
                                        UPDATE TO v<?php echo strtoupper(htmlspecialchars($skin['version'])); ?>
                                    </button>
                                </form>

                            <?php elseif (!$skin['installed']): ?>
                                <!-- Not installed: offer install -->
                                <?php if (!empty($skin['download_url'])): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="gallery_action" value="install">
                                        <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                        <input type="hidden" name="download_url" value="<?php echo htmlspecialchars($skin['download_url']); ?>">
                                        <input type="hidden" name="signature" value="<?php echo htmlspecialchars($skin['signature'] ?? ''); ?>">
                                        <button type="submit" class="gallery-btn install">INSTALL</button>
                                    </form>
                                <?php else: ?>
                                    <button class="gallery-btn disabled" disabled>NO DOWNLOAD</button>
                                <?php endif; ?>

                            <?php else: ?>
                                <!-- Installed and up to date -->
                                <span class="skin-status-current">
                                    UP TO DATE
                                </span>
                            <?php endif; ?>

                            <?php if ($skin['installed'] && $current_db_active !== $slug): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="gallery_action" value="remove">
                                    <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                    <button type="submit" class="gallery-btn remove"
                                            onclick="return confirm('Remove skin \'<?php echo htmlspecialchars($skin['name']); ?>\'? This deletes the entire skin directory.');">
                                        REMOVE
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php
            // Show locally installed skins that are NOT in the registry
            // (custom/local-only skins)
            foreach ($local_skins as $slug => $skin):
                if (isset($gallery_skins[$slug])) continue;
            ?>
                <div class="skin-card">
                    <div class="skin-card-screenshot">
                        <?php if (file_exists("skins/{$slug}/screenshot.png")): ?>
                            <img src="skins/<?php echo htmlspecialchars($slug); ?>/screenshot.png"
                                 alt="<?php echo htmlspecialchars($skin['name']); ?>">
                        <?php else: ?>
                            <span class="no-preview">NO PREVIEW</span>
                        <?php endif; ?>
                    </div>
                    <div class="skin-card-body">
                        <div class="skin-card-header">
                            <span class="skin-card-name"><?php echo htmlspecialchars($skin['name']); ?></span>
                            <span class="skin-card-version">v<?php echo htmlspecialchars($skin['version']); ?></span>
                        </div>
                        <div class="skin-badge-row">
                            <span class="status-badge <?php echo htmlspecialchars($skin['status']); ?>">
                                <?php echo strtoupper($skin['status']); ?>
                            </span>
                            <span class="installed-badge <?php echo ($current_db_active === $slug) ? 'active-badge' : ''; ?>">
                                <?php echo ($current_db_active === $slug) ? 'ACTIVE' : 'INSTALLED'; ?>
                            </span>
                            <span class="badge-local-only">LOCAL ONLY</span>
                        </div>
                        <div class="skin-card-desc"><?php echo htmlspecialchars($skin['description']); ?></div>
                        <div class="skin-card-meta">BY <?php echo strtoupper(htmlspecialchars($skin['author'])); ?></div>
                        <?php if ($current_db_active !== $slug): ?>
                            <div class="skin-card-actions">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="gallery_action" value="remove">
                                    <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                    <button type="submit" class="gallery-btn remove"
                                            onclick="return confirm('Remove skin \'<?php echo htmlspecialchars($skin['name']); ?>\'?');">
                                        REMOVE
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php endif; ?>
</div>

<?php include 'core/admin-footer.php'; ?>
