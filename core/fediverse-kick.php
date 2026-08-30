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

    $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
    if (!function_exists('exec') || in_array('exec', $disabled, true)) return false;

    $script = dirname(__DIR__) . '/cron-fediverse.php';
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

    // ── DETACH THE WORKER FROM THIS REQUEST ─────────────────────────────────
    // A bare `... &` leaves the child in the PHP-FPM worker's PROCESS GROUP.
    // When FPM reaps or recycles that worker (request_terminate_timeout, pool
    // churn, idle-process cull) the signal reaches the child too and the drain
    // dies part-way through its batch — after the row it is mid-send but BEFORE
    // it writes the outcome back. The symptom is a queue where rows sit at
    // attempts=0 forever while a handful of deliveries did visibly succeed:
    // craptasti.ca, 2026-07-25, 13 activities delivered and 291 never attempted.
    //
    // setsid puts the worker in its OWN session and process group so it outlives
    // the request. nohup is the fallback where setsid isn't installed; a bare
    // backgrounded command is the last resort (old behaviour — still better than
    // no delivery at all on a host with neither).
    $prefix = '';
    foreach (['/usr/bin/setsid', '/bin/setsid', '/usr/bin/nohup', '/bin/nohup'] as $cand) {
        if (is_file($cand) && is_executable($cand)) { $prefix = $cand . ' '; break; }
    }

    exec($prefix . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' > /dev/null 2>&1 &');
    return true;
}
// ===== SNAPSMACK EOF =====
