<?php
/**
 * SNAPSMACK — web-triggered cron for locked-down hosts
 *
 * Many shared hosts run NO background jobs: crontab is unavailable and exec()
 * is disabled, so cron-smackverse.php never fires and the admin shows
 * "Last cron run: never" — fediverse delivery stalls, the relay stays empty,
 * and the FEDBOARD site-picker never fills. This runs the due sweep straight
 * from ordinary public page loads instead, so a plain single install self-heals
 * with zero setup: no terminal, no hub, no desktop tool.
 *
 * Safety:
 *  - No-op on CLI, when SMACKVERSE is off, or when smackverse_webcron_enabled
 *    is explicitly '0'.
 *  - Throttled to the 10-minute delivery cadence using the SAME last-run stamp
 *    the CLI cron and the RUN NOW button write, so nearly every request does
 *    nothing but one cheap in-memory check.
 *  - The heavy engine (smackverse.php) is required ONLY when a sweep is actually
 *    due, and only inside the shutdown handler — normal page loads never pay it.
 *  - The sweep runs AFTER the response is flushed (fastcgi_finish_request where
 *    available), so the visitor never waits; sv_run_sweep's own GET_LOCK stops
 *    two requests ever sweeping at once. A first run on a site stamped "never"
 *    ($last = 0) is due immediately.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!function_exists('sv_web_cron_tick')) {
    function sv_web_cron_tick(PDO $pdo, array $settings): void
    {
        if (PHP_SAPI === 'cli') return;
        if (($settings['smackverse_enabled'] ?? '0') !== '1') return;
        if (($settings['smackverse_webcron_enabled'] ?? '1') === '0') return;

        // Due? Reuse the exact stamp the CLI cron / RUN NOW button write. A blank
        // or "never" value parses to 0 and is treated as due right away.
        $last = strtotime((string)($settings['smackverse_cron_last_run'] ?? '')) ?: 0;
        if ($last && (time() - $last) < 600) return;

        register_shutdown_function(static function () use ($pdo, $settings) {
            // Close the visitor's connection first where the SAPI allows it so
            // the sweep runs entirely off the page load.
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            @ignore_user_abort(true);
            @set_time_limit(60);

            require_once __DIR__ . '/smackverse.php';
            if (!function_exists('sv_run_sweep')) return;
            try {
                $s = $settings;                 // sv_run_sweep takes it by ref
                sv_run_sweep($pdo, $s);
            } catch (Throwable $e) {
                error_log('sv_web_cron_tick sweep failed: ' . $e->getMessage());
            }
        });
    }
}
// ===== SNAPSMACK EOF =====
