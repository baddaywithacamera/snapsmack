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

// Bootstrap DB (defines $pdo)
require_once __DIR__ . '/core/db.php';

// Resolve real IP: Cloudflare Tunnel passes CF-Connecting-IP
$ip = trim(explode(',', (
    $_SERVER['HTTP_CF_CONNECTING_IP']
    ?? $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? '0.0.0.0'
))[0]);

if ($ip && $ip !== '0.0.0.0') {
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
// EOF
