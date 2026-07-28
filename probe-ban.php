<?php
/**
 * SNAPSMACK - Probe Ban Handler
 *
 * Receives requests for known scanner/exploit paths (wp-login.php,
 * xmlrpc.php, .env probes, shell uploads, etc.) routed here by .htaccess.
 * Bans the source IP for 30 days and returns a 403 with no body.
 *
 * Accessed only via RewriteRule — never directly by legitimate visitors.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


// Bootstrap DB (defines $pdo)
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/client-ip.php';

// Resolve the client address through the shared trusted resolver (SECAUDIT 035).
// Forwarded headers are honoured ONLY when the peer is a configured trusted
// proxy, so a direct request can no longer nominate someone else to be banned.
$ip = snap_trusted_client_ip($pdo);

// Refusing the request is always right; RECORDING a ban is not. Private,
// reserved, loopback and our own proxy addresses can only be our own
// infrastructure — banning those takes us down, not the scanner.
if (snap_ip_is_bannable($ip, $pdo)) {
    try {
        $pdo->prepare(
            "INSERT INTO snap_ip_bans (ip, reason, banned_at, expires_at)
             VALUES (?, 'auto:probe', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY))
             ON DUPLICATE KEY UPDATE
               reason = 'auto:probe', banned_at = NOW(),
               expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)"
        )->execute([$ip]);
    } catch (PDOException $e) {
        // Non-fatal — still 403 even if DB write fails
    }
}

http_response_code(403);
exit;
// ===== SNAPSMACK EOF =====
