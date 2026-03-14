<?php
/**
 * SNAPSMACK - Social Profile Dock Configuration
 * Alpha v0.7.3
 *
 * Admin page for configuring the floating social profile links dock.
 * Toggle on/off, pick a position, customise colours, enter profile URLs.
 * The dock also absorbs the download button when downloads are active.
 */

require_once 'core/auth.php';

// --- FORM SUBMISSION ---
if (isset($_POST['save_social_dock'])) {
    $stmt = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?"
    );

    // Enabled toggle (checkbox: present=1, missing=0)
    $enabled = isset($_POST['social_dock_enabled']) ? '1' : '0';
    $stmt->execute(['social_dock_enabled', $enabled, $enabled]);

    // Position
    $position = $_POST['social_dock_position'] ?? 'bottom-right';
    $valid_positions = ['top-left','top-right','bottom-left','bottom-right','left-top','left-bottom','right-top','right-bottom'];
    if (!in_array($position, $valid_positions)) $position = 'bottom-right';
    $stmt->execute(['social_dock_position', $position, $position]);

    // Appearance settings
    $appearance_keys = [
        'social_dock_color_light' => '#ffffff',
        'social_dock_color_dark'  => '#1a1a1a',
        'social_dock_color_mode'  => 'light',
        'social_dock_shadow'      => '1',
        'social_dock_opacity'     => '50',
    ];
    foreach ($appearance_keys as $akey => $default) {
        $aval = trim($_POST[$akey] ?? $default);
        if ($akey === 'social_dock_color_mode' && !in_array($aval, ['light', 'dark'])) $aval = 'light';
        if ($akey === 'social_dock_shadow') $aval = isset($_POST[$akey]) ? '1' : '0';
        if ($akey === 'social_dock_opacity') $aval = max(0, min(100, (int)$aval));
        $stmt->execute([$akey, $aval, $aval]);
    }

    // Platform URLs
    $platforms = [
        'social_dock_flickr', 'social_dock_smugmug', 'social_dock_instagram',
        'social_dock_facebook', 'social_dock_youtube', 'social_dock_500px',
        'social_dock_vero', 'social_dock_threads', 'social_dock_bluesky',
        'social_dock_linkedin', 'social_dock_pinterest', 'social_dock_tumblr',
        'social_dock_deviantart', 'social_dock_behance', 'social_dock_linktree',
        'social_dock_website',
    ];

    foreach ($platforms as $key) {
        $val = trim($_POST[$key] ?? '');
        $stmt->execute([$key, $val, $val]);
    }

    $msg = "Social dock settings saved.";
}

// Load settings
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

$page_title = "Social Profile Dock";
include 'core/admin-header.php';
include 'core/sidebar.php';
?>

<div class="main">
    <div class="header-row">
        <h2>SOCIAL PROFILE DOCK</h2>
    </div>

    <?php if (isset($msg)): ?>
        <div class="alert">> <?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <form method="POST">

        <!-- ============================================================
             DOCK SETTINGS
             ============================================================ -->
        <div class="box">
            <h3>DOCK SETTINGS</h3>

            <div class="post-layout-grid">
                <div class="post-col-left">
                    <label>
                        <input type="checkbox" name="social_dock_enabled" value="1" <?php echo ($settings['social_dock_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                        ENABLE SOCIAL DOCK
                    </label>
                    <span class="dim" style="display: block; margin-top: 4px;">Floating profile links visible on every public page. When downloads are enabled for an image, the download button appears in the dock automatically.</span>
                </div>

                <div class="post-col-right">
                    <label>POSITION</label>
                    <?php
                    $current_pos = $settings['social_dock_position'] ?? 'bottom-right';
                    $positions = [
                        'Corners' => [
                            'top-left' => 'Top Left',
                            'top-right' => 'Top Right',
                            'bottom-left' => 'Bottom Left',
                            'bottom-right' => 'Bottom Right',
                        ],
                        'Side Edges (slides on scroll)' => [
                            'left-top' => 'Left Side — Top',
                            'left-bottom' => 'Left Side — Bottom',
                            'right-top' => 'Right Side — Top',
                            'right-bottom' => 'Right Side — Bottom',
                        ],
                    ];
                    ?>
                    <select name="social_dock_position">
                        <?php foreach ($positions as $group => $opts): ?>
                            <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                <?php foreach ($opts as $val => $label): ?>
                                    <option value="<?php echo $val; ?>" <?php echo $current_pos === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <span class="dim" style="display: block; margin-top: 4px;">Side positions slide out of view while scrolling.</span>
                </div>
            </div>
        </div>

        <!-- ============================================================
             APPEARANCE
             ============================================================ -->
        <div class="box">
            <h3>APPEARANCE</h3>
            <p class="dim">Each icon is rendered as a circle. Light colour is used for icon fills on dark backgrounds; dark colour for light backgrounds. The circle background automatically contrasts with the icon colour.</p>

            <div class="post-layout-grid">
                <div class="post-col-left">
                    <label>LIGHT ICON COLOUR</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="color" name="social_dock_color_light" value="<?php echo htmlspecialchars($settings['social_dock_color_light'] ?? '#ffffff'); ?>" style="width: 50px; height: 34px; border: 1px solid #ccc; cursor: pointer;" oninput="this.nextElementSibling.value = this.value">
                        <input type="text" value="<?php echo htmlspecialchars($settings['social_dock_color_light'] ?? '#ffffff'); ?>" style="width: 100px; font-family: monospace;" onchange="this.previousElementSibling.value = this.value" oninput="this.previousElementSibling.value = this.value">
                    </div>
                    <span class="dim">Icon colour and border tint for dark backgrounds.</span>

                    <label style="margin-top: 15px;">DARK ICON COLOUR</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="color" name="social_dock_color_dark" value="<?php echo htmlspecialchars($settings['social_dock_color_dark'] ?? '#1a1a1a'); ?>" style="width: 50px; height: 34px; border: 1px solid #ccc; cursor: pointer;" oninput="this.nextElementSibling.value = this.value">
                        <input type="text" value="<?php echo htmlspecialchars($settings['social_dock_color_dark'] ?? '#1a1a1a'); ?>" style="width: 100px; font-family: monospace;" onchange="this.previousElementSibling.value = this.value" oninput="this.previousElementSibling.value = this.value">
                    </div>
                    <span class="dim">Icon colour and border tint for light backgrounds.</span>

                    <label style="margin-top: 15px;">DEFAULT MODE</label>
                    <?php $current_mode = $settings['social_dock_color_mode'] ?? 'light'; ?>
                    <select name="social_dock_color_mode">
                        <option value="light" <?php echo $current_mode === 'light' ? 'selected' : ''; ?>>Light icons (for dark sites)</option>
                        <option value="dark" <?php echo $current_mode === 'dark' ? 'selected' : ''; ?>>Dark icons (for light sites)</option>
                    </select>
                    <span class="dim">Which colour set to use.</span>
                </div>

                <div class="post-col-right">
                    <label>
                        <input type="checkbox" name="social_dock_shadow" value="1" <?php echo ($settings['social_dock_shadow'] ?? '1') === '1' ? 'checked' : ''; ?>>
                        ICON DROP SHADOW
                    </label>
                    <span class="dim" style="display: block; margin-top: 4px;">Subtle shadow behind each circle for contrast against busy backgrounds.</span>

                    <label style="margin-top: 15px;">IDLE OPACITY</label>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <input type="range" name="social_dock_opacity" min="10" max="100" value="<?php echo htmlspecialchars($settings['social_dock_opacity'] ?? '50'); ?>" style="flex: 1;" oninput="this.nextElementSibling.textContent = this.value + '%'">
                        <span style="min-width: 40px; font-family: monospace;"><?php echo htmlspecialchars($settings['social_dock_opacity'] ?? '50'); ?>%</span>
                    </div>
                    <span class="dim">How visible the dock is when not being hovered. Hovering always reveals at full opacity.</span>
                </div>
            </div>
        </div>

        <!-- ============================================================
             PROFILE LINKS — 2-column grid
             ============================================================ -->
        <div class="box">
            <h3>PROFILE LINKS</h3>
            <p class="dim">Enter your profile URL for each platform. Leave blank to hide. No X/Twitter — by design.</p>

            <div class="post-layout-grid">
                <div class="post-col-left">

                    <label>FLICKR</label>
                    <input type="url" name="social_dock_flickr" value="<?php echo htmlspecialchars($settings['social_dock_flickr'] ?? ''); ?>" placeholder="https://www.flickr.com/photos/you">

                    <label>SMUGMUG</label>
                    <input type="url" name="social_dock_smugmug" value="<?php echo htmlspecialchars($settings['social_dock_smugmug'] ?? ''); ?>" placeholder="https://you.smugmug.com">

                    <label>INSTAGRAM</label>
                    <input type="url" name="social_dock_instagram" value="<?php echo htmlspecialchars($settings['social_dock_instagram'] ?? ''); ?>" placeholder="https://www.instagram.com/you">

                    <label>FACEBOOK</label>
                    <input type="url" name="social_dock_facebook" value="<?php echo htmlspecialchars($settings['social_dock_facebook'] ?? ''); ?>" placeholder="https://www.facebook.com/you">

                    <label>YOUTUBE</label>
                    <input type="url" name="social_dock_youtube" value="<?php echo htmlspecialchars($settings['social_dock_youtube'] ?? ''); ?>" placeholder="https://www.youtube.com/@you">

                    <label>500PX</label>
                    <input type="url" name="social_dock_500px" value="<?php echo htmlspecialchars($settings['social_dock_500px'] ?? ''); ?>" placeholder="https://500px.com/p/you">

                    <label>VERO</label>
                    <input type="url" name="social_dock_vero" value="<?php echo htmlspecialchars($settings['social_dock_vero'] ?? ''); ?>" placeholder="https://vero.co/you">

                    <label>THREADS</label>
                    <input type="url" name="social_dock_threads" value="<?php echo htmlspecialchars($settings['social_dock_threads'] ?? ''); ?>" placeholder="https://www.threads.net/@you">

                </div>

                <div class="post-col-right">

                    <label>BLUESKY</label>
                    <input type="url" name="social_dock_bluesky" value="<?php echo htmlspecialchars($settings['social_dock_bluesky'] ?? ''); ?>" placeholder="https://bsky.app/profile/you.bsky.social">

                    <label>LINKEDIN</label>
                    <input type="url" name="social_dock_linkedin" value="<?php echo htmlspecialchars($settings['social_dock_linkedin'] ?? ''); ?>" placeholder="https://www.linkedin.com/in/you">

                    <label>PINTEREST</label>
                    <input type="url" name="social_dock_pinterest" value="<?php echo htmlspecialchars($settings['social_dock_pinterest'] ?? ''); ?>" placeholder="https://www.pinterest.com/you">

                    <label>TUMBLR</label>
                    <input type="url" name="social_dock_tumblr" value="<?php echo htmlspecialchars($settings['social_dock_tumblr'] ?? ''); ?>" placeholder="https://you.tumblr.com">

                    <label>DEVIANTART</label>
                    <input type="url" name="social_dock_deviantart" value="<?php echo htmlspecialchars($settings['social_dock_deviantart'] ?? ''); ?>" placeholder="https://www.deviantart.com/you">

                    <label>BEHANCE</label>
                    <input type="url" name="social_dock_behance" value="<?php echo htmlspecialchars($settings['social_dock_behance'] ?? ''); ?>" placeholder="https://www.behance.net/you">

                    <label>LINKTREE</label>
                    <input type="url" name="social_dock_linktree" value="<?php echo htmlspecialchars($settings['social_dock_linktree'] ?? ''); ?>" placeholder="https://linktr.ee/you">

                    <label>PERSONAL WEBSITE</label>
                    <input type="url" name="social_dock_website" value="<?php echo htmlspecialchars($settings['social_dock_website'] ?? ''); ?>" placeholder="https://yoursite.com">

                </div>
            </div>
        </div>

        <button type="submit" name="save_social_dock" class="btn-smack" style="margin-top: 20px;">SAVE SOCIAL DOCK</button>

    </form>
</div>

<?php include 'core/admin-footer.php'; ?>
