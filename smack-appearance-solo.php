<?php
/**
 * SNAPSMACK - Solo Image Appearance
 *
 * Controls appearance and behaviour on individual post/image pages.
 * Covers EXIF display, download settings, and typography options
 * (drop caps and pull quotes — planned for 0.8.x).
 *
 * Moved here from smack-settings.php in v0.7.9f.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once 'core/auth-smack.php';

// --- MANIFEST (for skin-gated options) ---
$active_skin = $settings['active_skin'] ?? '';
$manifest    = [];
if ($active_skin && skin_manifest_exists($active_skin)) {
    $manifest = load_skin_manifest($active_skin);
}

// Skin feature flags
$supports_drop_caps   = !empty($manifest['features']['supports_drop_caps']);
$supports_pull_quotes = !empty($manifest['features']['supports_pull_quotes']);

// Skin manifest options flagged admin_page => 'solo' are OWNED by this page —
// controls whose effect is confined to the solo photo page (the only page with
// its own header). Cross-page controls stay under Smooth Your Skin. Rendered
// below; saved + recompiled by the POST handler.
$solo_manifest_opts = [];
if (is_array($manifest)) {
    foreach ($manifest['options'] ?? [] as $k => $o) {
        if (($o['admin_page'] ?? 'skin') === 'solo') {
            $solo_manifest_opts[$k] = $o;
        }
    }
}

// --- POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_solo_appearance'])) {
    if (isset($_POST['settings']) && is_array($_POST['settings'])) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        foreach ($_POST['settings'] as $k => $v) {
            $stmt->execute([$k, $v, $v]);
        }
        // Skin option values just landed in snap_settings, but the compiled skin
        // CSS blob is still stale. Recompile it here so the moved solo controls
        // take effect without a separate Smooth-Your-Skin re-save. Isolated copy
        // of the compile — smack-skin.php's own path is untouched.
        if (!empty($solo_manifest_opts) && $active_skin) {
            require_once __DIR__ . '/core/skin-css-recompile.php';
            snapsmack_recompile_public_skin_css($pdo, $active_skin);
        }
    }
    header("Location: smack-appearance-solo.php?msg=SAVED");
    exit;
}

$page_title = "Solo Image Appearance";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <h2>SOLO IMAGE APPEARANCE</h2>
    <p class="dim" style="margin-bottom:20px;">Controls how individual post pages look and behave — EXIF display, download behaviour, and typography.</p>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">> SOLO IMAGE APPEARANCE SAVED</div>
    <?php endif; ?>

    <form method="POST">
    <div id="smack-skin-config-wrap">

        <?php if (!empty($solo_manifest_opts)): ?>
        <?php
            // Load the Google Font catalogue so the font pickers + previews render.
            $solo_has_font = false;
            foreach ($solo_manifest_opts as $o) { if (!empty($o['is_font'])) { $solo_has_font = true; break; } }
            if ($solo_has_font) {
                $inv   = (function () { return include __DIR__ . '/core/manifest-inventory.php'; })();
                $gfams = is_array($inv) ? ($inv['fonts'] ?? []) : [];
                if (!empty($gfams)) {
                    $gp = [];
                    foreach (array_keys($gfams) as $fam) { $gp[] = 'family=' . str_replace(' ', '+', $fam) . ':wght@400;700'; }
                    echo '<link rel="stylesheet" href="' . htmlspecialchars('https://fonts.googleapis.com/css2?' . implode('&', $gp) . '&display=swap') . '">' . "\n";
                }
            }
        ?>
        <!-- ── SOLO CONTROLS (skin controls that only affect the solo photo page) ── -->
        <p class="dim" style="margin:0 0 14px;">These affect the individual photo page only — its own header, image treatment, and nav bar. Site-wide skin controls stay under Smooth Your Skin.</p>
        <?php
            // Group the solo controls by manifest section into their own sub-panels,
            // ordered HEADER → IMAGE → NAV → anything else.
            $solo_by_section = [];
            foreach ($solo_manifest_opts as $k => $o) {
                $solo_by_section[$o['section'] ?? 'SOLO LAYOUT'][] = ['key' => $k, 'meta' => $o];
            }
            $solo_order = ['SOLO HEADER', 'SOLO IMAGE', 'SOLO NAV', 'SOLO PAGE', 'SOLO LAYOUT'];
            uksort($solo_by_section, function ($a, $b) use ($solo_order) {
                $ia = array_search($a, $solo_order, true); $ia = ($ia === false) ? 999 : $ia;
                $ib = array_search($b, $solo_order, true); $ib = ($ib === false) ? 999 : $ib;
                return ($ia <=> $ib) ?: strcmp($a, $b);
            });
        ?>
        <?php foreach ($solo_by_section as $sec_title => $sec_opts): ?>
        <div class="box">
            <h3><?php echo htmlspecialchars($sec_title); ?></h3>
            <div class="config-grid">
            <?php foreach ($sec_opts as $item):
                $k = $item['key']; $o = $item['meta'];
                $val = ($settings[$k] ?? '') !== '' ? $settings[$k] : ($o['default'] ?? '');
            ?>
                <div class="lens-input-wrapper">
                    <label><?php echo strtoupper($o['label']); ?><?php if (!empty($o['hint'])): ?> <span class="field-tip" data-tip="<?php echo htmlspecialchars($o['hint']); ?>">ⓘ</span><?php endif; ?></label>
                    <?php if ($o['type'] === 'color'): ?>
                        <div class="color-picker-container">
                            <input type="color" name="settings[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($val ?: '#000000'); ?>"
                                   oninput="var s=this.nextElementSibling; if(s) s.innerText=this.value.toUpperCase();">
                            <span class="hex-display"><?php echo strtoupper(htmlspecialchars($val)); ?></span>
                        </div>
                    <?php elseif ($o['type'] === 'range'):
                        $u = strtoupper($o['unit'] ?? 'px');
                    ?>
                        <div class="range-wrapper">
                            <input type="range" name="settings[<?php echo $k; ?>]"
                                min="<?php echo htmlspecialchars($o['min']); ?>" max="<?php echo htmlspecialchars($o['max']); ?>"
                                step="<?php echo htmlspecialchars($o['step'] ?? '1'); ?>"
                                value="<?php echo htmlspecialchars($val); ?>"
                                oninput="this.nextElementSibling.innerText=this.value+'<?php echo $u; ?>'">
                            <span class="active-val"><?php echo strtoupper(htmlspecialchars($val)); ?><?php echo $u; ?></span>
                        </div>
                    <?php elseif ($o['type'] === 'select'):
                        $is_font    = (($o['property'] ?? '') === 'font-family') || !empty($o['is_font']);
                        $inherit_ok = ($o['default'] ?? '') === '';   // empty default => offer "Same as masthead"
                    ?>
                        <select name="settings[<?php echo $k; ?>]"
                            <?php if ($is_font): ?>onchange="var p=this.parentNode.querySelector('.font-preview span'); if(p) p.style.fontFamily=(this.value?(\"'\"+this.value+\"'\"):'inherit')+',sans-serif';"<?php endif; ?>>
                            <?php if ($is_font && $inherit_ok): ?>
                                <option value="" <?php echo ($val === '') ? 'selected' : ''; ?>><?php echo htmlspecialchars($o['inherit_label'] ?? 'Same as masthead'); ?></option>
                            <?php endif; ?>
                            <?php foreach (($o['options'] ?? []) as $sv => $sl): ?>
                                <option value="<?php echo htmlspecialchars($sv); ?>"
                                    <?php echo ((string)$val === (string)$sv) ? 'selected' : ''; ?>
                                    <?php if ($is_font): ?>style="font-family: '<?php echo htmlspecialchars($sv); ?>', sans-serif;"<?php endif; ?>>
                                    <?php echo htmlspecialchars(is_array($sl) ? ($sl['label'] ?? $sv) : $sl); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($is_font): ?>
                            <div class="font-preview" style="margin-top:8px;padding:10px 14px;background:rgba(128,128,128,0.08);border:1px solid rgba(128,128,128,0.2);border-radius:3px;">
                                <span style="display:block;font-family:<?php echo $val ? "'" . htmlspecialchars($val) . "'" : 'inherit'; ?>,sans-serif;font-size:16px;opacity:0.75;">The quick brown fox jumps over the lazy dog</span>
                            </div>
                            <?php if (empty($o['no_size_slider'])):
                                $sz_key = $k . '_size';
                                $sz     = $o['size'] ?? [];
                                $sz_u   = strtoupper($sz['unit'] ?? 'REM');
                                $sz_val = ($settings[$sz_key] ?? '') !== '' ? $settings[$sz_key] : ($sz['default'] ?? '1.0');
                            ?>
                            <div style="margin-top:12px;">
                                <label style="display:block;font-size:0.7rem;letter-spacing:1.5px;text-transform:uppercase;opacity:0.5;margin-bottom:6px;">Font Size (<?php echo strtolower($sz_u); ?>)</label>
                                <div class="range-wrapper">
                                    <input type="range" name="settings[<?php echo $sz_key; ?>]" min="0.6" max="2.4" step="0.05"
                                        value="<?php echo htmlspecialchars($sz_val); ?>"
                                        oninput="this.nextElementSibling.innerText=this.value+'<?php echo $sz_u; ?>'">
                                    <span class="active-val"><?php echo htmlspecialchars($sz_val); ?><?php echo $sz_u; ?></span>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- ── TECHNICAL DETAILS ────────────────────────────────────── -->
        <div class="box">
            <h3>TECHNICAL DETAILS</h3>
            <div class="config-grid">

                <div class="lens-input-wrapper">
                    <label>EXIF / TECHNICAL SPECS <span class="field-tip" data-tip="Hides the technical specifications panel from visitors. Data is still stored in the database.">ⓘ</span></label>
                    <select name="settings[exif_display_enabled]">
                        <option value="1" <?php echo (($settings['exif_display_enabled'] ?? '1') == '1') ? 'selected' : ''; ?>>SHOW ON PUBLIC POSTS</option>
                        <option value="0" <?php echo (($settings['exif_display_enabled'] ?? '1') == '0') ? 'selected' : ''; ?>>HIDDEN FROM PUBLIC</option>
                    </select>
                </div>

            </div>
        </div>

        <!-- ── DOWNLOADS ─────────────────────────────────────────────── -->
        <div class="box">
            <h3>DOWNLOADS</h3>
            <div class="config-grid">

                <div class="lens-input-wrapper">
                    <label>GLOBAL DOWNLOADS <span class="field-tip" data-tip="Master override. When disabled, no posts show download buttons regardless of per-post setting.">ⓘ</span></label>
                    <select name="settings[global_downloads_enabled]">
                        <option value="1" <?php echo (($settings['global_downloads_enabled'] ?? '0') == '1') ? 'selected' : ''; ?>>ENABLED</option>
                        <option value="0" <?php echo (($settings['global_downloads_enabled'] ?? '0') == '0') ? 'selected' : ''; ?>>DISABLED (KILL-SWITCH)</option>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>DEFAULT FOR NEW POSTS <span class="field-tip" data-tip="When set to All Posts, new posts default to download-enabled. You can still disable per-post.">ⓘ</span></label>
                    <select name="settings[download_default_mode]">
                        <option value="per_post" <?php echo (($settings['download_default_mode'] ?? 'per_post') == 'per_post') ? 'selected' : ''; ?>>PER-POST (MANUALLY ENABLE EACH POST)</option>
                        <option value="all_posts" <?php echo (($settings['download_default_mode'] ?? 'per_post') == 'all_posts') ? 'selected' : ''; ?>>ALL POSTS (DOWNLOADS ON BY DEFAULT)</option>
                    </select>
                </div>

                <div class="lens-input-wrapper">
                    <label>REQUIRE DOWNLOAD LINK? <span class="field-tip" data-tip="When enabled, posts cannot be published without a download URL. Use for sites where every image is backed by a Google Drive original.">ⓘ</span></label>
                    <select name="settings[download_link_required]">
                        <option value="0" <?php echo (($settings['download_link_required'] ?? '0') == '0') ? 'selected' : ''; ?>>NO (OPTIONAL)</option>
                        <option value="1" <?php echo (($settings['download_link_required'] ?? '0') == '1') ? 'selected' : ''; ?>>YES (BLOCK PUBLISH IF MISSING)</option>
                    </select>
                </div>

            </div>
        </div>

        <!-- ── TYPOGRAPHY (drop caps / pull quotes — shown ONLY when the skin declares
             those features; a skin's own fonts live in the SOLO panels above) ── -->
        <?php if ($supports_drop_caps || $supports_pull_quotes): ?>
        <div class="box">
            <h3>TYPOGRAPHY</h3>
            <div class="config-grid">

                <?php if ($supports_drop_caps): ?>
                <div class="lens-input-wrapper">
                    <label>DROP CAPS <span class="field-tip" data-tip="Enlarges the first letter of the first paragraph. Skin-supplied styling via CSS ::first-letter.">ⓘ</span></label>
                    <select name="settings[drop_caps_enabled]">
                        <option value="0" <?php echo (($settings['drop_caps_enabled'] ?? '0') == '0') ? 'selected' : ''; ?>>DISABLED</option>
                        <option value="1" <?php echo (($settings['drop_caps_enabled'] ?? '0') == '1') ? 'selected' : ''; ?>>ENABLED — FIRST PARAGRAPH</option>
                    </select>
                </div>
                <?php endif; ?>

                <?php if ($supports_pull_quotes): ?>
                <div class="lens-input-wrapper">
                    <label>PULL QUOTES <span class="field-tip" data-tip="Manual mode: wrap text in [pullquote]…[/pullquote] to pull it out. Auto mode pulls the first sentence of every post.">ⓘ</span></label>
                    <select name="settings[pull_quotes_enabled]">
                        <option value="0" <?php echo (($settings['pull_quotes_enabled'] ?? '0') == '0') ? 'selected' : ''; ?>>DISABLED</option>
                        <option value="manual" <?php echo (($settings['pull_quotes_enabled'] ?? '0') == 'manual') ? 'selected' : ''; ?>>MANUAL (USE [pullquote] SHORTCODE)</option>
                        <option value="auto" <?php echo (($settings['pull_quotes_enabled'] ?? '0') == 'auto') ? 'selected' : ''; ?>>AUTO-PULL FIRST SENTENCE</option>
                    </select>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php endif; ?>

        <!-- ── SAVE ───────────────────────────────────────────────────── -->
        <div style="margin-top:4px;">
            <button type="submit" name="save_solo_appearance" class="master-update-btn">SAVE SOLO APPEARANCE</button>
        </div>

    </div>
    </form>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
