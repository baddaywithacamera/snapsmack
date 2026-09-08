<?php
/**
 * SNAPSMACK - Mobile app install-mode routing
 *
 * Maps the authoritative snap_settings.site_mode value to the one composer
 * that belongs to the current installation. The PWA never offers a mode
 * picker and composer pages use the same map to reject forged cross-mode URLs.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!function_exists('snapsmack_app_mode')) {
    function snapsmack_app_mode(array $settings): string {
        $mode = (string)($settings['site_mode'] ?? 'photoblog');
        return in_array($mode, ['photoblog', 'carousel', 'smacktalk', 'smackthemup'], true)
            ? $mode
            : 'photoblog';
    }
}

if (!function_exists('snapsmack_mode_has_web_composer')) {
    /**
     * Whether the installed mode allows creating a photograph post through the
     * browser/PWA at all. SMACKTHEMUP is desktop-only: SNAP SLAPPER is the sole
     * creation path, so it has NO web composer (spec §4.1, §5.1). Enforced here,
     * server-side — hiding buttons is not enough.
     */
    function snapsmack_mode_has_web_composer(array $settings): bool {
        return snapsmack_app_mode($settings) !== 'smackthemup';
    }
}

if (!function_exists('snapsmack_app_composer')) {
    function snapsmack_app_composer(array $settings): string {
        return [
            'photoblog'   => 'smack-post-solo.php',
            'carousel'    => 'smack-post-gram.php',
            'smacktalk'   => 'smack-post-long.php',
            // SMACKTHEMUP has no web composer; fall back to the admin dashboard
            // so any redirect target resolves to a safe, non-creating page.
            'smackthemup' => 'smack-app.php',
        ][snapsmack_app_mode($settings)];
    }
}

if (!function_exists('snapsmack_require_app_mode')) {
    function snapsmack_require_app_mode(array $settings, string $required_mode): void {
        if (snapsmack_app_mode($settings) === $required_mode) {
            return;
        }

        // SMACKTHEMUP publishes from SNAP SLAPPER (desktop) only — there is no
        // browser composer for this mode. Refuse rather than redirect to a
        // composer that does not exist for it (spec §5.1).
        if (snapsmack_app_mode($settings) === 'smackthemup') {
            http_response_code(409);
            exit('SMACKTHEMUP publishes from SNAP SLAPPER (desktop) only — there is no browser composer for this mode. Open SNAP SLAPPER to add photographs.');
        }

        $target = snapsmack_app_composer($settings);
        if (!headers_sent()) {
            header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/') . $target, true, 303);
            exit;
        }

        http_response_code(409);
        exit('This composer does not match the installed SnapSmack mode.');
    }
}

// ===== SNAPSMACK EOF =====
