<?php
/**
 * SnapSmack - Central Footer Engine
 * Version: 3.0 - Slot Architecture
 * -------------------------------------------------------------------------
 * Slot-based configurable footer. Each slot (1-4) supports three states:
 *   ON     — renders default content
 *   CUSTOM — renders user-defined text
 *   OFF    — slot is hidden
 *
 * Slot 5 (RSS) is permanently visible and cannot be disabled.
 *
 * Separators (|) are inserted dynamically between visible slots only.
 *
 * Settings keys consumed:
 *   footer_slot_copyright       (on/custom/off)
 *   footer_slot_copyright_custom (text)
 *   footer_slot_email           (on/custom/off)
 *   footer_slot_email_custom    (text)
 *   footer_slot_theme           (on/custom/off)
 *   footer_slot_theme_custom    (text)
 *   footer_slot_powered         (on/custom/off)
 *   footer_slot_powered_custom  (text)
 *   site_email                  (used by email slot default)
 *   site_name                   (used by copyright slot default)
 *   active_skin                 (used by theme slot default)
 *
 * Font and size are skin-manifest driven (footer_font_family,
 * footer_font_size) and applied via the skin CSS compilation —
 * this file outputs structure only, no inline styles.
 * -------------------------------------------------------------------------
 */

// Pull version constant if available, otherwise fall back.
$version_str = defined('SNAPSMACK_VERSION') ? SNAPSMACK_VERSION : 'Alpha 0.6';

// =========================================================================
// 1. SLOT CONTENT RESOLUTION
// =========================================================================
// Each slot resolves to a string or null. Null = hidden.

$slots = [];

// --- SLOT 1: COPYRIGHT ---
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
$email_mode = $settings['footer_slot_email'] ?? 'on';
if ($email_mode === 'on') {
    $raw_email = $settings['site_email'] ?? '';
    if ($raw_email !== '') {
        $reversed = strrev($raw_email);
        $slots[] = 'EMAIL: <a href="mailto:' . htmlspecialchars($raw_email) . '" class="footer-link">'
                 . '<span class="reverse-email" style="unicode-bidi:bidi-override; direction:rtl;">'
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
$theme_mode = $settings['footer_slot_theme'] ?? 'off';
if ($theme_mode === 'on') {
    $skin_slug = $settings['active_skin'] ?? 'unknown';
    $skin_name = str_replace('_', ' ', ucwords($skin_slug, '_'));
    // Try to get the friendly name from the manifest.
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
$rss_url = (defined('BASE_URL') ? BASE_URL : '/') . 'feed';
$slots[] = '<a href="' . $rss_url . '" class="footer-link rss-tag" title="RSS Feed">RSS</a>';


// =========================================================================
// 2. RENDER
// =========================================================================
?>
<footer id="system-footer">
    <div class="inside">

        <?php
        /**
         * CHANNEL 1: SYSTEM INJECTION
         * Script tags injected by the JS handshake. Kept separate from visual UI.
         */
        if (!empty($settings['footer_injection_scripts'])):
            echo $settings['footer_injection_scripts'];
        endif;
        ?>

        <?php
        /**
         * CHANNEL 2: VISUAL FOOTER BAR
         * Slot content joined by pipe separators.
         */
        ?>
        <div class="footer-metadata-bar">
            <p>
                <?php echo implode(' <span class="sep">|</span> ', $slots); ?>
            </p>
        </div>
    </div>
</footer>
