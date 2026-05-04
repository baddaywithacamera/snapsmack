<?php
/**
 * SNAPSMACK - Static Page Appearance
 *
 * Controls layout and spacing for static pages (About, Contact, Blogroll, etc.).
 * Content width and gutter are skin-agnostic overrides — each skin has its own
 * default, but these settings win when set.
 *
 * Moved here from smack-globalvibe.php in v0.7.9f.
 */

require_once 'core/auth.php';

// --- POST HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_static_appearance'])) {
    if (isset($_POST['settings']) && is_array($_POST['settings'])) {
        $stmt = $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?");
        foreach ($_POST['settings'] as $k => $v) {
            $stmt->execute([$k, $v, $v]);
        }
    }
    header("Location: smack-appearance-static.php?msg=SAVED");
    exit;
}

$page_title = "Static Page Appearance";
include 'core/admin-header.php';
include 'core/sidebar.php';

$current_width  = (int)($settings['static_content_width']  ?? 850);
$current_gutter = (int)($settings['static_content_gutter'] ?? 40);
?>

<div class="main">
    <h2>STATIC PAGE APPEARANCE</h2>
    <p class="dim" style="margin-bottom:20px;">Layout and spacing for static pages (About, Contact, Blogroll, etc.). These override each skin's built-in defaults when set.</p>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">> STATIC PAGE APPEARANCE SAVED</div>
    <?php endif; ?>

    <form method="POST">
    <div id="smack-skin-config-wrap">

        <!-- ── PAGE LAYOUT ───────────────────────────────────────────── -->
        <div class="box">
            <h3>PAGE LAYOUT</h3>
            <div class="dash-grid">

                <div class="lens-input-wrapper">
                    <label>CONTENT WIDTH <span class="field-tip" data-tip="Maximum width of the text content column. Each skin has its own default when not set here.">ⓘ</span></label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <input type="range"
                               name="settings[static_content_width]"
                               min="400" max="1400" step="10"
                               value="<?php echo $current_width; ?>"
                               style="flex:1;"
                               oninput="this.nextElementSibling.textContent = this.value + 'px'">
                        <span style="min-width:52px; font-family:monospace;"><?php echo $current_width; ?>px</span>
                    </div>
                </div>

                <div class="lens-input-wrapper">
                    <label>SIDE GUTTERS <span class="field-tip" data-tip="Internal side padding — how far text sits from the edge of the content area.">ⓘ</span></label>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <input type="range"
                               name="settings[static_content_gutter]"
                               min="0" max="120" step="4"
                               value="<?php echo $current_gutter; ?>"
                               style="flex:1;"
                               oninput="this.nextElementSibling.textContent = this.value + 'px'">
                        <span style="min-width:52px; font-family:monospace;"><?php echo $current_gutter; ?>px</span>
                    </div>
                </div>

            </div>
        </div>

        <!-- ── SAVE ───────────────────────────────────────────────────── -->
        <div style="margin-top:4px;">
            <button type="submit" name="save_static_appearance" class="btn btn-primary">SAVE STATIC PAGE APPEARANCE</button>
        </div>

    </div>
    </form>
</div>

<?php include 'core/admin-footer.php'; ?>
// EOF
