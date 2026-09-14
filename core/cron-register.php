<?php
/**
 * SNAPSMACK - Self-registering cron helper
 *
 * SnapSmack registers its own scheduled tasks in the system crontab so the
 * user never touches a terminal. Extracted from the RSS-fetcher registration
 * that used to live in smack-admin-reference.php (deleted in 0.7.508 — see
 * UPDATER_DEPRECATED_FILES) into a shared helper so every self-scheduling
 * feature (RSS fetch, FEDIVERSE delivery, …) uses one proven code path.
 *
 * A job is identified by a tag comment (e.g. '# snapsmack-fediverse') PLUS the
 * script path inside THIS install. The tag alone is not enough: every site on
 * a shared box runs as the same user, writes into the same crontab, and uses
 * the same tag — so a tag-only match made one site's REGISTER silently replace
 * another's, and one crontab could only ever hold one site's job (OPAUDIT 014,
 * "Last One In": 36 of 37 sites unscheduled). The script path is the only
 * thing that makes a line ours.
 *
 * register is idempotent (re-registering updates OUR line); remove is safe to
 * call when nothing is registered. All operations no-op gracefully when the
 * host has no exec()/crontab (shared hosting) — the caller shows the manual
 * fallback line in that case.
 *
 * HEARTBEAT (the only truth): a job is running when cron_stamp_scheduler_fire()
 * has moved recently — not because a crontab line exists. The three cron
 * scripts stamp `<job>_sched_last_fire` first thing, only when launched by the
 * scheduler (CLI, not RUN NOW / event kicks, which set SNAPSMACK_LAUNCH=manual).
 * cron_job_verdict() turns that stamp into firing / stale / never /
 * not-since-deploy, and Cron & Jobs, the fleet API and CRONOMETER all read it.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/** Can this host self-register cron? Returns [bool supported, string php_cli]. */
function cron_capability(): array {
    if (!function_exists('exec')) return [false, ''];
    $out = []; $code = 1;
    @exec('crontab -l 2>&1', $out, $code);
    // `crontab -l` exits 1 when this user has no crontab yet. That is not lack
    // of capability; it is precisely the state in which registration is needed.
    $listing = implode("\n", $out);
    if ($code !== 0 && stripos($listing, 'no crontab for') === false) return [false, ''];
    $php = trim((string)@exec('which php 2>&1'));
    if (strpos($php, '/') !== 0) return [false, ''];  // no CLI PHP on PATH
    return [true, $php];
}

/** Root directory a crontab command must name for the line to be THIS site's. */
function cron_site_root(?string $script_abs = null): string {
    $dir = $script_abs !== null ? dirname($script_abs) : dirname(__DIR__);
    return rtrim(realpath($dir) ?: $dir, '/\\');
}

/**
 * Is this crontab line ours: carries $tag AND runs a script inside $root?
 * On a shared box every site's line carries the same tag; only the path tells
 * them apart. A line with our tag and another site's path is theirs — never
 * replace or remove it.
 */
function cron_line_is_ours(string $line, string $tag, string $root): bool {
    if (strpos($line, $tag) === false) return false;
    return strpos($line, rtrim($root, '/\\') . '/') !== false;
}

/**
 * A tagged line whose script no longer exists on disk belongs to nobody — a
 * deploy moved the install out from under it (OPAUDIT 011). The refresh that
 * runs after a deploy adopts and rewrites such lines to the live path.
 */
function cron_line_is_dead(string $line, string $tag): bool {
    if (strpos($line, $tag) === false) return false;
    if (!preg_match("/'([^']+\\.php)'/", $line, $m) && !preg_match('/(\/\S+\.php)/', $line, $m)) return false;
    return !is_file($m[1]);
}

/**
 * Is THIS site's job with this tag in the crontab? Pass the script path so the
 * match is per-site; without it the check degrades to tag-only (any site on
 * the box) and is only kept for callers that have no path to give.
 */
function cron_job_registered(string $tag, ?string $script_abs = null): bool {
    if (!function_exists('exec')) return false;
    $out = []; $code = 1;
    @exec('crontab -l 2>&1', $out, $code);
    if ($code !== 0) return false;
    if ($script_abs === null) return strpos(implode("\n", $out), $tag) !== false;
    $root = cron_site_root($script_abs);
    foreach ($out as $line) {
        if (cron_line_is_ours((string)$line, $tag, $root)) return true;
    }
    return false;
}

/**
 * Scheduler heartbeat. Called first thing by each cron script. Writes
 * `<job>_sched_last_fire` ONLY when the scheduler launched us: CLI SAPI, not a
 * RUN NOW / event kick (those launch through cron_run_detached, which sets
 * SNAPSMACK_LAUNCH=manual), not the page-hit fallback (web SAPI). Everything
 * that says "cron is running" reads this stamp and nothing else.
 */
function cron_stamp_scheduler_fire(PDO $pdo, string $job): bool {
    if (PHP_SAPI !== 'cli') return false;
    if (getenv('SNAPSMACK_LAUNCH') === 'manual') return false;
    if (defined('SNAPSMACK_INTERNAL_CRON')) return false;
    try {
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")
            ->execute([$job . '_sched_last_fire', date('Y-m-d H:i:s')]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** The three scheduled jobs every site runs: key => [tag, schedule, script, interval seconds]. */
function cron_job_catalogue(): array {
    return [
        'fediverse'     => ['# snapsmack-fediverse',     '*/10 * * * *', 'cron-fediverse.php',     600],
        'rss_fetch'     => ['# snapsmack-rss-fetch',     '0 * * * *',    'cron-rss-fetch.php',     3600],
        'version_check' => ['# snapsmack-version-check', '0 */6 * * *',  'cron-version-check.php', 21600],
    ];
}

/**
 * The one verdict every surface shows. Reads ONLY the scheduler heartbeat.
 *   state: 'firing'           fired within 2x its interval
 *          'stale'            fired before, not within 2x its interval
 *          'never'            no scheduler launch on record
 *          'not-since-deploy' a deploy finished >= 15 min ago and the job has
 *                             not fired since (the post-deploy gate)
 * Returns [state, last_fire (Y-m-d H:i:s or ''), age_seconds|null, deploy_at].
 */
function cron_job_verdict(array $settings, string $job): array {
    $cat = cron_job_catalogue();
    $interval = (int)($cat[$job][3] ?? 600);
    $fire = trim((string)($settings[$job . '_sched_last_fire'] ?? ''));
    $fire_t = $fire !== '' ? (strtotime($fire) ?: 0) : 0;
    $deploy = trim((string)($settings['deploy_finalized_at'] ?? ''));
    $deploy_t = $deploy !== '' ? (strtotime($deploy) ?: 0) : 0;
    $age = $fire_t ? max(0, time() - $fire_t) : null;
    // Post-deploy gate arms only once the job has HAD a chance to fire: at
    // least one interval after the deploy (15 min floor for the 10-min job).
    // A 6-hourly version check deployed at 19:03 is not "missing" at 19:20.
    $gate = max(900, $interval);
    if ($deploy_t && (time() - $deploy_t) >= $gate && $fire_t < $deploy_t) {
        return ['not-since-deploy', $fire, $age, $deploy];
    }
    if (!$fire_t) return ['never', '', null, $deploy];
    if ($age > 2 * $interval) return ['stale', $fire, $age, $deploy];
    return ['firing', $fire, $age, $deploy];
}

/** Stamp the moment a deploy finished, so cron_job_verdict can gate on it. */
function cron_stamp_deploy_finalized(PDO $pdo): void {
    try {
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('deploy_finalized_at', ?)
            ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")->execute([date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
    }
}

/** Return the exact tagged line plus whether it invokes the expected PHP script. */
function cron_job_inspect(string $tag, string $schedule, string $script_abs): array {
    if (!function_exists('exec')) return ['registered'=>false, 'valid'=>false, 'line'=>'', 'problem'=>'crontab unavailable'];
    $out = []; $code = 1;
    @exec('crontab -l 2>&1', $out, $code);
    if ($code !== 0) return ['registered'=>false, 'valid'=>false, 'line'=>'', 'problem'=>'crontab unavailable'];
    // Per-site: only lines that run a script inside THIS install are ours.
    // Another site's line with the same tag is not "invalid" — it isn't ours.
    $root = cron_site_root($script_abs);
    $matches = array_values(array_filter($out, static fn($line) => cron_line_is_ours((string)$line, $tag, $root)));
    if (!$matches) return ['registered'=>false, 'valid'=>false, 'line'=>'', 'problem'=>'no entry for this site'];
    if (count($matches) !== 1) return ['registered'=>true, 'valid'=>false, 'line'=>implode("\n", $matches), 'problem'=>'duplicate tagged entries'];
    $line = trim((string)$matches[0]);
    $script_real = realpath($script_abs) ?: $script_abs;
    $valid = str_starts_with($line, trim($schedule) . ' ')
        && strpos($line, $script_real) !== false
        && strpos($line, $tag) !== false;
    return ['registered'=>true, 'valid'=>$valid, 'line'=>$line,
            'problem'=>$valid ? '' : 'registered command does not match this job'];
}

/** Start a CLI job outside the web request's process group. */
function cron_run_detached(string $php_cli, string $script_abs): bool {
    if (!function_exists('exec') || DIRECTORY_SEPARATOR === '\\'
        || !is_file($php_cli) || !is_executable($php_cli)
        || !is_file($script_abs)) return false;
    $setsid = '';
    foreach (['/usr/bin/setsid', '/bin/setsid'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) { $setsid = $candidate; break; }
    }
    $nohup = '';
    foreach (['/usr/bin/nohup', '/bin/nohup'] as $candidate) {
        if (is_file($candidate) && is_executable($candidate)) { $nohup = $candidate; break; }
    }
    // `setsid command &` is not enough on every Linux build: when setsid can
    // reuse the shell child, Apache may retain that process in its request
    // group and Cloudflare waits until the paced job finishes.  `setsid -f`
    // guarantees a second fork; nohup plus a closed stdin severs every inherited
    // request descriptor.  Keep a nohup-only fallback for minimal hosts.
    $prefix = '';
    if ($setsid !== '') {
        $prefix = ($nohup !== '' ? escapeshellarg($nohup) . ' ' : '')
                . escapeshellarg($setsid) . ' -f ';
    } elseif ($nohup !== '') {
        $prefix = escapeshellarg($nohup) . ' ';
    }
    $log_dir = dirname(__DIR__) . '/logs';
    if (!is_dir($log_dir)) @mkdir($log_dir, 0750, true);
    $log_path = $log_dir . '/cron-' . preg_replace('/\.php$/i', '', basename($script_abs)) . '.log';
    $out = []; $code = 1;
    // SNAPSMACK_LAUNCH=manual: a human / event launch, not the scheduler. The
    // script then leaves the scheduler heartbeat alone, so RUN NOW and event
    // kicks can never make a dead cron look alive.
    @exec('SNAPSMACK_LAUNCH=manual ' . $prefix . escapeshellarg($php_cli) . ' ' . escapeshellarg($script_abs)
        . ' < /dev/null >> ' . escapeshellarg($log_path) . ' 2>&1 &', $out, $code);
    return $code === 0;
}

/**
 * Register (or refresh) a scheduled job.
 *   $schedule    cron time spec, e.g. '*\/10 * * * *' (pass literally, no escaping)
 *   $script_abs  absolute path to the PHP script to run
 *   $tag         unique '# snapsmack-...' comment identifying the job
 * Returns [bool ok, string message]. Idempotent: an existing job with the
 * same tag is replaced, so schedule/path changes take effect on re-register.
 */
function cron_register_job(string $schedule, string $script_abs, string $tag): array {
    list($ok, $php) = cron_capability();
    if (!$ok) return [false, 'This host does not allow SnapSmack to manage cron — add the line manually (shown below).'];
    if (!is_file($script_abs)) return [false, 'Script not found: ' . $script_abs];

    $out = []; $code = 1;
    @exec('crontab -l 2>&1', $out, $code);
    $current = ($code === 0) ? implode("\n", $out) : '';

    // Drop THIS site's existing line (and any tagged line whose script is
    // gone — a moved install's orphan), then append the fresh one. Other
    // sites' lines with the same tag are left exactly as they are.
    $root = cron_site_root($script_abs);
    $kept = array_filter(explode("\n", $current), static function ($line) use ($tag, $root) {
        $line = (string)$line;
        return !cron_line_is_ours($line, $tag, $root) && !cron_line_is_dead($line, $tag);
    });
    $cleaned = implode("\n", $kept);
    // Never throw cron evidence away. Each job owns a web-denied, durable log
    // that survives the request which registered it and exposes CLI fatals that
    // Apache's error log cannot see.
    $log_dir = dirname(__DIR__) . '/logs';
    if (!is_dir($log_dir)) @mkdir($log_dir, 0750, true);
    if (is_dir($log_dir)) {
        $deny = $log_dir . '/.htaccess';
        if (!is_file($deny)) @file_put_contents($deny, "Require all denied\n");
    }
    $safe_tag = trim(preg_replace('/[^a-z0-9_-]+/i', '-', trim($tag, "# \t\r\n")), '-');
    $log_path = $log_dir . '/cron-' . ($safe_tag !== '' ? $safe_tag : 'job') . '.log';
    $entry = trim($schedule) . ' ' . escapeshellarg($php) . ' ' . escapeshellarg($script_abs)
           . ' >> ' . escapeshellarg($log_path) . ' 2>&1 ' . $tag;
    $new     = trim((string)$cleaned) . "\n" . $entry . "\n";

    $tmp = tempnam(sys_get_temp_dir(), 'sscron');
    if ($tmp === false) return [false, 'Could not create a temp file for crontab install.'];
    file_put_contents($tmp, ltrim($new, "\n"));
    $o = []; $r = 1;
    @exec("crontab {$tmp} 2>&1", $o, $r);
    @unlink($tmp);
    return $r === 0
        ? [true, 'Scheduled task registered.']
        : [false, 'crontab install failed: ' . implode(' ', $o)];
}

/**
 * Refresh every SnapSmack-owned cron entry that is already enabled.
 *
 * Deployments may move the live install to a new absolute directory while the
 * tagged crontab line keeps yesterday's script path. Merely checking for the tag
 * then reports a false "registered" state and the job silently stops. Run this
 * after a successful update, once the new files are in their final location.
 * Deliberately preserves operator intent: absent/disabled jobs remain absent.
 *
 * @return array<int,array{tag:string,ok:bool,message:string}>
 */
function cron_refresh_enabled_jobs(string $root): array {
    $jobs = [
        ['*/10 * * * *', 'cron-fediverse.php',   '# snapsmack-fediverse'],
        ['0 * * * *',    'cron-rss-fetch.php',   '# snapsmack-rss-fetch'],
        ['0 */6 * * *',  'cron-version-check.php','# snapsmack-version-check'],
    ];
    $results = [];
    $listing = [];
    if (function_exists('exec')) { $lc = 1; @exec('crontab -l 2>&1', $listing, $lc); if ($lc !== 0) $listing = []; }
    foreach ($jobs as [$schedule, $script, $tag]) {
        $path = realpath(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $script)
             ?: rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $script;
        // Ours already, or an orphan line the deploy left behind: refresh it.
        // Another site's line: not ours to touch. Nothing: stay absent.
        $ours = cron_job_registered($tag, $path);
        $dead = false;
        foreach ($listing as $line) { if (cron_line_is_dead((string)$line, $tag)) { $dead = true; break; } }
        if (!$ours && !$dead) continue;
        [$ok, $message] = cron_register_job($schedule, $path, $tag);
        $results[] = ['tag' => $tag, 'ok' => $ok, 'message' => $message];
    }
    return $results;
}

/**
 * Ensure the FEDIVERSE WebFinger rewrite is present in the root .htaccess.
 * Called on federation-enable so the user never hand-edits Apache config.
 * Idempotent: no-op when already present. Inserts the rule just before the
 * catch-all router line (so it wins). Best-effort — returns a status pair;
 * the full canonical block is still what System Maintenance → REPAIR writes.
 */
function cron_ensure_webfinger_htaccess(string $htaccess_path): array {
    // Both Fediverse rewrites, keyed by their presence-check needle.
    // The /ap/ path routes exist because AP object ids must be
    // query-string-free — Pixelfed HTML-encodes '&' when dereferencing
    // object URLs, so ?ap=note&post=N ids 404 on their side (0.7.350).
    $rules = [
        'well-known/webfinger' =>
            "# FEDIVERSE (ActivityPub) WebFinger discovery — harmless while disabled.\n"
            . 'RewriteRule ^\\.well-known/webfinger$ fediverse.php?ap=webfinger [L,QSA]' . "\n",
        'fediverse.php?appath=' =>
            "# FEDIVERSE (ActivityPub) path-style object routes — harmless while disabled.\n"
            . 'RewriteRule ^ap/(.+)$ fediverse.php?appath=$1 [L,QSA]' . "\n",
    ];
    if (!is_file($htaccess_path)) {
        return [false, '.htaccess not found — run System Maintenance → REPAIR .htaccess.'];
    }
    $content = file_get_contents($htaccess_path);
    if ($content === false) return [false, 'Could not read .htaccess.'];

    $missing = [];
    foreach ($rules as $needle => $line) {
        if (strpos($content, $needle) === false) $missing[$needle] = $line;
    }
    if (!$missing) {
        return [true, 'Fediverse rewrites already present.'];
    }
    if (!is_writable($htaccess_path)) {
        return [false, '.htaccess is not writable — run System Maintenance → REPAIR .htaccess, or add the rules by hand.'];
    }

    // Insert directly above the catch-all "everything → index.php" router so
    // the dotted/slashed AP paths are matched first. Fall back to appending
    // inside the SnapSmack block if the catch-all isn't found.
    $catchall = '/^(\s*RewriteRule\s+\^\(\[a-zA-Z0-9_\-\]\+\)\$\s+index\.php.*)$/m';
    $block = implode('', $missing);
    if (preg_match($catchall, $content)) {
        $new = preg_replace($catchall, $block . '$1', $content, 1);
    } else {
        $new = rtrim($content) . "\n\n{$block}";
    }
    if ($new === null || @file_put_contents($htaccess_path, $new, LOCK_EX) === false) {
        return [false, 'Could not write the FEDIVERSE rules — run System Maintenance → REPAIR .htaccess.'];
    }
    return [true, 'Fediverse rewrites added to .htaccess.'];
}

/**
 * Remove THIS site's job carrying $tag. Safe when nothing is registered.
 * Pass the script path; without it (legacy callers) every line with the tag
 * goes — which on a shared box is every site's job.
 */
function cron_remove_job(string $tag, ?string $script_abs = null): array {
    if (!function_exists('exec')) return [false, 'exec() unavailable.'];
    $out = []; $code = 1;
    @exec('crontab -l 2>&1', $out, $code);
    if ($code !== 0) return [true, 'No crontab to modify.'];
    $current = implode("\n", $out);
    if ($script_abs !== null) {
        $root = cron_site_root($script_abs);
        $kept = array_filter($out, static fn($line) => !cron_line_is_ours((string)$line, $tag, $root));
        if (count($kept) === count($out)) return [true, 'Job was not registered for this site.'];
        $cleaned = implode("\n", $kept);
    } else {
        if (strpos($current, $tag) === false) return [true, 'Job was not registered.'];
        $cleaned = preg_replace('/.*' . preg_quote($tag, '/') . '.*\n?/', '', $current);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'sscron');
    if ($tmp === false) return [false, 'Could not create a temp file for crontab update.'];
    file_put_contents($tmp, trim((string)$cleaned) . "\n");
    $o = []; $r = 1;
    @exec("crontab {$tmp} 2>&1", $o, $r);
    @unlink($tmp);
    return $r === 0 ? [true, 'Scheduled task removed.'] : [false, 'crontab update failed: ' . implode(' ', $o)];
}
// ===== SNAPSMACK EOF =====
