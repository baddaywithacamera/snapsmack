<?php
/**
 * SNAPSMACK - Archive Appearance
 *
 * Controls all visual and structural settings for the public archive page.
 * Grid layout mode and visitor switching options are set here by the site
 * owner — skins no longer gate which modes are available.
 *
 * Moved here from smack-globalvibe.php in v0.7.9f.
 * Archive mode ownership moved from skin manifests to site owner in v0.7.9g.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth.php';

// --- MANIFEST ---
$active_skin = $settings['active_skin'] ?? '';
$manifest    = [];
if ($active_skin && file_exists(__DIR__ . "/skins/{$active_skin}/manifest.php")) {
    $manifest = include __DIR__ . "/skins/{$active_skin}/manifest.php";
}
// Skin manifest options flagged admin_page=>'archive' are rendered here instead of smack-skin.php.
$archive_manifest_opts = [];
foreach ($manifest['options'] ?? [] as $k => $o) {
    if (($o['admin_page'] ?? 'skin') === 'archive') {
        $archive_manifest_opts[$k] = $o;
    }
}
// Engine controls (from manifest-inventory.php) flagged admin_page=>'archive' are also rendered here.
$archive_engine_opts = [];
if (!empty($manifest['require_scripts'])) {
    $global_inventory = (function() { return include __DIR__ . '/core/manifest-inventory.php'; })();
    foreach ($manifest['require_scripts'] as $_ekey) {
        $_edata = $global_inventory['scripts'][$_ekey] ?? [];
        if (!empty($_edata['has_settings']) && !empty($_edata['controls'])
                && ($_edata['admin_page'] ?? 'skin') === 'archive') {
            $archive_engine_opts[$_ekey] = $_edata;
        }
    }
}

// --- POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_archive_appearance'])) {
    if (isset($_POST['settings']) && is_array($_POST['settings'])) {
        // archive_layouts_available arrives as an array of checkboxes; serialise it.
        if (isset($_POST['settings']['archive_layouts_available']) && is_array($_POST['settings']['archive_layouts_available'])) {
            $avail = array_intersect($_POST['settings']['archive_layouts_available'], ['square', 'cropped', 'masonry', 'croppedwithcalendar']);
            // Always include the default layout in the available set.
            $default_layout = $_POST['settings']['archive_layout'] ?? 'square';
            if (!in_array($default_layout, $avail)) $avail[] = $default_layout;
            $_POST['settings']['archive_layouts_available'] = implode(',', $avail);
        }

        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        foreach ($_POST['settings'] as $k => $v) {
            $stmt->execute([$k, $v, $v]);
        }
    }
    header("Location: smack-appearance-archive.php?msg=SAVED");
    exit;
}

$page_title = "Archive Appearance";
include 'core/admin-header.php';
include 'core/sidebar.php';

// All layout modes. croppedwithcalendar offered if the skin declares it in features.archive_layouts.
// Using features[] rather than require_scripts[] so the check is reliable even when the manifest
// loading path differs from smack-skin.php.
$skin_has_calendar = in_array('croppedwithcalendar', $manifest['features']['archive_layouts'] ?? [])
                  || in_array('smack-calendar', $manifest['require_scripts'] ?? []);
$all_layouts = [
    'square'  => 'Square Grid (1:1 Cropped)',
    'cropped' => 'Cropped Grid (Natural Aspect)',
    'masonry' => 'Masonry / Justified (Flickr-Style)',
];
if ($skin_has_calendar) {
    $all_layouts['croppedwithcalendar'] = 'Cropped + Calendar (Cal toggle)';
}

$current_layout = $settings['archive_layout'] ?? 'square';
if (!isset($all_layouts[$current_layout])) $current_layout = 'square';

// Which modes are offered to visitors as a switch.
// Stored as comma-separated: "square,masonry". Default = just the current layout.
$available_raw    = $settings['archive_layouts_available'] ?? $current_layout;
$available_modes  = array_filter(explode(',', $available_raw), fn($m) => isset($all_layouts[$m]));
if (empty($available_modes)) $available_modes = [$current_layout];
$available_modes  = array_values($available_modes);

// Note: archive_border_style / archive_shadow_depth removed — were saved but never consumed.

// Grid sizing
$current_cols   = max(2, min(8, (int)($settings['browse_cols'] ?? 4)));
$current_gutter = (int)($settings['archive_gutter'] ?? 4);
$current_row_h  = (int)($settings['justified_row_height'] ?? 220);

// Thumbnail size — backwards-compat with old pixel values
$size_steps   = ['xs' => 'XS — Extra Small', 's' => 'S — Small', 'm' => 'M — Medium', 'l' => 'L — Large', 'xl' => 'XL — Extra Large'];
$current_size = $settings['thumb_size'] ?? 'm';
if (is_numeric($current_size)) {
    $px = (int)$current_size;
    if ($px <= 130) $current_size = 'xs';
    elseif ($px <= 170) $current_size = 's';
    elseif ($px <= 230) $current_size = 'm';
    elseif ($px <= 290) $current_size = 'l';
    else $current_size = 'xl';
}
if (!isset($size_steps[$current_size])) $current_size = 'm';
?>

<div class="main">
    <h2>ARCHIVE APPEARANCE</h2>
    <p class="dim" style="margin-bottom:20px;">Controls how your image library looks to visitors. Layout mode, spacing, borders, and the floating gallery — your call, not the skin's.</p>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">> ARCHIVE APPEARANCE SAVED</div>
    <?php endif; ?>

    <form method="POST">
    <div id="smack-skin-config-wrap">

        <!-- ── GRID ARCHITECTURE ─────────────────────────────────────── -->
        <div class="box">
            <h3>GRID ARCHITECTURE</h3>
            <div class="dash-grid">

                <div class="lens-input-wrapper">
                    <label>DEFAULT LAYOUT <span class="field-tip" data-tip="The layout visitors see when they first arrive. If they have previously changed it, their preference wins.">ⓘ</span></label>
                    <select name="settings[archive_layout]" id="default-layout-select"
                            onchange="syncAvailableCheckbox(this.value)">
                        <?php foreach ($all_layouts as $lk => $ll): ?>
                            <option value="<?php echo $lk; ?>" <?php echo ($current_layout === $lk) ? 'selected' : ''; ?>>
                                <?php echo strtoupper($ll); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="none" <?php echo ($current_layout === 'none') ? 'selected' : ''; ?>>
                            DISABLED (HIDE ARCHIVE FROM NAV)
                        </option>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>OFFER VISITORS A LAYOUT SWITCH? <span class="field-tip" data-tip="Checked modes appear as toggle buttons on the public archive. The default layout is always included automatically.">ⓘ</span></label>
                    <div style="display:flex; flex-direction:column; gap:6px; margin-top:4px;">
                        <?php foreach ($all_layouts as $lk => $ll): ?>
                            <label style="display:flex; align-items:center; gap:8px; font-size:0.85em; cursor:pointer;">
                                <input type="checkbox"
                                       name="settings[archive_layouts_available][]"
                                       value="<?php echo $lk; ?>"
                                       id="avail-<?php echo $lk; ?>"
                                       <?php echo in_array($lk, $available_modes) ? 'checked' : ''; ?>>
                                <?php echo strtoupper($ll); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p id="layout-switch-status" style="margin:8px 0 0; font-size:0.8em; opacity:0.7;"></p>
                </div>

                <div class="lens-input-wrapper">
                    <label>THUMBNAIL SIZE <span class="field-tip" data-tip="Applies to square and cropped grid modes.">ⓘ</span></label>
                    <select name="settings[thumb_size]">
                        <?php foreach ($size_steps as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo ($current_size === $key) ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>COLUMNS <span class="field-tip" data-tip="How many columns across on desktop. Applies to square and cropped grid modes.">ⓘ</span></label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <input type="range" name="settings[browse_cols]"
                               min="2" max="8" step="1"
                               value="<?php echo $current_cols; ?>"
                               oninput="this.nextElementSibling.textContent = this.value">
                        <span style="min-width:24px; font-family:monospace;"><?php echo $current_cols; ?></span>
                    </div>
                </div>

                <div class="lens-input-wrapper">
                    <label>GUTTER <span class="field-tip" data-tip="Gap between grid tiles.">ⓘ</span></label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <input type="range" name="settings[archive_gutter]"
                               min="0" max="24" step="2"
                               value="<?php echo $current_gutter; ?>"
                               oninput="this.nextElementSibling.textContent = this.value + 'px'">
                        <span style="min-width:36px; font-family:monospace;"><?php echo $current_gutter; ?>px</span>
                    </div>
                </div>

                <div class="lens-input-wrapper">
                    <label>JUSTIFIED ROW HEIGHT <span class="field-tip" data-tip="Target row height for masonry/justified mode. Rows expand slightly to fill width.">ⓘ</span></label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <input type="range" name="settings[justified_row_height]"
                               min="120" max="500" step="10"
                               value="<?php echo $current_row_h; ?>"
                               oninput="this.nextElementSibling.textContent = this.value + 'px'">
                        <span style="min-width:44px; font-family:monospace;"><?php echo $current_row_h; ?>px</span>
                    </div>
                </div>

            </div>
        </div>

    </div><!-- /smack-skin-config-wrap -->
    <!-- Floating Gallery settings moved to Global Vibe (smack-globalvibe.php) -->

    <?php if (!empty($archive_manifest_opts)): ?>
    <!-- ── ARCHIVE DISPLAY (skin options flagged admin_page=>'archive') ── -->
    <div id="smack-skin-config-wrap">
        <div class="box">
            <h3>ARCHIVE DISPLAY</h3>
            <div class="dash-grid">
            <?php foreach ($archive_manifest_opts as $k => $o):
                $val = ($settings[$k] ?? '') !== '' ? $settings[$k] : ($o['default'] ?? '');
            ?>
                <div class="lens-input-wrapper">
                    <label><?php echo strtoupper($o['label']); ?></label>
                    <select name="settings[<?php echo htmlspecialchars($k); ?>]">
                        <?php foreach ($o['options'] ?? [] as $opt_val => $opt_data):
                            $opt_label = is_array($opt_data) ? ($opt_data['label'] ?? $opt_val) : $opt_data;
                        ?>
                            <option value="<?php echo htmlspecialchars($opt_val); ?>"<?php echo ($val === $opt_val) ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($opt_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($archive_engine_opts as $_ekey => $_edata): ?>
    <!-- ── ENGINE CONTROLS (engines flagged admin_page=>'archive') ── -->
    <div id="smack-skin-config-wrap">
        <div class="box">
            <h3><?php echo htmlspecialchars(strtoupper($_edata['label'] ?? $_ekey)); ?> SETTINGS</h3>
            <div class="dash-grid">
            <?php foreach ($_edata['controls'] as $k => $o):
                $val = ($settings[$k] ?? '') !== '' ? $settings[$k] : ($o['default'] ?? '');
            ?>
                <div class="lens-input-wrapper">
                    <label><?php echo strtoupper(htmlspecialchars($o['label'] ?? $k)); ?></label>
                    <?php if ($o['type'] === 'select'): ?>
                    <select name="settings[<?php echo htmlspecialchars($k); ?>]">
                        <?php foreach ($o['options'] ?? [] as $opt_val => $opt_label): ?>
                            <option value="<?php echo htmlspecialchars($opt_val); ?>"<?php echo ($val == $opt_val) ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars($opt_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php elseif ($o['type'] === 'range'): ?>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <input type="range"
                               name="settings[<?php echo htmlspecialchars($k); ?>]"
                               min="<?php echo (int)($o['min'] ?? 0); ?>"
                               max="<?php echo (int)($o['max'] ?? 100); ?>"
                               step="<?php echo (int)($o['step'] ?? 1); ?>"
                               value="<?php echo htmlspecialchars($val); ?>"
                               oninput="this.nextElementSibling.textContent = this.value">
                        <span style="min-width:32px; font-family:monospace;"><?php echo htmlspecialchars($val); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="form-action-row">
        <button type="submit" name="save_archive_appearance" class="master-update-btn">SAVE ARCHIVE APPEARANCE</button>
    </div>
    </form>
</div>

<script>
// Keep the default layout's checkbox always ticked and disabled so the owner
// can't accidentally remove the current default from the available set.
function syncAvailableCheckbox(defaultVal) {
    ['square','cropped','masonry','croppedwithcalendar'].forEach(function(m) {
        var cb = document.getElementById('avail-' + m);
        if (!cb) return;
        if (m === defaultVal) {
            cb.checked  = true;
            cb.disabled = true;
        } else {
            cb.disabled = false;
        }
    });
    updateSwitchStatus();
}

// Show visitors what toggle they will actually see based on checked count.
var layoutLabels = {
    'square':              'Grid',
    'cropped':             'Crop',
    'masonry':             'Flow',
    'croppedwithcalendar': 'Cal'
};
function updateSwitchStatus() {
    var status = document.getElementById('layout-switch-status');
    if (!status) return;
    var checked = [];
    Object.keys(layoutLabels).forEach(function(m) {
        var cb = document.getElementById('avail-' + m);
        if (cb && cb.checked) checked.push(layoutLabels[m]);
    });
    if (checked.length < 2) {
        status.textContent = 'No toggle shown — select 2 or more layouts to offer visitors a switch.';
        status.style.color = 'var(--accent, #ff0)';
    } else {
        status.textContent = '✓ Visitors will see a ' + checked.length + '-way toggle: ' + checked.join(' / ');
        status.style.color = 'var(--status-ok, var(--accent, #fff))';
    }
}

// Wire up all layout checkboxes.
document.addEventListener('DOMContentLoaded', function() {
    ['square','cropped','masonry','croppedwithcalendar'].forEach(function(m) {
        var cb = document.getElementById('avail-' + m);
        if (cb) cb.addEventListener('change', updateSwitchStatus);
    });
    syncAvailableCheckbox(document.getElementById('default-layout-select').value);
});
</script>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
