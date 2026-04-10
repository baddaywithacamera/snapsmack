<?php
/**
 * SNAPSMACK - Archive Appearance
 * Alpha v0.7.9g
 *
 * Controls all visual and structural settings for the public archive page.
 * Grid layout mode and visitor switching options are set here by the site
 * owner — skins no longer gate which modes are available.
 *
 * Moved here from smack-globalvibe.php in v0.7.9f.
 * Archive mode ownership moved from skin manifests to site owner in v0.7.9g.
 */

require_once 'core/auth.php';

// --- MANIFEST (for wall/pimpotron detection only — no longer gates grid options) ---
$active_skin = $settings['active_skin'] ?? '';
$manifest    = [];
if ($active_skin && file_exists("skins/{$active_skin}/manifest.php")) {
    $manifest = include "skins/{$active_skin}/manifest.php";
}
$pimpotron_active = !empty($manifest['engines']['pimpotron']);
$supports_wall    = !empty($manifest['features']['supports_wall']);
$wall_unavailable = $pimpotron_active || !$supports_wall;

// --- POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_archive_appearance'])) {
    if (isset($_POST['settings']) && is_array($_POST['settings'])) {
        // archive_layouts_available arrives as an array of checkboxes; serialise it.
        if (isset($_POST['settings']['archive_layouts_available']) && is_array($_POST['settings']['archive_layouts_available'])) {
            $avail = array_intersect($_POST['settings']['archive_layouts_available'], ['square', 'cropped', 'masonry']);
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

// All layout modes — always available to the owner regardless of skin.
$all_layouts = [
    'square'  => 'Square Grid (1:1 Cropped)',
    'cropped' => 'Cropped Grid (Natural Aspect)',
    'masonry' => 'Masonry / Justified (Flickr-Style)',
];

$current_layout = $settings['archive_layout'] ?? 'square';
if (!isset($all_layouts[$current_layout])) $current_layout = 'square';

// Which modes are offered to visitors as a switch.
// Stored as comma-separated: "square,masonry". Default = just the current layout.
$available_raw    = $settings['archive_layouts_available'] ?? $current_layout;
$available_modes  = array_filter(explode(',', $available_raw), fn($m) => isset($all_layouts[$m]));
if (empty($available_modes)) $available_modes = [$current_layout];
$available_modes  = array_values($available_modes);

// Border/shadow
$border_styles = [
    'none'          => 'None',
    'hairline'      => 'Hairline',
    'solid'         => 'Solid',
    'shadow'        => 'Shadow',
    'double_shadow' => 'Double Shadow',
];
$current_border = $settings['archive_border_style'] ?? 'none';
$current_shadow = (int)($settings['archive_shadow_depth'] ?? 2);

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
                    <label>DEFAULT LAYOUT</label>
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
                    <span class="dim">THE LAYOUT VISITORS SEE WHEN THEY FIRST ARRIVE. IF THEY HAVE PREVIOUSLY CHANGED IT, THEIR PREFERENCE WINS.</span>
                </div>

                <div class="lens-input-wrapper">
                    <label>OFFER VISITORS A LAYOUT SWITCH?</label>
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
                    <span class="dim">CHECKED MODES APPEAR AS TOGGLE BUTTONS ON THE PUBLIC ARCHIVE. THE DEFAULT LAYOUT IS ALWAYS INCLUDED AUTOMATICALLY.</span>
                </div>

                <div class="lens-input-wrapper">
                    <label>THUMBNAIL SIZE</label>
                    <select name="settings[thumb_size]">
                        <?php foreach ($size_steps as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo ($current_size === $key) ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="dim">APPLIES TO SQUARE AND CROPPED GRID MODES.</span>
                </div>

                <div class="lens-input-wrapper">
                    <label>COLUMNS</label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <input type="range" name="settings[browse_cols]"
                               min="2" max="8" step="1"
                               value="<?php echo $current_cols; ?>"
                               oninput="this.nextElementSibling.textContent = this.value">
                        <span style="min-width:24px; font-family:monospace;"><?php echo $current_cols; ?></span>
                    </div>
                    <span class="dim">HOW MANY COLUMNS ACROSS ON DESKTOP. APPLIES TO SQUARE AND CROPPED GRID MODES.</span>
                </div>

                <div class="lens-input-wrapper">
                    <label>GUTTER</label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <input type="range" name="settings[archive_gutter]"
                               min="0" max="24" step="2"
                               value="<?php echo $current_gutter; ?>"
                               oninput="this.nextElementSibling.textContent = this.value + 'px'">
                        <span style="min-width:36px; font-family:monospace;"><?php echo $current_gutter; ?>px</span>
                    </div>
                    <span class="dim">GAP BETWEEN GRID TILES.</span>
                </div>

                <div class="lens-input-wrapper">
                    <label>JUSTIFIED ROW HEIGHT</label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <input type="range" name="settings[justified_row_height]"
                               min="120" max="500" step="10"
                               value="<?php echo $current_row_h; ?>"
                               oninput="this.nextElementSibling.textContent = this.value + 'px'">
                        <span style="min-width:44px; font-family:monospace;"><?php echo $current_row_h; ?>px</span>
                    </div>
                    <span class="dim">TARGET ROW HEIGHT FOR MASONRY / JUSTIFIED MODE. ROWS EXPAND SLIGHTLY TO FILL WIDTH.</span>
                </div>

            </div>
        </div>

        <!-- ── TILE BORDER & SHADOW ──────────────────────────────────── -->
        <div class="box">
            <h3>TILE BORDER &amp; SHADOW</h3>
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>BORDER STYLE</label>
                    <select name="settings[archive_border_style]" id="archive-border-select"
                            onchange="document.getElementById('shadow-depth-row').classList.toggle('d-none', !['shadow','double_shadow'].includes(this.value))">
                        <?php foreach ($border_styles as $bk => $bl): ?>
                            <option value="<?php echo $bk; ?>" <?php echo ($current_border === $bk) ? 'selected' : ''; ?>>
                                <?php echo strtoupper($bl); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="dim">APPLIED TO EVERY TILE IN THE ARCHIVE GRID.</span>
                </div>
                <div class="lens-input-wrapper<?php echo in_array($current_border, ['shadow','double_shadow']) ? '' : ' d-none'; ?>" id="shadow-depth-row">
                    <label>SHADOW DEPTH</label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <input type="range" name="settings[archive_shadow_depth]"
                               min="1" max="5" step="1"
                               value="<?php echo $current_shadow; ?>"
                               oninput="this.nextElementSibling.textContent = this.value">
                        <span style="min-width:20px; font-family:monospace;"><?php echo $current_shadow; ?></span>
                    </div>
                    <span class="dim">1 = SUBTLE. 5 = DRAMATIC.</span>
                </div>
            </div>
        </div>

        <!-- ── FLOATING GALLERY ──────────────────────────────────────── -->
        <div class="box">
            <h3>FLOATING GALLERY</h3>
            <div class="dash-grid">

                <div class="lens-input-wrapper">
                    <label>FLOATING GALLERY LINK</label>
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
                    <label>SCROLL FRICTION</label>
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
                        <span class="dim">HIGHER = MORE COAST. LOWER = STOPS FASTER.</span>
                    <?php endif; ?>
                </div>

                <div class="lens-input-wrapper">
                    <label>DRAG WEIGHT</label>
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
                        <span class="dim">HOW HEAVY THE DRAG FEELS. HIGHER = MORE SLUGGISH.</span>
                    <?php endif; ?>
                </div>

                <div class="lens-input-wrapper">
                    <label>REFLECTION</label>
                    <?php if ($wall_unavailable): ?>
                        <select disabled class="select-locked"><option>DISABLED BY SKIN</option></select>
                        <input type="hidden" name="settings[wall_reflect]" value="0">
                    <?php else: ?>
                        <select name="settings[wall_reflect]">
                            <option value="1" <?php echo (($settings['wall_reflect'] ?? '0') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                            <option value="0" <?php echo (($settings['wall_reflect'] ?? '0') == '0') ? 'selected' : ''; ?>>DISABLED</option>
                        </select>
                        <span class="dim">REFLECTS THE GALLERY OFF THE FLOOR.</span>
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

    </div><!-- /smack-skin-config-wrap -->

    <div class="form-action-row">
        <button type="submit" name="save_archive_appearance" class="master-update-btn">SAVE ARCHIVE APPEARANCE</button>
    </div>
    </form>
</div>

<script>
// Keep the default layout's checkbox always ticked and disabled so the owner
// can't accidentally remove the current default from the available set.
function syncAvailableCheckbox(defaultVal) {
    ['square','cropped','masonry'].forEach(function(m) {
        var cb = document.getElementById('avail-' + m);
        if (!cb) return;
        if (m === defaultVal) {
            cb.checked  = true;
            cb.disabled = true;
        } else {
            cb.disabled = false;
        }
    });
}
// Run once on load to lock the current default.
syncAvailableCheckbox(document.getElementById('default-layout-select').value);
</script>

<?php include 'core/admin-footer.php'; ?>
