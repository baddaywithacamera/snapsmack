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
        return in_array($mode, ['photoblog', 'carousel', 'smacktalk'], true)
            ? $mode
            : 'photoblog';
    }
}

if (!function_exists('snapsmack_app_composer')) {
    function snapsmack_app_composer(array $settings): string {
        return [
            'photoblog' => 'smack-post-solo.php',
            'carousel'  => 'smack-post-gram.php',
            'smacktalk' => 'smack-post-long.php',
        ][snapsmack_app_mode($settings)];
    }
}

if (!function_exists('snapsmack_require_app_mode')) {
    function snapsmack_require_app_mode(array $settings, string $required_mode): void {
        if (snapsmack_app_mode($settings) === $required_mode) {
            return;
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
