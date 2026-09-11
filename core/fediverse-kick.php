<?php
/**
 * SNAPSMACK — non-blocking FEDIVERSE delivery kick
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

    $script = dirname(__DIR__) . '/cron-fediverse.php';
    if (!is_file($script)) return false;

    // Event kicks and RUN NOW use one launcher. The former duplicate could die
    // with PHP-FPM, hid errors in /dev/null, and still reported success.
    require_once __DIR__ . '/cron-register.php';

    $php_candidates = [PHP_BINDIR . '/php', '/usr/bin/php', PHP_BINARY];
    $php = '';
    foreach ($php_candidates as $candidate) {
        if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            $php = $candidate;
            break;
        }
    }
    if ($php === '') return false;

    return cron_run_detached($php, $script);
}
// ===== SNAPSMACK EOF =====
