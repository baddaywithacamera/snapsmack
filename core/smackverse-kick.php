<?php
/**
 * SNAPSMACK — non-blocking SMACKVERSE delivery kick
 *
 * Starts the CLI delivery worker after a delivery-producing web event. The
 * worker owns pacing and retries; the request never sends remote HTTP or
 * sleeps. Hosts without exec simply fall back to the scheduled cron sweep.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

function sv_kick_delivery(): bool {
    static $kicked = false;
    if ($kicked || PHP_SAPI === 'cli' || DIRECTORY_SEPARATOR === '\\') return false;
    $kicked = true;

    $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
    if (!function_exists('exec') || in_array('exec', $disabled, true)) return false;

    $script = dirname(__DIR__) . '/cron-smackverse.php';
    if (!is_file($script)) return false;

    $php_candidates = [PHP_BINDIR . '/php', '/usr/bin/php', PHP_BINARY];
    $php = '';
    foreach ($php_candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            $php = $candidate;
            break;
        }
    }
    if ($php === '') return false;

    exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
    return true;
}
// ===== SNAPSMACK EOF =====
