<?php
// SNAPSMACK_EOF_HEADER: this file MUST end with // ===== SNAPSMACK EOF =====
/**
 * SMACKVERSE Relay — delivery queue drain. Run from cron every minute:
 *   * * * * * /usr/bin/php /path/to/smackverse-relay/cron/drain.php >> /var/log/relay-drain.log 2>&1
 * The inbox also drains inline after each POST; this cron catches stragglers +
 * backoff retries. CLI-only.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../lib/relay.php';

try {
    relay_ensure_schema();
    list($sent, $failed) = relay_drain(300);
    fwrite(STDOUT, date('c') . " drained: sent={$sent} failed={$failed}\n");
} catch (Throwable $e) {
    fwrite(STDERR, date('c') . ' drain error: ' . $e->getMessage() . "\n");
    exit(1);
}
// ===== SNAPSMACK EOF =====
