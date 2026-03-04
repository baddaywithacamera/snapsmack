<?php
/**
 * SNAPSMACK - Social Profile Dock Configuration
 * Alpha v0.8
 *
 * Admin page for configuring the floating social profile links dock.
 * Toggle on/off, pick a position, enter profile URLs for each platform.
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

    // Platform URLs
    $platforms = [
        'social_dock_flickr', 'social_dock_smugmug', 'social_dock_instagram',
        'social_dock_facebook', 'social_dock_youtube', 'social_dock_500px',
        'social_dock_vero', 'social_dock_threads', 'social_dock_bluesky',
        'social_dock_linkedin', 'social_dock_pinterest', 'social_dock_tumblr',
        'social_dock_deviantart', 'social_dock_behance', 'social_dock_website',
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
                    <span class="dim" style="display: block; margin-top: 4px;">Floating profile links visible on every public page.</span>
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
                            'left-top' => 'Left Side \u2014 Top',
                            'left-bottom' => 'Left Side \u2014 Bottom',
                            'right-top' => 'Right Side \u2014 Top',
                            'right-bottom' => 'Right Side \u2014 Bottom',
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

                    <label>PERSONAL WEBSITE</label>
                    <input type="url" name="social_dock_website" value="<?php echo htmlspecialchars($settings['social_dock_website'] ?? ''); ?>" placeholder="https://yoursite.com">

                </div>
            </div>
        </div>

        <button type="submit" name="save_social_dock" class="btn-smack" style="margin-top: 20px;">SAVE SOCIAL DOCK</button>

    </form>
</div>

<?php include 'core/admin-footer.php'; ?>
