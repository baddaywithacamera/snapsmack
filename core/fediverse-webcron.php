<?php
/**
 * SNAPSMACK — web-triggered cron for locked-down hosts
 *
 * Many shared hosts run NO background jobs: crontab is unavailable and exec()
 * is disabled, so cron-fediverse.php never fires and the admin shows
 * "Last cron run: never" — fediverse delivery stalls, the relay stays empty,
 * and the FEDBOARD site-picker never fills. This runs the due sweep straight
 * from ordinary public page loads instead, so a plain single install self-heals
 * with zero setup: no terminal, no hub, no desktop tool.
 *
 * Safety:
 *  - No-op on CLI, when FEDIVERSE is off, or when fediverse_webcron_enabled
 *    is explicitly '0'.
 *  - Throttled to the 10-minute delivery cadence using the SAME last-run stamp
 *    the CLI cron and the RUN NOW button write, so nearly every request does
 *    nothing but one cheap in-memory check.
 *  - The heavy engine (fediverse.php) is required ONLY when a sweep is actually
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
        if (($settings['fediverse_webcron_enabled'] ?? '1') === '0') return;

        // FEDBOARD roster health is independent of ActivityPub delivery. A
        // spoke must keep learning its fleet even when FEDIVERSE is disabled
        // or the host has no system crontab.
        if (($settings['multisite_role'] ?? '') === 'spoke') {
            $roster_last = (int)($settings['fedboard_roster_pull_attempt'] ?? 0);
            if (!$roster_last || time() - $roster_last >= 900) {
                register_shutdown_function(static function () use ($pdo, $settings) {
                    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                    @ignore_user_abort(true);
                    @set_time_limit(20);
                    require_once __DIR__ . '/mesh-helpers.php';
                    try { ms_spoke_pull_roster($pdo, $settings); }
                    catch (Throwable $e) { error_log('FEDBOARD roster refresh failed: ' . $e->getMessage()); }
                });
            }
        }

        // RSS blogroll fetch — independent of FEDIVERSE. This is the cron that
        // shows "never" on hosts with no system crontab, because it had no
        // web-cron path until now. Throttle to the hourly CLI cadence using the
        // same rss_last_run stamp the CLI cron / Cron & Jobs RUN NOW write. A
        // blank/"never" value parses to 0 and is due immediately.
        $rss_last = strtotime((string)($settings['rss_last_run'] ?? '')) ?: 0;
        if (!$rss_last || (time() - $rss_last) >= 3600) {
            register_shutdown_function(static function () use ($pdo) {
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                @ignore_user_abort(true);
                @set_time_limit(120);
                // One runner at a time across concurrent page loads.
                try {
                    $got = $pdo->query("SELECT GET_LOCK('snapsmack_rss_webcron', 0)")->fetchColumn();
                    if ((int)$got !== 1) return;
                } catch (Throwable $e) { return; }
                try {
                    if (!defined('SNAPSMACK_INTERNAL_CRON')) define('SNAPSMACK_INTERNAL_CRON', true);
                    ob_start();
                    require dirname(__DIR__) . '/cron-rss-fetch.php';
                    ob_end_clean();
                } catch (Throwable $e) {
                    while (ob_get_level() > 0) { @ob_end_clean(); }
                    error_log('RSS web-cron failed: ' . $e->getMessage());
                }
                try { $pdo->query("SELECT RELEASE_LOCK('snapsmack_rss_webcron')"); } catch (Throwable $e) {}
            });
        }

        if (($settings['fediverse_enabled'] ?? '0') !== '1') return;

        // Due? Reuse the exact stamp the CLI cron / RUN NOW button write. A blank
        // or "never" value parses to 0 and is treated as due right away.
        $last = strtotime((string)($settings['fediverse_cron_last_run'] ?? '')) ?: 0;
        if ($last && (time() - $last) < 600) return;

        register_shutdown_function(static function () use ($pdo, $settings) {
            // Close the visitor's connection first where the SAPI allows it so
            // the sweep runs entirely off the page load.
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            @ignore_user_abort(true);
            @set_time_limit(60);

            require_once __DIR__ . '/fediverse.php';
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
