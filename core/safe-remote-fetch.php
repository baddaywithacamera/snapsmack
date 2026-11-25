<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * SNAPSMACK — one safe outbound GET, with the address pinned.
 *
 * WHY THIS FILE EXISTS. cron-directory-feeds.php already carries the strongest
 * outbound fetch in this repository: it resolves the host itself, refuses every
 * private, loopback and reserved address, and then PINS the connection to the
 * address it checked with CURLOPT_RESOLVE. That last part is the one that
 * matters and the one the other copies are missing -- checking a hostname and
 * then handing the same hostname to cURL leaves a gap in which DNS can answer
 * differently the second time and point the fetch at 127.0.0.1 or at the
 * Proxmox host. There are four looser copies of the check elsewhere in core/
 * (client-ip, fediverse, mesh-helpers, monthly-activity-summary,
 * multisite-api, photoblogs-feed), none of which pin.
 *
 * So this is the pinned one, factored out where anything may call it, rather
 * than a seventh copy written from memory. The existing callers are NOT
 * rewired here: they work, and changing a working security check while
 * building a feature is how a regression gets attributed to the wrong commit.
 * They are the code de-duplication audit's job, and this file is the home to
 * move them to.
 *
 * IT REFUSES RATHER THAN REPAIRS. A url it cannot prove safe returns null, and
 * the caller fetches nothing. There is no normalising, no stripping and no
 * "best effort": a redirect is not followed at all, because a followed
 * redirect is a second request to an address nobody checked.
 */

/** Hard ceiling on a single response, so a hostile server cannot fill the disk. */
const SNAP_REMOTE_MAX_BYTES = 2097152;

/**
 * Resolve a url to ONE public address, or refuse it.
 *
 * @return array{0:string,1:int,2:string}|null [host, port, ip], or null.
 */
function snap_safe_remote_target(string $url): ?array
{
    $parts = @parse_url(trim($url));
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }
    // CREDENTIALS IN A URL ARE REFUSED. http://user:pass@host is a way to make
    // a fetch carry a secret to somewhere it was not meant to go, and no
    // SnapSmack feed needs one.
    if (isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($port, [80, 443], true)) {
        return null;
    }
    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if ($host === '' || in_array($host, ['localhost', 'ip6-localhost', 'ip6-loopback'], true)) {
        return null;
    }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ip']))   { $ips[] = $record['ip']; }
            if (!empty($record['ipv6'])) { $ips[] = $record['ipv6']; }
        }
    }
    if (!$ips) {
        return null;
    }

    // EVERY ANSWER MUST BE PUBLIC, not merely the first one. A host that
    // answers with one public address and one loopback address is refused:
    // taking the first public answer and ignoring the rest would let the
    // ordering of a DNS reply decide whether the check passes.
    foreach (array_unique($ips) as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
    }

    return [$host, $port, (string)array_values(array_unique($ips))[0]];
}

/**
 * GET one url from a pinned public address.
 *
 * @return array{ok:bool,code:int,body:string,error:string}
 */
function snap_safe_remote_get(
    string $url,
    string $agent,
    array $accept = [],
    int $maxBytes = SNAP_REMOTE_MAX_BYTES,
    int $timeout = 12
): array {
    $target = snap_safe_remote_target($url);
    if ($target === null) {
        return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'refused: not a verifiably public http(s) address'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'refused: cURL is not available, and the plain stream wrapper cannot pin an address'];
    }
    [$host, $port, $ip] = $target;

    $body = '';
    $overflowed = false;
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        // NO REDIRECTS. A redirect is a request to an address this function
        // never resolved or checked, which is exactly what the pin prevents.
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_REDIR_PROTOCOLS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => max(1, $timeout),
        CURLOPT_USERAGENT => $agent,
        CURLOPT_HTTPHEADER => $accept,
        // THE PIN. cURL connects to the address this function proved public,
        // not to whatever DNS answers a second time.
        CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $ip)],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$overflowed, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) { $overflowed = true; return 0; }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $ran = curl_exec($handle);
    $code = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = (string)curl_error($handle);
    curl_close($handle);

    if ($overflowed) {
        return ['ok' => false, 'code' => $code, 'body' => '', 'error' => 'refused: response larger than ' . $maxBytes . ' bytes'];
    }
    if ($ran === false || $code !== 200 || $body === '') {
        return ['ok' => false, 'code' => $code, 'body' => '', 'error' => $error !== '' ? $error : 'HTTP ' . $code];
    }
    return ['ok' => true, 'code' => $code, 'body' => $body, 'error' => ''];
}

// ===== SNAPSMACK EOF =====
