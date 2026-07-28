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
