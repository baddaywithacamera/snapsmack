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
    $slots[] = "&copy; " . date("Y") . " " . $site_name;
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
    $skin_manifest_path = __DIR__ . '/../skins/' . $skin_slug . '/manifest.php';
    if (file_exists($skin_manifest_path)) {
        try {
            $skin_manifest_data = include $skin_manifest_path;
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
// RSS feed link is always visible and cannot be disabled
$rss_url = (defined('BASE_URL') ? BASE_URL : '/') . 'feed';
$slots[] = '<a href="' . $rss_url . '" class="footer-link rss-tag" title="RSS Feed">RSS</a>';

// --- FOOTER BAR BACKGROUND (optional, per-skin colour + opacity) ---
// footer_bg_color + footer_bg_opacity → an rgba emitted as the inline
// --footer-bg custom property. Each skin's CSS reads it with a fallback, so
// leaving it unset keeps that skin's own default (panel/bg). This lets the
// footer bar be dialled independently of Panel Opacity (screenshot-friendly).
$_footer_bg_var = '';
$_fb_color = trim((string)($settings['footer_bg_color'] ?? ''));
if (preg_match('/^#?[0-9a-fA-F]{6}$/', $_fb_color)) {
    $_fb_hex = ltrim($_fb_color, '#');
    $_fb_op  = $settings['footer_bg_opacity'] ?? '';
    $_fb_op  = ($_fb_op === '') ? 100 : max(0, min(100, (int)$_fb_op));
    $_footer_bg_var = '--footer-bg:rgba('
        . hexdec(substr($_fb_hex, 0, 2)) . ',' . hexdec(substr($_fb_hex, 2, 2)) . ',' . hexdec(substr($_fb_hex, 4, 2))
        . ',' . round($_fb_op / 100, 3) . ')';
}
$_footer_styles = [];
if (($settings['footer_lowercase'] ?? '0') === '1') $_footer_styles[] = 'text-transform:lowercase';
if ($_footer_bg_var !== '') $_footer_styles[] = $_footer_bg_var;
$_footer_class = (($settings['footer_lowercase'] ?? '0') === '1') ? ' class="footer-lowercase"' : '';
$_footer_style = $_footer_styles ? ' style="' . implode(';', $_footer_styles) . '"' : '';

// --- RENDERING ---
?>
<footer id="system-footer"<?php echo $_footer_class . $_footer_style; ?>>
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
</footer>
<?php // ===== SNAPSMACK EOF =====
