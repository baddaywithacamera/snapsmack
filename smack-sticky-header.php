<?php
/**
 * SNAPSMACK - Sticky Header Configuration
 * Alpha v0.8
 *
 * Admin page for enabling/configuring the sticky header engine.
 * Header sticks to top on scroll, goes transparent, returns opaque on hover.
 */

require_once 'core/auth.php';

// --- FORM SUBMISSION ---
if (isset($_POST['save_sticky_header'])) {
    $stmt = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?"
    );

    // Enabled toggle
    $enabled = isset($_POST['sticky_header_enabled']) ? '1' : '0';
    $stmt->execute(['sticky_header_enabled', $enabled, $enabled]);

    // Opacity (0-100 → stored as integer, converted to 0-1 by PHP template)
    $opacity = max(0, min(100, (int)($_POST['sticky_header_opacity'] ?? 12)));
    $stmt->execute(['sticky_header_opacity', $opacity, $opacity]);

    // Blur (px)
    $blur = max(0, min(30, (int)($_POST['sticky_header_blur'] ?? 14)));
    $stmt->execute(['sticky_header_blur', $blur, $blur]);

    $msg = "Sticky header settings saved.";
}

// Load settings
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

$page_title = "Sticky Header";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>STICKY HEADER</h2>
    </div>

    <?php if (isset($msg)): ?>
        <div class="alert">> <?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <form method="POST">

        <div class="box">
            <h3>STICKY HEADER SETTINGS</h3>

            <div class="post-layout-grid">
                <div class="post-col-left">
                    <label>
                        <input type="checkbox" name="sticky_header_enabled" value="1" <?php echo ($settings['sticky_header_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                        ENABLE STICKY HEADER
                    </label>
                    <span class="dim" style="display: block; margin-top: 4px;">Header stays pinned to the top when you scroll down. Goes transparent while idle, snaps back opaque on hover.</span>

                    <p class="dim" style="margin-top: 15px;">Skins with their own fixed headers (like Pocket Operator) are automatically excluded.</p>
                </div>

                <div class="post-col-right">
                    <label>BACKGROUND OPACITY (TRANSPARENT STATE)</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="range" name="sticky_header_opacity" min="0" max="100" value="<?php echo htmlspecialchars($settings['sticky_header_opacity'] ?? '12'); ?>" style="flex: 1;" oninput="this.nextElementSibling.textContent = this.value + '%'">
                        <span style="min-width: 40px; font-family: monospace;"><?php echo htmlspecialchars($settings['sticky_header_opacity'] ?? '12'); ?>%</span>
                    </div>
                    <span class="dim">How opaque the header backdrop is while scrolled. 0% = fully see-through, 100% = fully opaque.</span>

                    <label style="margin-top: 15px;">BACKDROP BLUR</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="range" name="sticky_header_blur" min="0" max="30" value="<?php echo htmlspecialchars($settings['sticky_header_blur'] ?? '14'); ?>" style="flex: 1;" oninput="this.nextElementSibling.textContent = this.value + 'px'">
                        <span style="min-width: 40px; font-family: monospace;"><?php echo htmlspecialchars($settings['sticky_header_blur'] ?? '14'); ?>px</span>
                    </div>
                    <span class="dim">Glass-morphism blur amount. Higher = more frosted glass effect. 0 = no blur.</span>
                </div>
            </div>
        </div>

        <button type="submit" name="save_sticky_header" class="btn-smack" style="margin-top: 20px;">SAVE STICKY HEADER</button>

    </form>
</div>

<?php include 'core/admin-footer.php'; ?>
