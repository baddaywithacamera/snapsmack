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
// $settings is not populated until admin-header.php (included far below), so read the
// active skin from the DB here — otherwise admin_page:"archive" controls never collect.
$active_skin = '';
try {
    $active_skin = (string)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'active_skin' LIMIT 1")->fetchColumn() ?: '');
} catch (\Throwable $e) {
    $active_skin = '';
}
$manifest    = [];
if ($active_skin) {
    $manifest_path = "skins/{$active_skin}/manifest.json";
    if (file_exists($manifest_path)) {
        $manifest = snapsmack_load_manifest($manifest_path);
    }
}
// Skin manifest options flagged admin_page=>'archive' rendered here if manifest loaded.
$archive_manifest_opts = [];
// One thumbnail-size control, not two. Thumbnail size is presentation, so a skin
// that ships its own thumbnail-size control (one that emits the shared
// --ss-thumb-size variable, e.g. NEW HORIZON's archive_thumb_size from SS-1185)
// owns it; the global Archive Appearance thumb_size control is HIDDEN on that
// skin so the owner sees exactly one. This is manifest-driven -- it reads what
// the active skin declares, never a skin-name list -- and the CMS does NOT feed
// its thumb_size into any skin variable; it only decides whether to show its own
// control. A skin with no such control keeps the global one unchanged.
$skin_has_own_thumb_size = false;
if (is_array($manifest)) {
    foreach ($manifest['options'] ?? [] as $o) {
        if (is_array($o) && ($o['property'] ?? '') === '--ss-thumb-size') {
            $skin_has_own_thumb_size = true;
            break;
        }
    }
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

        // Normalise default layout to thumbs|masonry, or 'none' (archive
        // disabled). 'none' MUST survive: the UI offers a Disabled option and
        // core (header.php, gram-nav-links.php, archive.php) treats 'none' as
        // the off switch — before this it was forced back to 'thumbs', so the
        // Disabled choice silently did nothing.
        $_def = $_POST['settings']['archive_layout'] ?? 'thumbs';
        if (in_array($_def, ['square', 'cropped', 'croppedwithcalendar'], true)) $_def = 'thumbs';
        if (!in_array($_def, ['thumbs', 'masonry', 'none'], true)) $_def = 'thumbs';
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

        // 988D: the GUTTER slider always posts its position (default 4) even when
        // the owner never dragged it. Persisting 4 made a value nobody chose
        // override the skin's own gap (TRUE GRIT looked crammed). 4 means "use the
        // skin's spacing", so at 4 we DELETE the key -- resetting to the skin
        // default -- rather than storing a phantom override. Any other value is a
        // real choice and is stored normally. (See snapsmack_owner_gutter_px.)
        if (array_key_exists('archive_gutter', $_POST['settings'])
            && (string)$_POST['settings']['archive_gutter'] === '4'
            && (string)($_POST['settings']['archive_gutter_mode'] ?? '') !== 'custom') {
            $pdo->prepare("DELETE FROM snap_settings WHERE setting_key = 'archive_gutter'")->execute();
            unset($_POST['settings']['archive_gutter']);
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
        //
        // The rule MUST land OUTSIDE the /* SKIN_START */ ... /* SKIN_END */ region.
        // core/skin-css-recompile.php regenerates that whole region from manifest
        // state and keeps only what sits outside it, so a rule written INSIDE it is
        // silently deleted on the next skin recompile -- the owner's archive frame
        // vanished while Archive Appearance still showed the preset selected. So we
        // drop any prior copy wherever it sits (old builds wrote it before SKIN_END,
        // i.e. inside the region) and re-place it AFTER /* SKIN_END */, where
        // recompile leaves it alone. Existing sites heal on their next archive save.
        $upsert_rule = function(string &$blob, string $marker, string $rule): void {
            $blob = preg_replace('/' . preg_quote($marker, '/') . '[^\n]*\n?/', '', (string)$blob);
            $blob = rtrim($blob);
            // An empty rule removes the CMS preset without touching skin CSS.
            if ($rule === '') return;
            if (strpos($blob, '/* SKIN_END */') !== false) {
                $blob = str_replace('/* SKIN_END */', '/* SKIN_END */' . "\n" . $rule, $blob);
            } else {
                $blob .= ($blob !== '' ? "\n" : '') . $rule;
            }
        };

        // Thumbnail frame preset. (987D -- the frame is drawn on a ::after OVERLAY
        // that sits ON TOP of the thumbnail image, because that is the only place
        // it is actually visible. Measured on Sean's live /archive: an inset
        // box-shadow on the tile element (986D) paints UNDER the opaque image that
        // fills the tile, and an outside box-shadow (pre-986D) is clipped by the
        // aspect-ratio crop box. The overlay is pointer-events:none and inset:0 --
        // edge-aligned, on top of the photo -- so every preset reads as a line at
        // the image edge, never clipped, never under the photo, and the photo's
        // geometry is untouched. A real-browser pixel test guards it.
        //
        // One inset box-shadow per preset now (the overlay is the same shape for
        // grid and justified). out_ and in_ both read as a line at the edge; the
        // pre-987D outside/inside distinction could not survive the clip/under-image
        // problem and was never reliably visible. Double frame keeps 3/8/9px.
        $thumb_frame_shadows = [
            'none'          => 'none',
            'out_1px_grey'  => 'inset 0 0 0 1px #666666',
            'out_1px_white' => 'inset 0 0 0 1px #ffffff',
            'out_1px_dark'  => 'inset 0 0 0 1px #333333',
            'out_3px_grey'  => 'inset 0 0 0 3px #666666',
            'out_3px_white' => 'inset 0 0 0 3px #ffffff',
            'in_1px_grey'   => 'inset 0 0 0 1px #666666',
            'in_1px_white'  => 'inset 0 0 0 1px #ffffff',
            'in_3px_grey'   => 'inset 0 0 0 3px #666666',
            'in_3px_white'  => 'inset 0 0 0 3px #ffffff',
            'dbl_grey'      => 'inset 0 0 0 3px #666666, inset 0 0 0 8px #0d0d0d, inset 0 0 0 9px #666666',
            'dbl_white'     => 'inset 0 0 0 3px #d4d4d4, inset 0 0 0 8px #0d0d0d, inset 0 0 0 9px #d4d4d4',
            'dbl_dark'      => 'inset 0 0 0 3px #333333, inset 0 0 0 8px #0d0d0d, inset 0 0 0 9px #333333',
        ];
        $tfp = $_POST['settings']['archive_thumb_frame'] ?? 'none';
        if (!isset($thumb_frame_shadows[$tfp])) $tfp = 'none';
        $frameShadow = $thumb_frame_shadows[$tfp];
        // WHICH ARCHIVES THIS CAN REACH, measured 6 November 2026 by rendering
        // all 30 skins' archives in square, cropped and masonry and asking the
        // DOM. It reaches FIVE: 50 SHADES and CHAPLIN via .fsog-archive-item,
        // CHAPLIN, IMPACT PRINTER, NEW HORIZON and TRUE GRIT via
        // .square-grid/.cropped-grid .thumb-link. On the other 25 skins this
        // rule matches nothing, which is why the tip below names them.
        //
        // .rg-archive-item .rg-thumb IS KEPT AND MATCHES NOTHING TODAY. No
        // layout and no page component emits rg-archive-item -- RATIONAL GEO
        // styles that class in its own stylesheet and does not render it,
        // which is also on the SS-1211 shortlist. It stays because removing it
        // would quietly drop the owner's border the day that skin starts
        // emitting the class again, and a selector that matches nothing costs
        // nothing. It is recorded here rather than left to be rediscovered.
        $grid_sel   = '.square-grid .thumb-link, .cropped-grid .thumb-link, .fsog-archive-item .fsog-thumb, .rg-archive-item .rg-thumb';
        // The ::after selector list, one per grid selector.
        $grid_after = implode(', ', array_map(
            static fn(string $s): string => trim($s) . '::after',
            explode(',', $grid_sel)
        ));
        // The overlay: a transparent layer on top of the image, clicks pass
        // through, the frame drawn as the chosen inset shadow. box-sizing is set
        // so a future border-based preset would also stay inside the edge.
        $overlay = "content: '' !important; position: absolute !important; inset: 0 !important; "
                 . "pointer-events: none !important; box-sizing: border-box !important; box-shadow: {$frameShadow} !important;";
        // GRID: the tile is static, so it needs a positioning context for the
        // overlay. position:relative does not move an in-flow element, so photo
        // geometry is unchanged.
        $grid_rule = "/* arch_opt:grid_border */ {$grid_sel} { position: relative !important; } {$grid_after} { {$overlay} }";
        // JUSTIFIED/MASONRY: the wall engine positions each item ABSOLUTELY, so it
        // is already a containing block for its ::after -- do NOT set position here
        // or the wall layout breaks. Just the overlay.
        $masonry_rule = "/* arch_opt:masonry_border */ #justified-grid .justified-item::after { {$overlay} }";
        // None means no CMS frame declarations, including positioning/overlays.
        // Remove both old marker rules so the skin's own shadow remains intact.
        if ($tfp === 'none') {
            $grid_rule = '';
            $masonry_rule = '';
        }
        $upsert_rule($blob, '/* arch_opt:grid_border */', $grid_rule);
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
// 'none' (archive disabled) is a valid state — keep it so the Disabled option
// stays selected; only unknown values fall back to thumbs.
if ($current_layout !== 'none' && !isset($all_layouts[$current_layout])) $current_layout = 'thumbs';

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
    <p class="dim mb-20">Controls how your image library looks to visitors. Layout mode, spacing, borders, and the floating gallery — your call, not the skin's.</p>

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
                            DISABLED (NO ARCHIVE — REMOVED FROM NAV &amp; MENU MANAGER)
                        </option>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <?php
                    /* IT DOES NOT APPLY TO WHICHEVER SKIN IS ACTIVE, AND SAYING SO WAS THE FAULT.
                     *
                     * The old tip ended "Applies to whichever skin is active." Measured on
                     * 6 November 2026 against all thirty installed skins, that is wrong for
                     * twenty-seven of them.
                     *
                     * METHOD, so this can be rechecked rather than believed: every skin is
                     * schema_version 2, so every skin renders through its own layout or its
                     * whole-page component and NONE falls through to archive.php -- which
                     * means archive.php's own thumb-style branch (the `$grid_layout =
                     * $archive_thumb_style` at archive.php:232) cannot reach any skin in the
                     * current fleet. A skin therefore honours this setting only if its own
                     * layout reads the prepared value. Grepping each layout plus the page
                     * component it calls for `thumb_style` and `archive_grid`:
                     *
                     *   HONOUR IT: 50 SHADES, CHAPLIN, IMPACT PRINTER.
                     *   TRUE GRIT reads archive_grid and then hardcodes square-grid, so the
                     *     setting does nothing there. That is SS-1043.
                     *   NEW HORIZON hardcodes square-grid and reads neither value.
                     *   HIP-TO-BE-SQUARE is square by design -- the owner ruled on
                     *     6 November that it stays square and is documented, not fixed.
                     *   The remaining skins do not read it at all.
                     *
                     * WHY THIS IS A SENTENCE AND NOT A HIDDEN CONTROL. The coordinator asked
                     * for either wording or hiding the setting where it does nothing. Hiding
                     * needs a capability to test, and there is none: features.thumbs_supported
                     * defaults to true and NO skin sets it false, so the only flag that could
                     * gate this says all thirty support thumbs. Adding a real flag means
                     * touching thirty manifests and bumping thirty skin versions, which is a
                     * release, not a tooltip. Ticketed separately.
                     *
                     * The named list will rot the day a skin starts or stops reading the
                     * value -- I have just spent a day fixing a test that hardcoded a skin
                     * name -- so the date and the method are in the tip itself, which is the
                     * best a static string can do.
                     */
                    ?>
                    <label>THUMB STYLE <span class="field-tip" data-tip="In the Thumbs layout: NATURAL ASPECT keeps each photograph&#039;s own proportions, so tiles differ in height. SQUARE CROP crops every photograph to a 1:1 tile, so the grid is even.&#10;&#10;IT DOES NOT APPLY TO EVERY SKIN. It only changes anything on a skin whose archive reads this setting. Measured 6 Nov 2026 by rendering every skin both ways, that is 7 skins: 50 SHADES, CHAPLIN, GLIDE, IMPACT PRINTER, NEW HORIZON, ONYX and TRUE GRIT. GLIDE, NEW HORIZON and TRUE GRIT began honouring it in this build. HIP-TO-BE-SQUARE is square by design and ignores it deliberately. The other 23 skins do not read it, so changing it will look like nothing happened -- because nothing did.">ⓘ</span></label>
                    <select name="settings[archive_thumb_style]">
                        <option value="cropped" <?php echo $thumb_style === 'cropped' ? 'selected' : ''; ?>>NATURAL ASPECT (DEFAULT)</option>
                        <option value="square"  <?php echo $thumb_style === 'square'  ? 'selected' : ''; ?>>SQUARE CROP (1:1)</option>
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

                <?php if (!$skin_has_own_thumb_size): ?>
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
                <?php else: ?>
                <div class="lens-input-wrapper">
                    <label>THUMBNAIL SIZE <span class="field-tip" data-tip="This skin has its own thumbnail-size control in Skin Settings, which is used instead.">ⓘ</span></label>
                    <p class="field-note">Set in this skin's own Skin Settings &mdash; shown there to keep one control.</p>
                </div>
                <?php endif; ?>

                <div class="lens-input-wrapper">
                    <label>COLUMNS <span class="field-tip" data-tip="How many columns across on desktop. Applies to square and cropped grid modes.">ⓘ</span></label>
                    <div class="flex-mid gap-12">
                        <input type="range" name="settings[browse_cols]"
                               min="2" max="8" step="1"
                               value="<?php echo $current_cols; ?>"
                               oninput="this.nextElementSibling.textContent = this.value">
                        <span style="min-width:24px; font-family:monospace;"><?php echo $current_cols; ?></span>
                    </div>
                </div>

                <div class="lens-input-wrapper">
                    <label>GUTTER <span class="field-tip" data-tip="Gap between grid tiles. Your value overrides the skin's own spacing on the 12 skins that read it: AURORA, GAME ON, HEURISTIC, IMPACT PRINTER, INSTANT CAMERA, JIVE TURKEY, NEW HORIZON, PARADE, SLIDERS, SUDDEN IMPACT, THE GRID and TRUE GRIT. STANLEY, TELEGRAM, TILEZ and WRITING WITH IMPACT fix their own gaps in their stylesheets, so this will not change them. Saving this page sends the slider's current position, so this takes effect from the first save even if you did not move it.">ⓘ</span></label>
                    <div class="flex-mid gap-12">
                        <input type="range" name="settings[archive_gutter]"
                               min="0" max="40" step="2"
                               value="<?php echo $current_gutter; ?>"
                               oninput="this.nextElementSibling.textContent = this.value + 'px'">
                        <span style="min-width:36px; font-family:monospace;"><?php echo $current_gutter; ?>px</span>
                    </div>
                </div>

                <div class="lens-input-wrapper">
                    <label>JUSTIFIED ROW HEIGHT <span class="field-tip" data-tip="Target row height for masonry/justified mode. Rows expand slightly to fill width.">ⓘ</span></label>
                    <div class="flex-mid gap-12">
                        <input type="range" name="settings[justified_row_height]"
                               min="120" max="500" step="10"
                               value="<?php echo $current_row_h; ?>"
                               oninput="this.nextElementSibling.textContent = this.value + 'px'">
                        <span style="min-width:44px; font-family:monospace;"><?php echo $current_row_h; ?>px</span>
                    </div>
                </div>

                <div class="lens-input-wrapper">
                    <label>MASONRY IMAGE SIZE <span class="field-tip" data-tip="Full size loads original images — sharpest quality, slower on large archives. Medium uses the pre-generated aspect thumbnails — faster load, recommended for most sites.">ⓘ</span></label>
                    <select name="settings[masonry_use_thumbs]">
                        <option value="1" <?php echo  $masonry_use_thumbs ? 'selected' : ''; ?>>MEDIUM (~<?php echo (int)(defined('SNAPSMACK_THUMB_ASPECT_LONG') ? SNAPSMACK_THUMB_ASPECT_LONG : 900); ?>px THUMBNAILS) — RECOMMENDED</option>
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
                    <div class="flex-mid gap-12">
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

    <!-- ── ARCHIVE THUMB BORDERS ─────────────────────────────────────────── -->
    <div id="smack-skin-config-wrap">
        <?php
        $current_thumb_frame = $settings['archive_thumb_frame'] ?? 'none';
        $thumb_frame_opts = [
            'none'         => 'No Border',
            'out_1px_grey'  => '1px Outside — Grey',
            'out_1px_white' => '1px Outside — White',
            'out_1px_dark'  => '1px Outside — Dark',
            'out_3px_grey'  => '3px Outside — Grey',
            'out_3px_white' => '3px Outside — White',
            'in_1px_grey'   => '1px Inside — Grey',
            'in_1px_white'  => '1px Inside — White',
            'in_3px_grey'   => '3px Inside — Grey',
            'in_3px_white'  => '3px Inside — White',
            'dbl_grey'      => 'Double Frame Grey (3px + gap + 1px)',
            'dbl_white'     => 'Double Frame White (3px + gap + 1px)',
            'dbl_dark'      => 'Double Frame Dark (3px + gap + 1px)',
        ];
        ?>
        <div class="box">
            <h3>THUMBNAIL FRAME <span class="field-tip" data-tip="Border around archive thumbnails on the 5 skins whose archives this can reach: 50 SHADES OF NOAH GREY, CHAPLIN, IMPACT PRINTER, NEW HORIZON and TRUE GRIT. On the other 25 nothing will happen. IMPACT PRINTER is reached in square and cropped but not masonry. Whether it reaches masonry on a live page has not been measured on any skin.">ⓘ</span></h3>
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>FRAME STYLE</label>
                    <select name="settings[archive_thumb_frame]">
                        <?php foreach ($thumb_frame_opts as $tfk => $tfl): ?>
                            <option value="<?php echo $tfk; ?>"<?php echo ($current_thumb_frame === $tfk) ? ' selected' : ''; ?>><?php echo $tfl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

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
