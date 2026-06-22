<?php
/**
 * SNAPSMACK - Diagnostic Logger
 *
 * Lightweight, dependency-free, fire-and-forget structured logger used to
 * troubleshoot SMACKBACK false-breaches and update/extraction races. Writes
 * one JSON object per line ("JSON lines") to logs/<channel>.log under the
 * project root.
 *
 * Design rules:
 *  - NEVER throws. A logging failure must never break a page or a cron run.
 *  - No DB dependency. Safe to call from anywhere, including mid-extraction.
 *  - The logs/ directory is web-denied (.htaccess), excluded from the SMACKBACK
 *    integrity monitor (see core/smackback.php smackback_should_monitor()),
 *    listed in protected_paths.json, excluded from the release zip, and
 *    gitignored. Touch all five if you ever rename it.
 *
 * Kill switch: define('SNAPSMACK_DIAGLOG_OFF', true) before include to disable.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!defined('SNAPSMACK_DIAGLOG_MAX_BYTES')) {
    define('SNAPSMACK_DIAGLOG_MAX_BYTES', 5 * 1024 * 1024); // rotate at 5 MB
}
if (!defined('SNAPSMACK_DIAGLOG_KEEP')) {
    define('SNAPSMACK_DIAGLOG_KEEP', 7); // rotated files retained per channel
}

/**
 * Absolute path to the logs/ directory (project root /logs).
 */
function snap_diaglog_dir(): string {
    return dirname(__DIR__) . '/logs';
}

/**
 * Ensure logs/ exists and is self-protecting (.htaccess deny + index.html).
 * Returns the dir path, or '' on failure.
 */
function snap_diaglog_ensure_dir(): string {
    $dir = snap_diaglog_dir();
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return '';
        }
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        // Apache 2.2 and 2.4 both covered.
        @file_put_contents(
            $ht,
            "Require all denied\n"
            . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
        );
    }
    $idx = $dir . '/index.html';
    if (!is_file($idx)) {
        @file_put_contents($idx, '');
    }
    return $dir;
}

/**
 * Rotate a channel log if it exceeds the size cap, pruning old rotations.
 */
function snap_diaglog_rotate(string $file): void {
    if (!is_file($file) || filesize($file) < SNAPSMACK_DIAGLOG_MAX_BYTES) {
        return;
    }
    @rename($file, $file . '.' . date('Ymd-His'));
    // Keep only the newest SNAPSMACK_DIAGLOG_KEEP rotated files.
    $rotated = glob($file . '.*');
    if (is_array($rotated) && count($rotated) > SNAPSMACK_DIAGLOG_KEEP) {
        usort($rotated, fn($a, $b) => filemtime($a) <=> filemtime($b));
        $excess = array_slice($rotated, 0, count($rotated) - SNAPSMACK_DIAGLOG_KEEP);
        foreach ($excess as $old) { @unlink($old); }
    }
}

/**
 * Append a structured event to logs/<channel>.log. Never throws.
 *
 * @param string $channel  Log file stem, e.g. 'smackback' or 'updater'.
 * @param string $event    Short event name, e.g. 'verify' or 'breach'.
 * @param array  $data     Arbitrary context; merged into the JSON record.
 */
function snap_diaglog(string $channel, string $event, array $data = []): void {
    if (defined('SNAPSMACK_DIAGLOG_OFF') && SNAPSMACK_DIAGLOG_OFF) {
        return;
    }
    try {
        $dir = snap_diaglog_ensure_dir();
        if ($dir === '') return;
        $channel = preg_replace('/[^a-z0-9_-]/i', '', $channel) ?: 'misc';
        $file = $dir . '/' . $channel . '.log';
        snap_diaglog_rotate($file);
        $record = array_merge([
            'ts'    => date('c'),
            'pid'   => function_exists('getmypid') ? getmypid() : null,
            'sapi'  => PHP_SAPI,
            'event' => $event,
        ], $data);
        $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($line === false) return;
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
        // Diagnostics must never break the request.
    }
}

/**
 * Snapshot the maintenance/extraction lock state — the dispositive fact for
 * telling an update-window race apart from a genuine integrity breach. The
 * updater writes data/maintenance.lock (JSON: since, reason, site_name) before
 * extraction and removes it after.
 *
 * @return array { present:bool, since:?int, age_sec:?int, reason:?string }
 */
function snap_maint_lock_state(): array {
    $path = dirname(__DIR__) . '/data/maintenance.lock';
    clearstatcache(true, $path);
    if (!is_file($path)) {
        return ['present' => false, 'since' => null, 'age_sec' => null, 'reason' => null];
    }
    $raw   = @file_get_contents($path);
    $j     = json_decode((string)$raw, true);
    $since = (is_array($j) && isset($j['since'])) ? (int)$j['since'] : null;
    return [
        'present' => true,
        'since'   => $since,
        'age_sec' => $since !== null ? (time() - $since) : null,
        'reason'  => is_array($j) ? ($j['reason'] ?? null) : null,
    ];
}

// ===== SNAPSMACK EOF =====
