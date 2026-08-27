<?php
/**
 * SNAPSMACK — photoblogs.fyi DIRECTORY (spoke side)  [0.7.547]
 *
 * A standard blog's opt-in to be LISTED in the photoblogs.fyi directory.
 * This is deliberately SEPARATE from two things it is easy to confuse it with:
 *   - JOIN NETWORK (the fan-out relay — posts flow between blogs)
 *   - ROLL CALL    (lists the blog on fediverse.info, a different directory)
 * Listing here puts the blog on photoblogs.fyi's own people-finder. Turning it
 * ON is step-up gated (password + 2FA) because a public listing reflects on the
 * whole network. Nothing is listed until the hub admin approves it.
 *
 * Submit pattern copied from sv_rollcall_submit() in core/smackverse.php:
 * one deliberate call per admin save, fail-soft, breadcrumb to the error log.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the marker above.
 */

if (!defined('PBDIR_DEFAULT_HUB')) define('PBDIR_DEFAULT_HUB', 'https://photoblogs.fyi');

/** The hub this blog lists on (override with the photoblogs_hub setting). */
function pbdir_hub_url(array $settings): string {
    $u = trim((string)($settings['photoblogs_hub'] ?? ''));
    return $u !== '' ? rtrim($u, '/') : PBDIR_DEFAULT_HUB;
}

/** Is this blog currently opted in to the photoblogs.fyi directory? */
function pbdir_is_listed(array $settings): bool {
    return ($settings['photoblogs_listed'] ?? '0') === '1';
}

/** Topics (genres) the blog files itself under — deduped, trimmed. */
function pbdir_topics(array $settings): array {
    $raw = (string)($settings['photoblogs_topics'] ?? 'photography');
    $out = [];
    foreach (explode(',', $raw) as $t) {
        $t = trim($t);
        if ($t === '') continue;
        $lower = array_map('strtolower', $out);
        if (!in_array(strtolower($t), $lower, true)) $out[] = $t;
    }
    return array_slice($out, 0, 12);
}

/**
 * The listing payload sent to the hub. Identity comes from site settings; the
 * fediverse handle from the smackverse helpers when federation is on. All fields
 * are best-effort — a blank avatar/samples just yields a text-only card.
 */
function pbdir_payload(array $settings): array {
    $handle = function_exists('sv_handle') ? sv_handle($settings) : '';
    $domain = function_exists('sv_domain')
        ? sv_domain($settings)
        : (string)(parse_url((string)($settings['site_url'] ?? ''), PHP_URL_HOST) ?? '');
    return [
        'site_url'    => rtrim((string)($settings['site_url'] ?? ''), '/'),
        'handle'      => ($handle !== '' && $domain !== '') ? '@' . $handle . '@' . $domain : '',
        'name'        => (string)($settings['site_name'] ?? ''),
        'description' => (string)($settings['site_description'] ?? $settings['site_tagline'] ?? ''),
        'topics'      => pbdir_topics($settings),
        'avatar_url'  => (string)($settings['site_logo'] ?? ''),
        'feed_url'    => rtrim((string)($settings['site_url'] ?? ''), '/') . '/rss.php',
        'samples'     => [],  // sample thumbnails: follow-up (kept optional by design)
        'software'    => 'SnapSmack',
        'version'     => defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '',
    ];
}

/**
 * Submit (register) or withdraw (remove) this blog's listing at the hub.
 * @param string $mode 'register' | 'remove'
 * @return array [bool ok, string message-for-the-admin]
 */
function pbdir_submit(array $settings, string $mode): array {
    $hub = pbdir_hub_url($settings);
    if (rtrim((string)($settings['site_url'] ?? ''), '/') === '') {
        return [false, 'Set your Site URL in Settings first — the directory needs it.'];
    }
    $url = $hub . '/directory-api.php?action=' . ($mode === 'remove' ? 'remove' : 'register');
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(pbdir_payload($settings), JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_USERAGENT      => 'SnapSmack/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '') . ' (+https://snapsmack.ca)',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    error_log('PBDIR ' . $mode . ' url=' . $url . ' http=' . $code
              . ($err !== '' ? ' curlerr=' . $err : '')
              . ' body=' . substr(is_string($body) ? $body : '', 0, 300));

    if ($body === false || $code === 0) {
        return [false, "couldn't reach " . $hub . ' (' . ($err ?: 'network error') . ')'];
    }
    $json = json_decode((string)$body, true);
    if ($code >= 200 && $code < 300) {
        if ($mode === 'remove') return [true, 'Removed from the photoblogs.fyi directory.'];
        $state = (is_array($json) && isset($json['state'])) ? (string)$json['state'] : '';
        return [true, $state === 'active'
            ? 'Listed on photoblogs.fyi.'
            : 'Submitted to photoblogs.fyi — pending review before it appears.'];
    }
    $emsg = (is_array($json) && isset($json['error'])) ? (string)$json['error'] : ('HTTP ' . $code);
    return [false, 'photoblogs.fyi rejected the request: ' . $emsg];
}

// ===== SNAPSMACK EOF =====
