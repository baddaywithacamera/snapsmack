<?php
/**
 * SNAPSMACK - Shared login-protection helpers
 *
 * Central home for the brute-force / bot / IP-ban defenses used by BOTH admin
 * login entry points (snap-in.php via the login slug, and login.php). Extracted
 * so the two portals can never drift apart in coverage again — historically
 * login.php shipped with none of snap-in.php's protections, leaving an
 * unthrottled credential-stuffing door into admin accounts.
 *
 * Functions are function_exists-guarded so this file is safe to include even in
 * a request that already defined equivalents.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!function_exists('snap_client_ip')) {
    /**
     * Resolve the real client IP, trusting X-Forwarded-For only for the first
     * hop (Cloudflare / one reverse proxy). Falls back to REMOTE_ADDR.
     */
    function snap_client_ip(): string {
        $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($fwd !== '') {
            $first = trim(explode(',', $fwd)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
        return filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP)
            ? $_SERVER['REMOTE_ADDR']
            : '0.0.0.0';
    }
}

if (!function_exists('snap_record_login_failure')) {
    /**
     * Record a failed login attempt for the given IP. On the 5th failure within
     * a 10-minute window, auto-ban the IP for 7 days and clear the counter.
     */
    function snap_record_login_failure(PDO $pdo, string $ip): void {
        $pdo->prepare(
            "INSERT INTO snap_rate_limits (ip, action, count, window_start)
             VALUES (?, 'login_fail', 1, NOW())
             ON DUPLICATE KEY UPDATE
               count        = IF(window_start < DATE_SUB(NOW(), INTERVAL 10 MINUTE), 1, count + 1),
               window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL 10 MINUTE), NOW(), window_start)"
        )->execute([$ip]);

        $row = $pdo->prepare(
            "SELECT count FROM snap_rate_limits
             WHERE ip = ? AND action = 'login_fail'
               AND window_start >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );
        $row->execute([$ip]);
        $fail_count = (int)($row->fetchColumn() ?: 0);

        if ($fail_count >= 5) {
            $pdo->prepare(
                "INSERT INTO snap_ip_bans (ip, reason, banned_at, expires_at)
                 VALUES (?, 'auto:brute_force', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))
                 ON DUPLICATE KEY UPDATE
                   reason = VALUES(reason), banned_at = NOW(), expires_at = VALUES(expires_at)"
            )->execute([$ip]);
            $pdo->prepare(
                "DELETE FROM snap_rate_limits WHERE ip = ? AND action = 'login_fail'"
            )->execute([$ip]);
        }
    }
}

if (!function_exists('snap_login_guard')) {
    /**
     * Run the pre-authentication gate shared by both login portals:
     *   1. Reject blank / obviously-scripted user agents (403).
     *   2. Reject IPs with an active ban (403).
     * Returns the resolved client IP so the caller can pass it to
     * snap_record_login_failure() on a bad attempt.
     *
     * NOTE: intentionally does NOT include snap-in.php's direct-access 403,
     * because login.php is a deliberately-reachable endpoint (linked from the
     * installer, password-reset flow, recovery email, and SSO).
     */
    function snap_login_guard(PDO $pdo): string {
        // --- USER-AGENT FILTER ---
        $ua = trim($_SERVER['HTTP_USER_AGENT'] ?? '');
        $ua_bot_rx = '#^$|curl/|python-?requests?/|python/\d|Go-http-client/|'
                   . 'libwww-perl/|Wget/|Scrapy/|mechanize|Java/\d|Nikto|'
                   . 'masscan|sqlmap|Nmap|DirBuster|zgrab|Hydra|WPScan|'
                   . 'nuclei|zgrab|dirsearch|gobuster|ffuf#i';
        if (preg_match($ua_bot_rx, $ua)) {
            http_response_code(403);
            exit;
        }

        // --- IP BAN GATE ---
        $ip = snap_client_ip();
        try {
            $ban = $pdo->prepare(
                "SELECT expires_at FROM snap_ip_bans WHERE ip = ? AND expires_at > NOW() LIMIT 1"
            );
            $ban->execute([$ip]);
            if ($ban->fetchColumn()) {
                http_response_code(403);
                exit;
            }
        } catch (PDOException $e) {
            // Table may not exist yet on older installs — fail open so login works.
        }
        return $ip;
    }
}
// ===== SNAPSMACK EOF =====
