<?php
/**
 * SNAPSMACK - Self-registering cron helper
 *
 * SnapSmack registers its own scheduled tasks in the system crontab so the
 * user never touches a terminal. Extracted from the RSS-fetcher registration
 * (smack-admin-reference.php) into a shared helper so every self-scheduling
 * feature (RSS fetch, SMACKVERSE delivery, …) uses one proven code path.
 *
 * A job is identified by a unique tag comment (e.g. '# snapsmack-smackverse').
 * register is idempotent (re-registering updates the line); remove is safe to
 * call when nothing is registered. All operations no-op gracefully when the
 * host has no exec()/crontab (shared hosting) — the caller shows the manual
 * fallback line in that case.
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
    if ($code !== 0) return [false, ''];         // no crontab access
    $php = trim((string)@exec('which php 2>&1'));
    if (strpos($php, '/') !== 0) return [false, ''];  // no CLI PHP on PATH
    return [true, $php];
}

/** Is a job with this tag currently in the crontab? */
function cron_job_registered(string $tag): bool {
    if (!function_exists('exec')) return false;
    $out = []; $code = 1;
    @exec('crontab -l 2>&1', $out, $code);
    return $code === 0 && strpos(implode("\n", $out), $tag) !== false;
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

    // Drop any existing line carrying our tag, then append the fresh one.
    $cleaned = preg_replace('/.*' . preg_quote($tag, '/') . '.*\n?/', '', $current);
    $entry   = "{$schedule} {$php} {$script_abs} >> /dev/null 2>&1 {$tag}";
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
 * Ensure the SMACKVERSE WebFinger rewrite is present in the root .htaccess.
 * Called on federation-enable so the user never hand-edits Apache config.
 * Idempotent: no-op when already present. Inserts the rule just before the
 * catch-all router line (so it wins). Best-effort — returns a status pair;
 * the full canonical block is still what System Maintenance → REPAIR writes.
 */
function cron_ensure_webfinger_htaccess(string $htaccess_path): array {
    // Both SMACKVERSE rewrites, keyed by their presence-check needle.
    // The /ap/ path routes exist because AP object ids must be
    // query-string-free — Pixelfed HTML-encodes '&' when dereferencing
    // object URLs, so ?ap=note&post=N ids 404 on their side (0.7.350).
    $rules = [
        'well-known/webfinger' =>
            "# SMACKVERSE (ActivityPub) WebFinger discovery — harmless while disabled.\n"
            . 'RewriteRule ^\\.well-known/webfinger$ smackverse.php?ap=webfinger [L,QSA]' . "\n",
        'smackverse.php?appath=' =>
            "# SMACKVERSE (ActivityPub) path-style object routes — harmless while disabled.\n"
            . 'RewriteRule ^ap/(.+)$ smackverse.php?appath=$1 [L,QSA]' . "\n",
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
        return [true, 'SMACKVERSE rewrites already present.'];
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
        return [false, 'Could not write the SMACKVERSE rules — run System Maintenance → REPAIR .htaccess.'];
    }
    return [true, 'SMACKVERSE rewrites added to .htaccess.'];
}

/** Remove the job carrying $tag. Safe when nothing is registered. */
function cron_remove_job(string $tag): array {
    if (!function_exists('exec')) return [false, 'exec() unavailable.'];
    $out = []; $code = 1;
    @exec('crontab -l 2>&1', $out, $code);
    if ($code !== 0) return [true, 'No crontab to modify.'];
    $current = implode("\n", $out);
    if (strpos($current, $tag) === false) return [true, 'Job was not registered.'];

    $cleaned = preg_replace('/.*' . preg_quote($tag, '/') . '.*\n?/', '', $current);
    $tmp = tempnam(sys_get_temp_dir(), 'sscron');
    if ($tmp === false) return [false, 'Could not create a temp file for crontab update.'];
    file_put_contents($tmp, trim((string)$cleaned) . "\n");
    $o = []; $r = 1;
    @exec("crontab {$tmp} 2>&1", $o, $r);
    @unlink($tmp);
    return $r === 0 ? [true, 'Scheduled task removed.'] : [false, 'crontab update failed: ' . implode(' ', $o)];
}
// ===== SNAPSMACK EOF =====
