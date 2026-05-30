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


require_once 'core/auth-smack.php';

// --- MANIFEST (best-effort: skin-specific options if it loads; not required for core controls) ---
$active_skin = $settings['active_skin'] ?? '';
$manifest    = [];
if ($active_skin) {
    $manifest_path = "skins/{$active_skin}/manifest.php";
    if (file_exists($manifest_path)) {
        $manifest = include $manifest_path;
    }
}
// Skin manifest options flagged admin_page=>'archive' rendered here if manifest loaded.
$archive_manifest_opts = [];
if (is_array($manifest)) {
    foreach ($manifest['options'] ?? [] as $k => $o) {
        if (($o['admin_page'] ?? 'skin') === 'archive') {
            $archive_manifest_opts[$k] = $o;
        }
    }
}

// --- POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_archive_appearance'])) {
    if (isset($_POST['settings']) && is_array($_POST['settings'])) {
        // 0.7.79: archive_layouts_available is no longer authoritative — kept
        // for legacy reads but pinned to 'thumbs,masonry' regardless of input.
        $_POST['settings']['archive_layouts_available'] = 'thumbs,masonry';

        // Normalise default layout to thumbs|masonry.
        $_def = $_POST['settings']['archive_layout'] ?? 'thumbs';
        if (in_array($_def, ['square', 'cropped', 'croppedwithcalendar'], true)) $_def = 'thumbs';
        if (!in_array($_def, ['thumbs', 'masonry'], true)) $_def = 'thumbs';
        $_POST['settings']['archive_layout'] = $_def;

        // Coerce checkboxes to '0'/'1'.
        foreach (['archive_show_layout_toggle', 'archive_calendar_enabled', 'archive_calendar_default_open', 'masonry_use_thumbs'] as $_k) {
            $_POST['settings'][$_k] = empty($_POST['settings'][$_k]) ? '0' : '1';
        }

        // Thumb style guard.
        if (!isset($_POST['settings']['archive_thumb_style']) ||
            !in_array($_POST['settings']['archive_thumb_style'], ['square', 'cropped'], true)) {
            $_POST['settings']['archive_thumb_style'] = 'cropped';
        }

        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        foreach ($_POST['settings'] as $k => $v) {
            $stmt->execute([$k, $v, $v]);
        }

        // CSS regeneration for per-grid and per-masonry border settings.
        // Each setting pair (width + color) writes its own marker-keyed rule into
        // custom_css_public so repeated saves find and replace the existing rule.
        $blob = (string)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'custom_css_public'")->fetchColumn() ?: '');

        // Helper: upsert a marker-keyed CSS rule into the blob.
        $upsert_rule = function(string &$blob, string $marker, string $rule): void {
            if (strpos($blob, $marker) !== false) {
                $blob = preg_replace('/' . preg_quote($marker, '/') . '[^\n]+/', $rule, $blob);
            } elseif (strpos($blob, '/* SKIN_END */') !== false) {
                $blob = str_replace('/* SKIN_END */', $rule . "\n/* SKIN_END */", $blob);
            } else {
                $blob .= ($blob !== '' ? "\n" : '') . $rule;
            }
        };

        // Grid / cropped thumb border.
        $gw = max(0, min(8, (int)($_POST['settings']['archive_grid_border_width'] ?? 1)));
        $gc = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['settings']['archive_grid_border_color'] ?? '')
              ? $_POST['settings']['archive_grid_border_color'] : '#888888';
        if ($gw > 0) {
            $grid_rule = "/* arch_opt:grid_border */ .fsog-archive-item .fsog-thumb, .rg-archive-item .rg-thumb { border: {$gw}px solid {$gc} !important; box-shadow: none !important; }";
        } else {
            $grid_rule = "/* arch_opt:grid_border */ .fsog-archive-item .fsog-thumb, .rg-archive-item .rg-thumb { border: none !important; box-shadow: none !important; }";
        }
        $upsert_rule($blob, '/* arch_opt:grid_border */', $grid_rule);

        // Masonry / justified border (outline, not border — public-facing.css resets border !important on .justified-item).
        $mw = in_array((int)($_POST['settings']['archive_masonry_border_width'] ?? 1), [0,1,2], true)
              ? (int)$_POST['settings']['archive_masonry_border_width'] : 1;
        $mc = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['settings']['archive_masonry_border_color'] ?? '')
              ? $_POST['settings']['archive_masonry_border_color'] : '#888888';
        if ($mw > 0) {
            $masonry_rule = "/* arch_opt:masonry_border */ .justified-item { outline: {$mw}px solid {$mc} !important; outline-offset: -{$mw}px; }";
        } else {
            $masonry_rule = "/* arch_opt:masonry_border */ .justified-item { outline: none !important; }";
        }
        $upsert_rule($blob, '/* arch_opt:masonry_border */', $masonry_rule);

        $pdo->prepare("REPLACE INTO snap_settings (setting_key, setting_val) VALUES ('custom_css_public', ?)")
            ->execute([$blob]);
    }
    header("Location: smack-appearance-archive.php?msg=SAVED");
    exit;
}

$page_title = "Archive Appearance";
include 'core/admin-header.php';
include 'core/sidebar.php';

// 0.7.79: layout vocabulary is just thumbs | masonry.
// Thumb style (square or cropped) is admin's separate choice and applies
// whenever layout=thumbs. Calendar is its own enable+default-open pair.
$all_layouts = [
    'thumbs'  => 'Thumbs',
    'masonry' => 'Masonry / Justified (Flickr-Style)',
];

$current_layout = $settings['archive_layout'] ?? 'thumbs';
if (in_array($current_layout, ['square', 'cropped', 'croppedwithcalendar'], true)) {
    $current_layout = 'thumbs';
}
if (!isset($all_layouts[$current_layout])) $current_layout = 'thumbs';

$thumb_style = $settings['archive_thumb_style'] ?? 'cropped';
if (!in_array($thumb_style, ['square', 'cropped'], true)) $thumb_style = 'cropped';

$show_layout_toggle    = isset($settings['archive_show_layout_toggle'])
                          ? !empty($settings['archive_show_layout_toggle'])
                          : true;
$calendar_enabled      = !empty($settings['archive_calendar_enabled']);
$calendar_default_open = !empty($settings['archive_calendar_default_open']);
$calendar_months       = max(1, min(6, (int)($settings['calendar_months'] ?? 1)));
if ($collections_rows !== 2) $collections_rows = 1;

// Note: archive_border_style / archive_shadow_depth removed — were saved but never consumed.

// Grid sizing
$current_cols   = max(2, min(8, (int)($settings['browse_cols'] ?? 4)));
$current_gutter = (int)($settings['archive_gutter'] ?? 4);
$current_row_h  = (int)($settings['justified_row_height'] ?? 220);
$masonry_use_thumbs = isset($settings['masonry_use_thumbs']) ? !empty($settings['masonry_use_thumbs']) : true;

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
                    <select name="settings[archive_layout]" id="default-layout-select">
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
                    <label>THUMB STYLE <span class="field-tip" data-tip="When layout is Thumbs, render images as square (1:1 cropped) or cropped (natural aspect). Pick one — applies to whichever skin is active.">ⓘ</span></label>
                    <select name="settings[archive_thumb_style]">
                        <option value="cropped" <?php echo $thumb_style === 'cropped' ? 'selected' : ''; ?>>CROPPED (NATURAL ASPECT)</option>
                        <option value="square"  <?php echo $thumb_style === 'square'  ? 'selected' : ''; ?>>SQUARE (1:1 CROPPED)</option>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>VISITOR CONTROLS</label>
                    <div style="display:flex; flex-direction:column; gap:6px; margin-top:4px;">
                        <label style="display:flex; align-items:center; gap:8px; font-size:0.85em; cursor:pointer;">
                            <input type="checkbox" name="settings[archive_show_layout_toggle]" value="1" <?php echo $show_layout_toggle ? 'checked' : ''; ?>>
                            ALLOW VISITORS TO TOGGLE THUMBS / MASONRY
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:0.85em; cursor:pointer;">
                            <input type="checkbox" name="settings[archive_calendar_enabled]" value="1" <?php echo $calendar_enabled ? 'checked' : ''; ?>>
                            ENABLE CALENDAR PANEL (independent of layout)
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; font-size:0.85em; cursor:pointer; padding-left:24px; opacity:<?php echo $calendar_enabled ? '1' : '0.5'; ?>;">
                            <input type="checkbox" name="settings[archive_calendar_default_open]" value="1" <?php echo $calendar_default_open ? 'checked' : ''; ?>>
                            CALENDAR STARTS OPEN BY DEFAULT
                        </label>
                    </div>
                    <p style="margin:8px 0 0; font-size:0.78em; opacity:0.6;">Visitors who change these preferences have their choice cookied for a year.</p>
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

                <div class="lens-input-wrapper">
                    <label>MASONRY IMAGE SIZE <span class="field-tip" data-tip="Full size loads original images — sharpest quality, slower on large archives. Medium uses pre-generated ~600px thumbnails — faster load, recommended for most sites.">ⓘ</span></label>
                    <select name="settings[masonry_use_thumbs]">
                        <option value="1" <?php echo  $masonry_use_thumbs ? 'selected' : ''; ?>>MEDIUM (~600px THUMBNAILS) — RECOMMENDED</option>
                        <option value="0" <?php echo !$masonry_use_thumbs ? 'selected' : ''; ?>>FULL SIZE (ORIGINAL FILES)</option>
                    </select>
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

    <!-- ── CALENDAR SIDEBAR SETTINGS ─────────────────────────────────────── -->
    <!-- Hardcoded — not manifest-dependent. Applies when layout = croppedwithcalendar. -->
    <div id="smack-skin-config-wrap">
        <div class="box">
            <h3>ARCHIVE CALENDAR SIDEBAR (SLIDING DATE PANEL) SETTINGS</h3>
            <div class="dash-grid">

                <div class="lens-input-wrapper">
                    <label>MONTHS TO SHOW</label>
                    <select name="settings[calendar_months]">
                        <?php
                        $cal_months = $settings['calendar_months'] ?? '1';
                        foreach (['1'=>'1 Month','2'=>'2 Months','3'=>'3 Months','4'=>'4 Months'] as $mv => $ml):
                        ?>
                            <option value="<?php echo $mv; ?>"<?php echo ($cal_months == $mv) ? ' selected' : ''; ?>><?php echo $ml; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>RECENT POSTS LISTED</label>
                    <?php $cal_posts = max(5, min(20, (int)($settings['calendar_post_count'] ?? 10))); ?>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <input type="range" name="settings[calendar_post_count]"
                               min="5" max="20" step="1"
                               value="<?php echo $cal_posts; ?>"
                               oninput="this.nextElementSibling.textContent = this.value">
                        <span style="min-width:24px; font-family:monospace;"><?php echo $cal_posts; ?></span>
                    </div>
                </div>

                <div class="lens-input-wrapper">
                    <label>PANEL SIDE</label>
                    <select name="settings[calendar_side]">
                        <?php $cal_side = $settings['calendar_side'] ?? 'left'; ?>
                        <option value="left"<?php echo ($cal_side === 'left')  ? ' selected' : ''; ?>>Slide From Left</option>
                        <option value="right"<?php echo ($cal_side === 'right') ? ' selected' : ''; ?>>Slide From Right</option>
                    </select>
                </div>

            </div>
        </div>
    </div>

    <?php if (!isset($archive_manifest_opts['archive_frame_style'])): ?>
    <!-- ── ARCHIVE THUMB BORDERS ─────────────────────────────────────────── -->
    <div id="smack-skin-config-wrap">
        <?php
        $agbw = max(0, min(8, (int)($settings['archive_grid_border_width'] ?? 1)));
        $agbc = preg_match('/^#[0-9a-fA-F]{6}$/', $settings['archive_grid_border_color'] ?? '') ? $settings['archive_grid_border_color'] : '#888888';
        $ambw = in_array((int)($settings['archive_masonry_border_width'] ?? 1), [0,1,2]) ? (int)$settings['archive_masonry_border_width'] : 1;
        $ambc = preg_match('/^#[0-9a-fA-F]{6}$/', $settings['archive_masonry_border_color'] ?? '') ? $settings['archive_masonry_border_color'] : '#888888';
        ?>
        <div class="box">
            <h3>GRID THUMB BORDER <span class="field-tip" data-tip="Border applied to thumbnails in square, cropped, and calendar grid modes. Applies to all skins.">ⓘ</span></h3>
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>WIDTH (PX)</label>
                    <input type="range" name="settings[archive_grid_border_width]"
                           min="0" max="8" step="1" value="<?php echo $agbw; ?>"
                           oninput="document.getElementById('agbw-val').textContent=this.value+'px'">
                    <span id="agbw-val" style="font-size:0.85rem; color:var(--text-muted,#888);"><?php echo $agbw; ?>px</span>
                </div>
                <div class="lens-input-wrapper">
                    <label>COLOUR</label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="color" name="settings[archive_grid_border_color]"
                               value="<?php echo htmlspecialchars($agbc); ?>"
                               style="width:48px; height:36px; padding:2px; border-radius:4px; cursor:pointer; border:1px solid var(--border,#333); background:transparent;">
                        <span style="font-family:monospace; font-size:0.85rem; color:var(--text-muted,#888);"><?php echo htmlspecialchars($agbc); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="box" style="margin-top:16px;">
            <h3>MASONRY / JUSTIFIED THUMB BORDER <span class="field-tip" data-tip="Border on justified-grid thumbnails. Uses outline rather than border so it renders correctly in all skins.">ⓘ</span></h3>
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>WIDTH</label>
                    <select name="settings[archive_masonry_border_width]">
                        <?php foreach ([0 => 'None', 1 => '1px', 2 => '2px'] as $mv => $ml): ?>
                            <option value="<?php echo $mv; ?>"<?php echo ($ambw === $mv) ? ' selected' : ''; ?>><?php echo $ml; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lens-input-wrapper">
                    <label>COLOUR</label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <input type="color" name="settings[archive_masonry_border_color]"
                               value="<?php echo htmlspecialchars($ambc); ?>"
                               style="width:48px; height:36px; padding:2px; border-radius:4px; cursor:pointer; border:1px solid var(--border,#333); background:transparent;">
                        <span style="font-family:monospace; font-size:0.85rem; color:var(--text-muted,#888);"><?php echo htmlspecialchars($ambc); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
