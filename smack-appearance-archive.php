<?php
/**
 * SNAPSMACK - Archive Appearance
 * Alpha v0.7.9f
 *
 * Controls all visual and structural settings for the public archive page.
 * Grid layout, crop style, thumbnail size, columns, gutter, border/shadow,
 * and the floating gallery — everything that affects how the image library
 * is presented to visitors.
 *
 * Moved here from smack-globalvibe.php in v0.7.9f.
 */

require_once 'core/auth.php';

// --- MANIFEST (for skin-gated options) ---
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

// Layout options gated by skin manifest
$all_layouts = [
    'square'  => 'Square Grid (1:1 Cropped)',
    'cropped' => 'Cropped Grid (Max 3:2 Aspect)',
    'masonry' => 'Justified (Flickr-Style Row Fill)',
    'none'    => 'Disabled (Hide Archive View)',
];
$supported_layouts = $manifest['features']['archive_layouts'] ?? ['square'];
$current_layout    = $settings['archive_layout'] ?? 'square';
if ($current_layout !== 'none' && !in_array($current_layout, $supported_layouts)) {
    $current_layout = $supported_layouts[0];
}
$layout_locked = (count($supported_layouts) === 1);

// Crop styles gated by manifest (new in 0.7.9f)
$supported_crops   = $manifest['archive_options']['crop_styles'] ?? $supported_layouts;
$all_crop_labels   = [
    'square'  => 'Square (1:1)',
    'natural' => 'Natural (Preserve Aspect)',
    'flickr'  => 'Flickr (Justified Row Fill)',
];

// Border styles
$border_styles = [
    'none'          => 'None',
    'hairline'      => 'Hairline',
    'solid'         => 'Solid',
    'shadow'        => 'Shadow',
    'double_shadow' => 'Double Shadow',
];
$current_border = $settings['archive_border_style'] ?? 'none';
$current_shadow = (int)($settings['archive_shadow_depth'] ?? 2);

// Columns range from manifest
$col_min = (int)($manifest['archive_options']['columns_range'][0] ?? 2);
$col_max = (int)($manifest['archive_options']['columns_range'][1] ?? 4);
$current_cols = (int)($settings['browse_cols'] ?? 4);
$current_cols = max($col_min, min($col_max, $current_cols));

// Gutter
$current_gutter = (int)($settings['archive_gutter'] ?? 4);
?>

<div class="main">
    <h2>ARCHIVE APPEARANCE</h2>
    <p class="dim" style="margin-bottom:20px;">Controls how your image library looks to visitors. Grid layout, crop style, spacing, and the floating gallery.</p>

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
                    <label>ARCHIVE DISPLAY MODE</label>
                    <?php if ($layout_locked): ?>
                        <select name="settings[archive_layout]">
                            <option value="<?php echo htmlspecialchars($supported_layouts[0]); ?>" <?php echo ($current_layout === $supported_layouts[0]) ? 'selected' : ''; ?>>
                                <?php echo strtoupper($all_layouts[$supported_layouts[0]] ?? $supported_layouts[0]); ?>
                            </option>
                            <option value="none" <?php echo ($current_layout === 'none') ? 'selected' : ''; ?>>
                                <?php echo strtoupper($all_layouts['none']); ?>
                            </option>
                        </select>
                        <span class="dim">ACTIVE SKIN SUPPORTS ONE LAYOUT MODE. DISABLE TO HIDE ARCHIVE FROM NAV.</span>
                    <?php else: ?>
                        <select name="settings[archive_layout]">
                            <?php foreach ($supported_layouts as $lk): ?>
                                <?php if (isset($all_layouts[$lk])): ?>
                                    <option value="<?php echo htmlspecialchars($lk); ?>" <?php echo ($current_layout === $lk) ? 'selected' : ''; ?>>
                                        <?php echo strtoupper($all_layouts[$lk]); ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <option value="none" <?php echo ($current_layout === 'none') ? 'selected' : ''; ?>>
                                <?php echo strtoupper($all_layouts['none']); ?>
                            </option>
                        </select>
                    <?php endif; ?>
                </div>

                <?php if (count($supported_crops) > 1): ?>
                <div class="lens-input-wrapper">
                    <label>CROP STYLE</label>
                    <div class="toggle-row">
                        <?php foreach ($supported_crops as $crop_key): ?>
                            <?php if (!isset($all_crop_labels[$crop_key])) continue; ?>
                            <label class="toggle-pill">
                                <input type="radio" name="settings[archive_crop_style]" value="<?php echo htmlspecialchars($crop_key); ?>"
                                    <?php echo (($settings['archive_crop_style'] ?? $supported_crops[0]) === $crop_key) ? 'checked' : ''; ?>>
                                <span><?php echo strtoupper($all_crop_labels[$crop_key]); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <span class="dim">ACTIVE SKIN MUST DECLARE SUPPORTED CROP STYLES IN ITS MANIFEST.</span>
                </div>
                <?php endif; ?>

                <div class="lens-input-wrapper">
                    <label>THUMBNAIL SIZE</label>
                    <select name="settings[thumb_size]">
                        <?php
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
                        foreach ($size_steps as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo ($current_size === $key) ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>COLUMNS</label>
                    <?php if ($col_min === $col_max): ?>
                        <input type="number" name="settings[browse_cols]" value="<?php echo $current_cols; ?>" min="<?php echo $col_min; ?>" max="<?php echo $col_max; ?>" readonly>
                        <span class="dim">ACTIVE SKIN FIXES COLUMNS AT <?php echo $col_min; ?>.</span>
                    <?php else: ?>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <input type="range" name="settings[browse_cols]"
                                   min="<?php echo $col_min; ?>" max="<?php echo $col_max; ?>" step="1"
                                   value="<?php echo $current_cols; ?>"
                                   oninput="this.nextElementSibling.textContent = this.value">
                            <span style="min-width:24px; font-family:monospace;"><?php echo $current_cols; ?></span>
                        </div>
                        <span class="dim">HOW MANY COLUMNS ACROSS ON DESKTOP. SKIN SETS ALLOWED RANGE.</span>
                    <?php endif; ?>
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

            </div>
        </div>

        <!-- ── BORDER & SHADOW ───────────────────────────────────────── -->
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
                    <span class="dim">APPLIED TO EVERY TILE IN THE ARCHIVE GRID. SKIN CSS HANDLES THE ACTUAL RENDERING.</span>
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
                        <span class="dim">
                            <?php echo $pimpotron_active ? 'PIMPOTRON IS ACTIVE &mdash; FLOATING GALLERY IS INCOMPATIBLE.' : 'ACTIVE SKIN DOES NOT SUPPORT THE FLOATING GALLERY.'; ?>
                        </span>
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

<style>
.toggle-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px; }
.toggle-pill input[type="radio"] { display: none; }
.toggle-pill span {
    display: inline-block;
    padding: 5px 14px;
    border: 1px solid var(--lens-border, #333);
    border-radius: 3px;
    font-size: 0.78rem;
    font-weight: 600;
    letter-spacing: 0.5px;
    cursor: pointer;
    color: var(--text-dim, #888);
    transition: all 0.15s;
}
.toggle-pill input[type="radio"]:checked + span {
    background: var(--accent, #5b9bd5);
    border-color: var(--accent, #5b9bd5);
    color: #fff;
}
</style>

<?php include 'core/admin-footer.php'; ?>
