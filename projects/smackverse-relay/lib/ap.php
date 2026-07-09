<?php
// SNAPSMACK_EOF_HEADER: this file MUST end with // ===== SNAPSMACK EOF =====
/**
 * SMACKVERSE Relay — ActivityPub primitives (keys, actor doc, HTTP-signature
 * sign + verify, SSRF-guarded fetch, signed POST delivery + queue). Faithful to
 * the SnapSmack core/smackverse.php implementation, stripped to relay needs.
 */

require_once __DIR__ . '/db.php';

/** Ensure the relay keypair exists; return [privatePem, publicPem]. */
function relay_keys(): array {
    $priv = relay_setting('private_key', '');
    $pub  = relay_setting('public_key', '');
    if ($priv !== '' && $pub !== '') return [$priv, $pub];
    $res = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($res, $priv);
    $d   = openssl_pkey_get_details($res);
    $pub = $d['key'];
    relay_set('private_key', $priv);
    relay_set('public_key', $pub);
    return [$priv, $pub];
}

/** The relay's service actor (type Application — a relay, not a person). */
function relay_actor_doc(): array {
    list(, $pub) = relay_keys();
    return [
        '@context' => ['https://www.w3.org/ns/activitystreams', 'https://w3id.org/security/v1'],
        'id'                => relay_actor_url(),
        'type'              => 'Application',
        'preferredUsername' => 'relay',
        'name'              => 'SMACKVERSE Relay',
        'summary'           => 'The SnapSmack network relay. Subscribe to share public posts across all SnapSmack blogs. No images are stored here — media always loads from the origin blog.',
        'inbox'             => relay_inbox_url(),
        'outbox'            => relay_base() . 'outbox',
        'followers'         => relay_base() . 'followers',
        'following'         => relay_base() . 'following',
        'endpoints'         => ['sharedInbox' => relay_inbox_url()],
        'url'               => relay_base(),
        'manuallyApprovesFollowers' => (relay_setting('open_mode', 'allowlist') !== 'open'),
        'publicKey'         => [
            'id'           => relay_key_id(),
            'owner'        => relay_actor_url(),
            'publicKeyPem' => $pub,
        ],
    ];
}

function relay_webfinger_doc(string $resource): ?array {
    $c = relay_config();
    $want = 'acct:relay@' . ($c['domain'] ?? '');
    if (strcasecmp($resource, $want) !== 0) return null;
    return [
        'subject' => $want,
        'links'   => [[
            'rel'  => 'self',
            'type' => 'application/activity+json',
            'href' => relay_actor_url(),
        ]],
    ];
}

// ── SSRF guard ────────────────────────────────────────────────────────────────
/** Resolve a URL's host to a public IP; return ['pin'=>[...]] for cURL or null. */
function relay_resolve_public(string $url): ?array {
    $p = parse_url($url);
    if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return null;
    $host = $p['host'];
    $port = (int)($p['port'] ?? 443);
    $ips  = @gethostbynamel($host);
    if (!$ips) {
        $rec = @dns_get_record($host, DNS_AAAA);
        $ips = [];
        foreach (($rec ?: []) as $r) { if (!empty($r['ipv6'])) $ips[] = $r['ipv6']; }
        if (!$ips) return null;
    }
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null; // any private/reserved IP → refuse (DNS-rebinding safe)
        }
    }
    return ['pin' => [$host . ':' . $port . ':' . implode(',', $ips)]];
}

function relay_url_is_public(string $url): bool { return relay_resolve_public($url) !== null; }

// ── Signing ───────────────────────────────────────────────────────────────────
/** Signed headers for a POST (draft-cavage): (request-target) host date digest content-type. */
function relay_sign_post(string $url, string $body): ?array {
    list($priv) = relay_keys();
    $pkey = openssl_pkey_get_private($priv);
    if ($pkey === false) return null;
    $p      = parse_url($url);
    $host   = $p['host'] ?? '';
    $path   = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    $date   = gmdate('D, d M Y H:i:s') . ' GMT';
    $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    $ctype  = 'application/activity+json';
    $signing = "(request-target): post {$path}\nhost: {$host}\ndate: {$date}\ndigest: {$digest}\ncontent-type: {$ctype}";
    if (!openssl_sign($signing, $sig, $pkey, OPENSSL_ALGO_SHA256)) return null;
    $hdr = 'keyId="' . relay_key_id() . '",algorithm="rsa-sha256",'
         . 'headers="(request-target) host date digest content-type",signature="' . base64_encode($sig) . '"';
    return [
        'Host: ' . $host, 'Date: ' . $date, 'Digest: ' . $digest,
        'Content-Type: ' . $ctype, 'Signature: ' . $hdr,
        'Accept: application/activity+json',
        'User-Agent: SMACKVERSE-Relay/1.0',
    ];
}

/** Signed headers for a GET (authorized-fetch): (request-target) host date. */
function relay_sign_get(string $url): array {
    list($priv) = relay_keys();
    $out = ['Accept: application/activity+json, application/ld+json', 'User-Agent: SMACKVERSE-Relay/1.0'];
    $pkey = openssl_pkey_get_private($priv);
    if ($pkey === false) return $out;
    $p    = parse_url($url);
    $host = $p['host'] ?? '';
    $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    $date = gmdate('D, d M Y H:i:s') . ' GMT';
    $signing = "(request-target): get {$path}\nhost: {$host}\ndate: {$date}";
    if (!openssl_sign($signing, $sig, $pkey, OPENSSL_ALGO_SHA256)) return $out;
    $hdr = 'keyId="' . relay_key_id() . '",algorithm="rsa-sha256",headers="(request-target) host date",signature="' . base64_encode($sig) . '"';
    return array_merge(['Host: ' . $host, 'Date: ' . $date, 'Signature: ' . $hdr], $out);
}

// ── Fetch ─────────────────────────────────────────────────────────────────────
function relay_fetch_ap(string $url): ?array {
    if (!function_exists('curl_init')) return null;
    $res = relay_resolve_public($url);
    if ($res === null) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE        => $res['pin'],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => relay_sign_get($url),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300 || strlen($body) > 524288) return null;
    $doc = json_decode($body, true);
    return is_array($doc) ? $doc : null;
}

// ── Verify inbound signature ──────────────────────────────────────────────────
/** Normalised lowercase request headers. */
function relay_req_headers(): array {
    $h = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($k, 5)));
            $h[$name] = $v;
        }
    }
    if (isset($_SERVER['CONTENT_TYPE']))   $h['content-type']   = $_SERVER['CONTENT_TYPE'];
    if (isset($_SERVER['CONTENT_LENGTH'])) $h['content-length'] = $_SERVER['CONTENT_LENGTH'];
    return $h;
}

/**
 * Verify the inbound HTTP signature. Returns the sender's actor doc on success,
 * null otherwise. Fetches the signer actor (signed GET) to get its public key.
 */
function relay_verify_signature(string $raw): ?array {
    $h = relay_req_headers();
    $sig = $h['signature'] ?? '';
    if ($sig === '') return null;

    $parts = [];
    foreach (explode(',', $sig) as $seg) {
        if (preg_match('/([a-zA-Z]+)="(.*)"/', trim($seg), $m)) $parts[$m[1]] = $m[2];
    }
    $keyId   = $parts['keyId']     ?? '';
    $headers = $parts['headers']   ?? '(request-target) host date';
    $sigb64  = $parts['signature'] ?? '';
    if ($keyId === '' || $sigb64 === '') return null;

    // Digest check (body integrity) when present.
    if (isset($h['digest'])) {
        $want = 'SHA-256=' . base64_encode(hash('sha256', $raw, true));
        if (!hash_equals($want, trim($h['digest']))) return null;
    }
    // Date window ±1h.
    if (isset($h['date']) && abs(time() - strtotime($h['date'])) > 3600) return null;

    // Fetch signer actor to get the public key.
    $actor = relay_fetch_ap($keyId);
    if (!is_array($actor)) return null;
    // keyId may point at #main-key on the actor; unwrap to the actor doc.
    if (($actor['type'] ?? '') !== 'Application' && empty($actor['publicKey']) && !empty($actor['owner'])) {
        $actor = relay_fetch_ap((string)$actor['owner']) ?: $actor;
    }
    $pem = $actor['publicKey']['publicKeyPem'] ?? '';
    if ($pem === '' && isset($actor['publicKeyPem'])) $pem = $actor['publicKeyPem'];
    if ($pem === '') return null;

    // Rebuild the signing string from the named headers.
    $method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'post');
    $target = ($_SERVER['REQUEST_URI'] ?? '/');
    $lines = [];
    foreach (explode(' ', $headers) as $name) {
        if ($name === '(request-target)') { $lines[] = "(request-target): {$method} {$target}"; }
        else { $lines[] = $name . ': ' . ($h[$name] ?? ''); }
    }
    $signing = implode("\n", $lines);
    $ok = openssl_verify($signing, base64_decode($sigb64), $pem, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) return null;

    // Ensure we return the full actor doc (with id/inbox).
    if (empty($actor['id']) || empty($actor['inbox'])) {
        $owner = (string)($actor['owner'] ?? '');
        if ($owner !== '') { $full = relay_fetch_ap($owner); if (is_array($full)) $actor = $full; }
    }
    return (!empty($actor['id']) && !empty($actor['inbox'])) ? $actor : null;
}

// ── Delivery ──────────────────────────────────────────────────────────────────
function relay_deliver(string $inbox, string $json): array {
    if (!function_exists('curl_init')) return [false, 'curl missing'];
    $res = relay_resolve_public($inbox);
    if ($res === null) return [false, 'inbox not public'];
    $headers = relay_sign_post($inbox, $json);
    if ($headers === null) return [false, 'signing failed'];
    $ch = curl_init($inbox);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $json,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE        => $res['pin'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300) ? [true, 'ok ' . $code] : [false, 'http ' . $code];
}

function relay_queue(string $inbox, string $json): void {
    relay_db()->prepare("INSERT INTO relay_deliveries (inbox_url, activity_json) VALUES (?, ?)")
        ->execute([$inbox, $json]);
}

/** Drain the delivery queue with exponential backoff (call from cron + inline). */
function relay_drain(int $limit = 50): array {
    $now = date('Y-m-d H:i:s');
    $due = relay_db()->prepare(
        "SELECT * FROM relay_deliveries WHERE status = 'queued' AND next_try_at <= ? ORDER BY id ASC LIMIT " . (int)$limit
    );
    $due->execute([$now]);
    $sent = 0; $failed = 0;
    foreach ($due->fetchAll() as $row) {
        list($ok, $info) = relay_deliver($row['inbox_url'], $row['activity_json']);
        if ($ok) {
            relay_db()->prepare("DELETE FROM relay_deliveries WHERE id = ?")->execute([$row['id']]);
            $sent++;
            continue;
        }
        $failed++;
        $attempts = (int)$row['attempts'] + 1;
        if ($attempts >= 8) {
            relay_db()->prepare("UPDATE relay_deliveries SET status='failed', attempts=?, last_error=? WHERE id=?")
                ->execute([$attempts, $info, $row['id']]);
        } else {
            $delay = min(300 * (2 ** $attempts), 86400);
            relay_db()->prepare(
                "UPDATE relay_deliveries SET attempts=?, last_error=?, next_try_at=DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id=?"
            )->execute([$attempts, $info, $delay, $row['id']]);
        }
    }
    return [$sent, $failed];
}
// ===== SNAPSMACK EOF =====
