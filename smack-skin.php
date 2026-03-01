<?php
/**
 * SNAPSMACK - Skin Admin.
 * Orchestrates the engine handshake by resolving skin dependencies against the global inventory.
 * Compiles dynamic CSS for public skins and manages engine-level feature toggles.
 * Git Version Official Alpha 0.5
 */

require_once 'core/auth.php';

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

// --- 4. SAVE HANDLER ---
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

        if ($meta['type'] === 'select' && isset($meta['options'][$val]['css'])) {
            $generated_public .= "{$meta['selector']} {$meta['options'][$val]['css']}\n";
        } elseif ($meta['property'] === 'font-family') {
            $generated_public .= "{$meta['selector']} { font-family: \"{$val}\", sans-serif; }\n";
        } elseif ($meta['type'] === 'range' || $meta['type'] === 'number') {
            $unit = (substr($meta['property'], 0, 2) === '--') ? '' : 'px';
            $generated_public .= "{$meta['selector']} { {$meta['property']}: {$val}{$unit}; }\n";
        } else {
            $generated_public .= "{$meta['selector']} { {$meta['property']}: {$val}; }\n";
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

<div class="main">
    <div class="header-row">
        <h2>SKIN ADMIN</h2>
    </div>

    <div class="box appearance-controller">
        <div class="skin-meta-wrap">
            <div class="theme-title-display">
                <?php echo strtoupper($manifest['name']); ?>
                <span class="theme-version-tag">v<?php echo $manifest['version']; ?></span>
            </div>
            <p class="skin-desc-text"><?php echo $manifest['description']; ?></p>
            <div class="dim">
                BY <?php echo strtoupper($manifest['author']); ?>
                <?php if (!empty($manifest['support'])): ?>
                    | <a href="mailto:<?php echo $manifest['support']; ?>" style="color: #00ff00; text-decoration: none;">SUPPORT</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="skin-selector-wrap">
            <form method="GET">
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
                        <p class="dim" style="margin-top: 6px; font-size: 0.75em;">COLOUR PALETTE FOR THE ACTIVE SKIN. GEOMETRY AND LAYOUT ARE UNCHANGED.</p>
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
</div>

<?php include 'core/admin-footer.php'; ?>