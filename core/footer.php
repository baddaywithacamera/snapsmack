<?php
/**
 * SNAPSMACK - Public Footer Engine
 *
 * Renders a configurable footer with 5 slots: copyright, email, theme name,
 * powered by, and RSS (always visible). Each slot can be ON (default content),
 * CUSTOM (user-defined text), or OFF (hidden). Separators are inserted
 * dynamically between visible slots only. Styling is driven by the active skin.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
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
    $home_url = defined('BASE_URL') ? BASE_URL : '/';
    $slots[] = "&copy; " . date("Y") . ' <a class="p-name u-url footer-link" href="'
             . htmlspecialchars($home_url) . '">' . $site_name . '</a>';
} elseif ($copy_mode === 'custom') {
    $custom = trim($settings['footer_slot_copyright_custom'] ?? '');
    if ($custom !== '') {
        // {year} auto-fills the current year so a custom copyright line never
        // goes stale; {site_name} fills the configured site name. Tokens are
        // substituted before escaping, so the result is still safe.
        $custom = strtr($custom, [
            '{year}'      => date('Y'),
            '{site_name}' => $settings['site_name'] ?? 'SnapSmack',
        ]);
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
$theme_mode = $settings['footer_slot_theme'] ?? 'on';
if ($theme_mode === 'on') {
    $skin_slug = $settings['active_skin'] ?? 'unknown';
    $skin_name = str_replace('_', ' ', ucwords($skin_slug, '_'));
    // Try to get the friendly name from the manifest
    $skin_manifest_path = __DIR__ . '/../skins/' . $skin_slug . '/manifest.json';
    if (file_exists($skin_manifest_path)) {
        try {
            $skin_manifest_data = snapsmack_load_manifest($skin_manifest_path);
            if (is_array($skin_manifest_data) && isset($skin_manifest_data['name'])) {
                $skin_name = $skin_manifest_data['name'];
            }
        } catch (\Throwable $e) {
            error_log("SnapSmack: failed to load manifest {$skin_manifest_path} — " . $e->getMessage());
        }
    }
    // Theme names always render in all caps (SnapSmack convention).
    $slots[] = 'THEME: ' . htmlspecialchars(mb_strtoupper($skin_name, 'UTF-8'));
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
    $slots[] = 'POWERED BY <a href="https://snapsmack.ca" target="_blank" rel="nofollow noopener" class="footer-link">SNAPSMACK</a> ' . strtoupper($version_str);
} elseif ($powered_mode === 'custom') {
    $custom = trim($settings['footer_slot_powered_custom'] ?? '');
    if ($custom !== '') {
        $slots[] = htmlspecialchars($custom);
    }
}

// --- SLOT 5: PRIVACY POLICY ---
// Only shown when enabled in smack-privacy.php
if (!empty($settings['privacy_policy_enabled']) && $settings['privacy_policy_enabled'] === '1') {
    $pp_label = htmlspecialchars($settings['privacy_policy_title'] ?? 'Privacy Policy');
    $pp_url   = (defined('BASE_URL') ? BASE_URL : '/') . 'privacy-policy.php';
    $slots[]  = '<a href="' . $pp_url . '" class="footer-link">' . $pp_label . '</a>';
}

// --- SLOT 6: RSS (ALWAYS ON) ---
// SMACKTHEMUP shares public work through the visitor's own mail application.
// No address, message, or tracking data is sent to SnapSmack.
if (($settings['site_mode'] ?? '') === 'smackthemup') {
    $_stu_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $_stu_shareable = ($_stu_script === 'index.php' && !empty($requested_slug ?? ''))
        || ($_stu_script === 'archive.php' && (!empty($_GET['album']) || !empty($_GET['category'])))
        || $_stu_script === 'collection.php';
    if ($_stu_shareable) {
        $_stu_title = trim(strip_tags((string)($page_title ?? $site_name ?? 'Photograph')));
        // BASE_URL is the configured canonical origin. Never derive share links
        // from the request Host header, which is visitor-controlled on some hosts.
        $_stu_url = rtrim((string)BASE_URL, '/') . '/' . ltrim((string)($_SERVER['REQUEST_URI'] ?? '/'), '/');
        $_stu_mailto = 'mailto:?subject=' . rawurlencode($_stu_title) . '&body=' . rawurlencode($_stu_title . "\n" . $_stu_url);
        $slots[] = '<a href="' . htmlspecialchars($_stu_mailto, ENT_QUOTES) . '" class="footer-link stu-email-this">EMAIL THIS</a>';
        $_stu_share_text = rawurlencode($_stu_title . ' ' . $_stu_url);
        $slots[] = '<a href="https://bsky.app/intent/compose?text=' . $_stu_share_text . '" class="footer-link stu-share-bsky" target="_blank" rel="noopener noreferrer">SHARE TO BLUESKY</a>';
        $slots[] = '<a href="https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($_stu_url) . '" class="footer-link stu-share-facebook" target="_blank" rel="noopener noreferrer">SHARE TO FACEBOOK</a>';
        $slots[] = '<a href="' . htmlspecialchars($_stu_url, ENT_QUOTES) . '" role="button" class="footer-link stu-copy-link" data-share-url="' . htmlspecialchars($_stu_url, ENT_QUOTES) . '">COPY LINK</a>';
    }
    unset($_stu_script,$_stu_shareable,$_stu_title,$_stu_url,$_stu_mailto,$_stu_share_text);
}

// --- SLOT 7: RSS (ALWAYS ON) ---
// RSS feed link is always visible and cannot be disabled
$rss_url = (defined('BASE_URL') ? BASE_URL : '/') . 'feed';
$slots[] = '<a href="' . $rss_url . '" class="footer-link rss-tag" title="RSS Feed">RSS</a>';

// --- FOOTER STYLING: OWNED ENTIRELY BY THE ACTIVE SKIN'S STYLESHEET ---
// All skin styling comes from the skin stylesheet, period. Core emits NO styles
// for the footer here — no inline style attribute, no <style> block, not even a
// CSS custom-property value. Any of those plants a landmine: someone forking a
// skin reads their own style.css, sees the footer styled there, and can neither
// see nor override a declaration core injected behind their back.
//
// Core's ONLY job is markup + a state-hook CLASS the skin stylesheet targets
// (.footer-lowercase). The footer background colour is a per-skin control (e.g.
// JIVE TURKEY's Footer Background Colour), delivered through the skin's own
// :root var block and consumed by a rule that lives in the skin stylesheet.
$_footer_lowercase = (($settings['footer_lowercase'] ?? '0') === '1');

// --- RENDERING ---
?>
<footer id="system-footer" class="h-card<?php echo $_footer_lowercase ? ' footer-lowercase' : ''; ?>">
    <span class="snapsmack-indieweb-properties" hidden aria-hidden="true">
        <data class="p-name" value="<?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?>"></data>
        <a class="u-url" href="<?php echo htmlspecialchars(defined('BASE_URL') ? BASE_URL : '/'); ?>"></a>
        <?php if (!empty($settings['site_description'])): ?>
        <data class="p-note" value="<?php echo htmlspecialchars(strip_tags($settings['site_description'])); ?>"></data>
        <?php endif; ?>
    </span>
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
<?php if (!empty($settings['nav_menu_json']) && $settings['nav_menu_json'] !== '[]'): ?>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-nav-dropdown.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>
<?php endif; ?>
<!-- 0.7.80: public help modal — F1 / footer HELP link -->
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-public-help.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>
<?php if (($settings['site_mode'] ?? '') === 'smackthemup'): ?>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-public-share.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>" defer></script>
<?php endif; ?>
</footer>
<?php // ===== SNAPSMACK EOF =====
