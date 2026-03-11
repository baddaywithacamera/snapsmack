<?php
/**
 * SNAPSMACK - Public Footer Engine
 * Alpha v0.7.1
 *
 * Renders a configurable footer with 5 slots: copyright, email, theme name,
 * powered by, and RSS (always visible). Each slot can be ON (default content),
 * CUSTOM (user-defined text), or OFF (hidden). Separators are inserted
 * dynamically between visible slots only. Styling is driven by the active skin.
 */

// --- VERSION STRING ---
// Pull version constant if available, otherwise fall back to default
$version_str = defined('SNAPSMACK_VERSION') ? SNAPSMACK_VERSION : 'Alpha 0.7';

// --- SLOT CONTENT RESOLUTION ---
// Each slot resolves to a string or null. Null means the slot is hidden.
$slots = [];

// --- SLOT 1: COPYRIGHT ---
// Display current year and site name, or use custom text
$copy_mode = $settings['footer_slot_copyright'] ?? 'on';
if ($copy_mode === 'on') {
    $site_name = htmlspecialchars($settings['site_name'] ?? 'SnapSmack');
    $slots[] = "&copy; " . date("Y") . " " . $site_name;
} elseif ($copy_mode === 'custom') {
    $custom = trim($settings['footer_slot_copyright_custom'] ?? '');
    if ($custom !== '') {
        $slots[] = htmlspecialchars($custom);
    }
}

// --- SLOT 2: EMAIL ---
// Display contact email (reversed for spam protection) or custom text
$email_mode = $settings['footer_slot_email'] ?? 'on';
if ($email_mode === 'on') {
    $raw_email = $settings['site_email'] ?? '';
    if ($raw_email !== '') {
        $reversed = strrev($raw_email);
        $slots[] = 'EMAIL: <a href="mailto:' . htmlspecialchars($raw_email) . '" class="footer-link">'
                 . '<span class="reverse-email">'
                 . htmlspecialchars($reversed)
                 . '</span></a>';
    }
} elseif ($email_mode === 'custom') {
    $custom = trim($settings['footer_slot_email_custom'] ?? '');
    if ($custom !== '') {
        $slots[] = htmlspecialchars($custom);
    }
}

// --- SLOT 3: CURRENT THEME ---
// Display the active skin name, or use custom text
$theme_mode = $settings['footer_slot_theme'] ?? 'off';
if ($theme_mode === 'on') {
    $skin_slug = $settings['active_skin'] ?? 'unknown';
    $skin_name = str_replace('_', ' ', ucwords($skin_slug, '_'));
    // Try to get the friendly name from the manifest
    $skin_manifest_path = __DIR__ . '/../skins/' . $skin_slug . '/manifest.php';
    if (file_exists($skin_manifest_path)) {
        $skin_manifest_data = include $skin_manifest_path;
        if (isset($skin_manifest_data['name'])) {
            $skin_name = $skin_manifest_data['name'];
        }
    }
    $slots[] = 'THEME: ' . htmlspecialchars($skin_name);
} elseif ($theme_mode === 'custom') {
    $custom = trim($settings['footer_slot_theme_custom'] ?? '');
    if ($custom !== '') {
        $slots[] = htmlspecialchars($custom);
    }
}

// --- SLOT 4: POWERED BY ---
// Display SnapSmack branding, or use custom text
$powered_mode = $settings['footer_slot_powered'] ?? 'on';
if ($powered_mode === 'on') {
    $slots[] = 'POWERED BY SNAPSMACK ' . strtoupper($version_str);
} elseif ($powered_mode === 'custom') {
    $custom = trim($settings['footer_slot_powered_custom'] ?? '');
    if ($custom !== '') {
        $slots[] = htmlspecialchars($custom);
    }
}

// --- SLOT 5: RSS (ALWAYS ON) ---
// RSS feed link is always visible and cannot be disabled
$rss_url = (defined('BASE_URL') ? BASE_URL : '/') . 'feed';
$slots[] = '<a href="' . $rss_url . '" class="footer-link rss-tag" title="RSS Feed">RSS</a>';

// --- RENDERING ---
?>
<footer id="system-footer">
    <div class="inside">

        <?php
        /**
         * CHANNEL 1: SYSTEM INJECTION
         * Script tags injected by the JS handshake, kept separate from visual UI
         */
        if (!empty($settings['footer_injection_scripts'])):
            echo $settings['footer_injection_scripts'];
        endif;
        ?>

        <?php
        /**
         * CHANNEL 2: VISUAL FOOTER BAR
         * Slot content joined by pipe separators
         */
        ?>
        <div class="footer-metadata-bar">
            <p>
                <?php echo implode(' <span class="sep">|</span> ', $slots); ?>
            </p>
        </div>
    </div>
</footer>
