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
if ($active_skin && file_exists("skins/{$active_skin}/manifest.php")) {
    $manifest = include "skins/{$active_skin}/manifest.php";
}

// Skin feature flags
$supports_drop_caps   = !empty($manifest['features']['supports_drop_caps']);
$supports_pull_quotes = !empty($manifest['features']['supports_pull_quotes']);

// --- POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_solo_appearance'])) {
    if (isset($_POST['settings']) && is_array($_POST['settings'])) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        foreach ($_POST['settings'] as $k => $v) {
            $stmt->execute([$k, $v, $v]);
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

        <!-- ── TYPOGRAPHY ─────────────────────────────────────────────── -->
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

                <?php if (!$supports_drop_caps && !$supports_pull_quotes): ?>
                <div class="lens-input-wrapper" style="grid-column: 1 / -1;">
                    <p class="dim" style="margin:0; padding:4px 0;">
                        ACTIVE SKIN DOES NOT DECLARE TYPOGRAPHY FEATURES. DROP CAPS AND PULL QUOTES WILL APPEAR HERE ONCE A SKIN ADDS <code>features.supports_drop_caps</code> OR <code>features.supports_pull_quotes</code> TO ITS MANIFEST.
                    </p>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- ── SAVE ───────────────────────────────────────────────────── -->
        <div style="margin-top:4px;">
            <button type="submit" name="save_solo_appearance" class="master-update-btn">SAVE SOLO APPEARANCE</button>
        </div>

    </div>
    </form>
</div>

<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
