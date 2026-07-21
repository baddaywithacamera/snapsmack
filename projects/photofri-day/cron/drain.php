<?php
// SNAPSMACK_EOF_HEADER: this file MUST end with // ===== SNAPSMACK EOF =====
/**
 * PHOTOFRI.DAY — delivery-queue drainer. Run from cron (e.g. every minute):
 *   * * * * * php /var/www/photofri.day/cron/drain.php >/dev/null 2>&1
 * The inbox also drains a little inline after each POST; this catches backoff.
 */
require_once __DIR__ . '/../lib/ap.php';
try { pfd_ensure_schema(); } catch (Throwable $e) {}
list($sent, $failed) = pfd_drain(200);
echo "photofri.day drain: sent={$sent} failed={$failed}\n";
// ===== SNAPSMACK EOF =====
