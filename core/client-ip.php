<?php
/**
 * SNAPSMACK - Trusted client-address resolution
 *
 * ONE resolver for every subsystem that records or enforces an entry in
 * snap_ip_bans. Introduced by SECAUDIT 035 (2026-07-27).
 *
 * THE RULE: X-Forwarded-For and CF-Connecting-IP are assertions made by
 * whoever sent the request. They are meaningful ONLY when the machine that
 * actually connected is a proxy we have chosen to trust. On a direct
 * connection the client may claim to be anyone, so its claims are discarded
 * and REMOTE_ADDR — set by the web server from the real TCP peer, and not
 * forgeable — is used instead.
 *
 * Before this file existed, two subsystems (probe-ban.php and snap-in.php)
 * preferred the forgeable headers unconditionally. That let an unauthenticated
 * visitor ban any address it named, and let an attacker evade login
 * brute-force limiting entirely by varying the header on each attempt.
 *
 * CONFIGURATION: snap_settings key `trusted_proxies`, comma-separated IPs or
 * CIDR ranges.
 * Default is loopback only, which is correct for a same-host Cloudflare Tunnel
 * or nginx. If the proxy is on another host you MUST add its address, or every
 * visitor will resolve to that proxy and per-client rate limiting becomes
 * per-site rate limiting.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

if (!function_exists('snap_trusted_proxies')) {

/**
 * Addresses entitled to assert a forwarded client address.
 * Fails safe: any lookup problem keeps the loopback-only default rather than
 * widening trust.
 */
function snap_trusted_proxies(?PDO $pdo = null): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $raw = '127.0.0.1,::1';
    if ($pdo instanceof PDO) {
        try {
            $v = $pdo->query(
                "SELECT setting_val FROM snap_settings WHERE setting_key='trusted_proxies' LIMIT 1"
            )->fetchColumn();
            if ($v !== false && trim((string)$v) !== '') $raw = (string)$v;
        } catch (Throwable $e) {
            // keep the default — never widen trust because a query failed
        }
    }

    return $cache = snap_parse_trusted_proxies($raw);
}

/**
 * Validate and normalize a comma-separated trusted-proxy configuration.
 */
function snap_parse_trusted_proxies(string $raw): array {
    $out = [];
    foreach (explode(',', $raw) as $entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        if (filter_var($entry, FILTER_VALIDATE_IP)) {
            $out[] = $entry;
            continue;
        }
        if (preg_match('#^([^/]+)/(\d{1,3})$#', $entry, $m)
            && filter_var($m[1], FILTER_VALIDATE_IP)) {
            $packed = inet_pton($m[1]);
            $bits = strlen($packed) * 8;
            $prefix = (int)$m[2];
            if ($prefix >= 0 && $prefix <= $bits) {
                $out[] = $m[1] . '/' . $prefix;
            }
        }
    }
    return array_values(array_unique($out));
}

function snap_ip_in_cidr(string $ip, string $cidr): bool {
    if (!preg_match('#^([^/]+)/(\d{1,3})$#', $cidr, $m)) return false;
    $address = @inet_pton($ip);
    $network = @inet_pton($m[1]);
    if ($address === false || $network === false || strlen($address) !== strlen($network)) return false;
    $prefix = (int)$m[2];
    $bits = strlen($address) * 8;
    if ($prefix < 0 || $prefix > $bits) return false;
    $bytes = intdiv($prefix, 8);
    $remainder = $prefix % 8;
    if ($bytes > 0 && substr($address, 0, $bytes) !== substr($network, 0, $bytes)) return false;
    if ($remainder === 0) return true;
    $mask = (0xff << (8 - $remainder)) & 0xff;
    return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
}

function snap_ip_is_trusted_proxy(string $ip, array $trusted): bool {
    foreach ($trusted as $entry) {
        if ($ip === $entry || (str_contains($entry, '/') && snap_ip_in_cidr($ip, $entry))) return true;
    }
    return false;
}

/**
 * The address that actually connected, or — if it connected through a trusted
 * proxy — the client that proxy is vouching for.
 */
function snap_trusted_client_ip(?PDO $pdo = null): string {
    $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!filter_var($peer, FILTER_VALIDATE_IP)) return '0.0.0.0';

    $trusted = snap_trusted_proxies($pdo);

    // Direct connection: the peer IS the client. Ignore anything it claims.
    if (!snap_ip_is_trusted_proxy($peer, $trusted)) return $peer;

    // Cloudflare sets exactly one authoritative value.
    $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) return $cf;

    // Otherwise walk X-Forwarded-For from the RIGHT — the rightmost entry was
    // appended by the nearest proxy and is the most trustworthy. Skip further
    // trusted hops; the first untrusted address is the real client. Reading
    // left-to-right instead would take the attacker-controlled end of the list.
    $fwd = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($fwd !== '') {
        $chain = array_map('trim', explode(',', $fwd));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $c = $chain[$i];
            if (!filter_var($c, FILTER_VALIDATE_IP)) continue;
            if (snap_ip_is_trusted_proxy($c, $trusted)) continue;
            return $c;
        }
    }

    return $peer;
}

/**
 * May this address have a ban recorded against it?
 *
 * Refusing the request is always correct; RECORDING a ban is not. Private,
 * reserved, loopback and our own proxy addresses can only ever be our own
 * infrastructure, and banning them takes the site down rather than the
 * attacker. Callers should still return 403 when this returns false.
 */
function snap_ip_is_bannable(string $ip, ?PDO $pdo = null): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    if (snap_ip_is_trusted_proxy($ip, snap_trusted_proxies($pdo))) return false;
    return true;
}

/**
 * Keep limiter storage bounded and clear potentially forged pre-fix bans.
 *
 * The updater's database backup makes the one-time reset recoverable. Manual
 * moderation is preserved; only old automatic rows are reset.
 */
function snap_ip_ban_maintenance(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $marker = $pdo->prepare(
            "INSERT IGNORE INTO snap_settings (setting_key, setting_val)
             VALUES ('secaudit_035_ban_reset', 'running')"
        );
        $marker->execute();
        if ($marker->rowCount() === 1) {
            try {
                $pdo->exec("DELETE FROM snap_ip_bans WHERE reason LIKE 'auto:%'");
                $rows = $pdo->query(
                    "SELECT id, ip FROM snap_ip_bans ORDER BY id ASC"
                )->fetchAll(PDO::FETCH_ASSOC);
                $delete = $pdo->prepare("DELETE FROM snap_ip_bans WHERE id = ?");
                foreach ($rows as $row) {
                    if (!snap_ip_is_bannable((string)$row['ip'], $pdo)) {
                        $delete->execute([(int)$row['id']]);
                    }
                }
                $complete = $pdo->prepare(
                    "UPDATE snap_settings SET setting_val = ?
                     WHERE setting_key = 'secaudit_035_ban_reset'"
                );
                $complete->execute([gmdate('Y-m-d H:i:s')]);
            } catch (Throwable $cleanup_error) {
                $pdo->exec(
                    "DELETE FROM snap_settings
                     WHERE setting_key = 'secaudit_035_ban_reset'
                       AND setting_val = 'running'"
                );
                throw $cleanup_error;
            }
        }

        $pdo->exec("DELETE FROM snap_ip_bans WHERE expires_at <= NOW()");
        $pdo->exec(
            "DELETE FROM snap_rate_limits
             WHERE window_start < DATE_SUB(NOW(), INTERVAL 2 DAY)"
        );
    } catch (Throwable $e) {
        error_log('SnapSmack IP-ban maintenance failed: ' . $e->getMessage());
    }
}

/**
 * Record one fixed-lifetime automatic ban. Active duplicates are deliberately
 * left untouched, and global caps prevent rotating-address storage abuse.
 */
function snap_ip_record_ban(
    PDO $pdo,
    string $ip,
    string $reason,
    int $lifetime_seconds,
    bool $owner_alert = false
): bool {
    snap_ip_ban_maintenance($pdo);
    if (!snap_ip_is_bannable($ip, $pdo)) return false;

    $lifetime_seconds = max(60, min($lifetime_seconds, 30 * 86400));
    try {
        $existing = $pdo->prepare(
            "SELECT 1 FROM snap_ip_bans WHERE ip = ? AND expires_at > NOW() LIMIT 1"
        );
        $existing->execute([$ip]);
        if ($existing->fetchColumn()) return true;

        $expired = $pdo->prepare(
            "DELETE FROM snap_ip_bans WHERE ip = ? AND expires_at <= NOW()"
        );
        $expired->execute([$ip]);

        $recent = (int)$pdo->query(
            "SELECT COUNT(*) FROM snap_ip_bans
             WHERE banned_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        )->fetchColumn();
        $total = (int)$pdo->query("SELECT COUNT(*) FROM snap_ip_bans")->fetchColumn();
        if ($recent >= 250 || $total >= 10000) {
            error_log('SnapSmack IP-ban insertion cap reached; request refused without recording a ban');
            return false;
        }

        $banned_at = gmdate('Y-m-d H:i:s');
        $expires_at = gmdate('Y-m-d H:i:s', time() + $lifetime_seconds);
        $insert = $pdo->prepare(
            "INSERT IGNORE INTO snap_ip_bans (ip, reason, banned_at, expires_at)
             VALUES (?, ?, ?, ?)"
        );
        $insert->execute([$ip, $reason, $banned_at, $expires_at]);
        $created = $insert->rowCount() === 1;
        if ($created && $owner_alert) {
            snap_ip_send_owner_ban_alert($pdo, $ip, $reason, $expires_at);
        }
        return $created;
    } catch (Throwable $e) {
        error_log('SnapSmack IP ban write failed: ' . $e->getMessage());
        return false;
    }
}

/** Best-effort out-of-band warning for a login-address lockout. */
function snap_ip_send_owner_ban_alert(
    PDO $pdo,
    string $ip,
    string $reason,
    string $expires_at
): void {
    try {
        require_once __DIR__ . '/mailer.php';
        $settings = snapsmack_mail_settings($pdo);
        $to = trim((string)($settings['admin_email'] ?? ''));
        if ($to === '') return;
        $site = (string)($settings['site_name'] ?? 'SnapSmack');
        $url = rtrim((string)($settings['site_url'] ?? ''), '/');
        $body = "SnapSmack blocked repeated failed administrator logins.\n\n"
              . "Site: {$site}\nAddress: {$ip}\nReason: {$reason}\nExpires (UTC): {$expires_at}\n\n"
              . "If this was you, use your current BREAK THE GLASS recovery card or remove the address in "
              . "Troll Control > IP Shield after reaching the site from another address.\n"
              . ($url !== '' ? "Site: {$url}\n" : '');
        snapsmack_send_mail(
            $to,
            "[{$site}] administrator login address blocked",
            $body,
            ['pdo' => $pdo, 'settings' => $settings]
        );
    } catch (Throwable $e) {
        error_log('SnapSmack IP-ban owner alert failed: ' . $e->getMessage());
    }
}

/**
 * Owner-facing diagnostic without exposing untrusted header values as truth.
 */
function snap_client_ip_diagnostic(?PDO $pdo = null): array {
    $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $trusted = snap_trusted_proxies($pdo);
    $peer_trusted = filter_var($peer, FILTER_VALIDATE_IP)
        && snap_ip_is_trusted_proxy($peer, $trusted);
    $selected = snap_trusted_client_ip($pdo);
    $source = 'direct peer';
    if ($peer_trusted && filter_var(trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '')), FILTER_VALIDATE_IP)) {
        $source = 'CF-Connecting-IP from trusted proxy';
    } elseif ($peer_trusted && trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) !== '' && $selected !== $peer) {
        $source = 'X-Forwarded-For from trusted proxy';
    } elseif ($peer_trusted) {
        $source = 'trusted proxy peer (no valid forwarded client)';
    }
    return [
        'observed_peer' => $peer !== '' ? $peer : 'missing',
        'selected_client' => $selected,
        'peer_trusted' => (bool)$peer_trusted,
        'source' => $source,
        'trusted_proxies' => $trusted,
    ];
}

}
// ===== SNAPSMACK EOF =====
