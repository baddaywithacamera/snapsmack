<?php
/**
 * SNAPSMACK — SMACKVERSE library (ActivityPub, v0.2 FOLLOW + DELIVER)
 *
 * Library for the SMACKVERSE federation endpoints (smackverse.php router)
 * and the delivery cron (cron-smackverse.php).
 * Spec: _spec/smackverse-activitypub-spec-v0_1.md (v0.2 section).
 *
 * v0.2 scope on top of the v0.1 discovery skeleton:
 *   - HTTP Signature verification (draft-cavage, what Mastodon speaks) on
 *     inbound inbox POSTs, incl. Digest + Date-window checks and an
 *     SSRF-guarded remote-actor fetch.
 *   - Follow → store follower (snap_ap_followers) → queue signed Accept.
 *     Undo Follow / actor Delete → deactivate follower.
 *   - Note builder: one published snap_image = one Note (mirrors rss.php:
 *     permalink = site_url + img_slug), attachments, hashtags from
 *     snap_image_tags. Dereferenceable Note JSON served at ?ap=note&id=N.
 *   - Outbound delivery queue (snap_ap_deliveries) with signed POSTs and
 *     exponential backoff, processed by cron-smackverse.php.
 *   - Publish sweep (PULL model — no posting-flow edits anywhere): the cron
 *     federates images published since the last-federated marker. First run
 *     initialises the marker to NOW so an existing library (e.g. 10k Flickr
 *     imports) is NEVER blasted to followers.
 *
 * EVERYTHING is gated on the smackverse_enabled setting (absent = OFF).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// ─── Identity / config ──────────────────────────────────────────────────────

/** Is federation switched on for this install? Absent setting = OFF. */
function sv_enabled(array $settings): bool {
    return ($settings['smackverse_enabled'] ?? '0') === '1';
}

/**
 * Canonical public base URL with trailing slash. Prefers the site_url
 * setting (what rss.php uses for permalinks) so federation ids stay stable
 * even if the request arrives on an alternate host; falls back to BASE_URL.
 */
function sv_base(array $settings): string {
    $u = trim($settings['site_url'] ?? '');
    if ($u === '') $u = BASE_URL;
    return rtrim($u, '/') . '/';
}

/** The domain this install answers WebFinger for. */
function sv_domain(array $settings): string {
    return parse_url(sv_base($settings), PHP_URL_HOST) ?: '';
}

/**
 * The actor's preferredUsername. smackverse_handle setting wins; otherwise
 * the site_name sanitised to [a-z0-9_]. Falls back to 'photoblog'.
 */
function sv_handle(array $settings): string {
    $h = trim($settings['smackverse_handle'] ?? '');
    if ($h === '') {
        $h = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', trim($settings['site_name'] ?? '')));
        $h = trim($h, '_');
    }
    return $h !== '' ? $h : 'photoblog';
}

// AP endpoint/object URLs are PATH-STYLE (/ap/…, rewritten to smackverse.php
// by .htaccess) as of 0.7.350. They must stay query-string-free: Pixelfed
// HTML-encodes '&' when dereferencing object ids (?a=1&b=2 arrives as
// ?a=1&amp;b=2 → 404), which silently killed every delivered Note. The old
// ?ap= query routes still resolve for anything already federated.
function sv_actor_url(array $settings): string     { return sv_base($settings) . 'ap/actor'; }
function sv_inbox_url(array $settings): string     { return sv_base($settings) . 'ap/inbox'; }
function sv_outbox_url(array $settings): string    { return sv_base($settings) . 'ap/outbox'; }
function sv_followers_url(array $settings): string { return sv_base($settings) . 'ap/followers'; }
function sv_following_url(array $settings): string { return sv_base($settings) . 'ap/following'; }
function sv_key_id(array $settings): string        { return sv_actor_url($settings) . '#main-key'; }

/** Upsert a snap_settings row and mirror it into the in-memory array. */
function sv_set_setting(PDO $pdo, array &$settings, string $key, string $val): void {
    $ins = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    );
    $ins->execute([$key, $val]);
    $settings[$key] = $val;
}

// ─── Tables (defensive — canonical schema is the real delivery) ─────────────

/** Belt-and-suspenders CREATE IF NOT EXISTS for the two SMACKVERSE tables. */
function sv_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_ap_followers` (
        `id`               int unsigned  NOT NULL AUTO_INCREMENT,
        `actor_url`        varchar(500)  COLLATE utf8mb4_unicode_ci NOT NULL,
        `actor_handle`     varchar(190)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `inbox_url`        varchar(500)  COLLATE utf8mb4_unicode_ci NOT NULL,
        `shared_inbox_url` varchar(500)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `followed_at`      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `is_active`        tinyint(1)    NOT NULL DEFAULT '1',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_ap_actor` (`actor_url`(191)),
        KEY `idx_ap_active` (`is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_ap_deliveries` (
        `id`            int unsigned  NOT NULL AUTO_INCREMENT,
        `inbox_url`     varchar(500)  COLLATE utf8mb4_unicode_ci NOT NULL,
        `activity_json` mediumtext    COLLATE utf8mb4_unicode_ci NOT NULL,
        `attempts`      int unsigned  NOT NULL DEFAULT '0',
        `next_try_at`   datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `status`        enum('queued','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
        `last_error`    varchar(500)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `created_at`    datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_ap_due` (`status`, `next_try_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Outbound follows (0.7.356): who the BLOG ACTOR follows.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_ap_following` (
        `id`           int unsigned NOT NULL AUTO_INCREMENT,
        `actor_url`    varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
        `actor_handle` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `inbox_url`    varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
        `follow_id`    varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,
        `state`        enum('pending','accepted','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
        `followed_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_ap_following` (`actor_url`(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // v0.3 interaction crossing: federated likes table + comment AP columns.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_ap_likes` (
        `id`          int unsigned NOT NULL AUTO_INCREMENT,
        `target_type` enum('image','post') COLLATE utf8mb4_unicode_ci NOT NULL,
        `target_id`   int unsigned NOT NULL,
        `actor_url`   varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
        `created_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_ap_like` (`target_type`, `target_id`, `actor_url`(180)),
        KEY `idx_ap_like_target` (`target_type`, `target_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ([
        "ap_source enum('local','fediverse') NOT NULL DEFAULT 'local'",
        "ap_actor_url varchar(500) DEFAULT NULL",
        "ap_object_id varchar(500) DEFAULT NULL",
        "ap_note_id varchar(500) DEFAULT NULL",
        "ap_in_reply_to varchar(500) DEFAULT NULL",
    ] as $_col) {
        try { $pdo->exec("ALTER TABLE snap_comments ADD COLUMN IF NOT EXISTS {$_col}"); }
        catch (Exception $e) { /* older MySQL without IF NOT EXISTS — ignore dup */ }
    }

    // Reader / engagement (0.7.365): the two-way client. Mirrors of the
    // canonical tables so they exist the instant SMACKVERSE runs, before a
    // schema sync. See database/schema/snapsmack_canonical.sql for the notes.
    $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_ap_actors` (
        `id`               int unsigned NOT NULL AUTO_INCREMENT,
        `actor_url`        varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
        `handle`           varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `name`             varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `avatar_url`       varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `inbox_url`        varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `shared_inbox_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `summary`          text         COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `profile_url`      varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `fetched_at`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_ap_actor_cache` (`actor_url`(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_ap_notifications` (
        `id`           int unsigned NOT NULL AUTO_INCREMENT,
        `ntype`        enum('follow','like','reply','mention','boost') COLLATE utf8mb4_unicode_ci NOT NULL,
        `actor_url`    varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
        `actor_handle` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `object_id`    varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `target_url`   varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `content`      text         COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `is_read`      tinyint(1)   NOT NULL DEFAULT '0',
        `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_ap_notif` (`ntype`, `actor_url`(150), `object_id`(150)),
        KEY `idx_ap_notif_read` (`is_read`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_ap_timeline` (
        `id`           int unsigned NOT NULL AUTO_INCREMENT,
        `object_id`    varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
        `actor_url`    varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
        `actor_handle` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `content`      mediumtext   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `media_json`   mediumtext   COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `url`          varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `in_reply_to`  varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `is_boost`     tinyint(1)   NOT NULL DEFAULT '0',
        `boosted_by`   varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `source`       enum('home','local','global') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'home',
        `published`    datetime     DEFAULT NULL,
        `fetched_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_ap_tl` (`object_id`(191)),
        KEY `idx_ap_tl_src` (`source`, `published`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_ap_outbound_replies` (
        `id`           int unsigned NOT NULL AUTO_INCREMENT,
        `token`        varchar(40)  COLLATE utf8mb4_unicode_ci NOT NULL,
        `in_reply_to`  varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
        `to_actor_url` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
        `to_handle`    varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `content`      text         COLLATE utf8mb4_unicode_ci NOT NULL,
        `published`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_ap_reply_token` (`token`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS `snap_ap_outbound_likes` (
        `id`         int unsigned NOT NULL AUTO_INCREMENT,
        `object_id`  varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
        `like_id`    varchar(600) COLLATE utf8mb4_unicode_ci NOT NULL,
        `created_at` datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_ap_out_like` (`object_id`(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// ─── Keys ────────────────────────────────────────────────────────────────────

/**
 * Ensure the RSA-2048 keypair exists in snap_settings; return the public
 * key PEM. Returns '' if openssl is unavailable or generation fails — the
 * actor is then served without a publicKey block (discovery still works,
 * federation delivery will not).
 */
function sv_ensure_keys(PDO $pdo, array &$settings): string {
    $pub = $settings['smackverse_public_key'] ?? '';
    if ($pub !== '') return $pub;
    if (!function_exists('openssl_pkey_new')) return '';

    $res = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    if ($res === false) return '';
    if (!openssl_pkey_export($res, $priv)) return '';
    $det = openssl_pkey_get_details($res);
    $pub = $det['key'] ?? '';
    if ($pub === '') return '';

    try {
        sv_set_setting($pdo, $settings, 'smackverse_public_key', $pub);
        sv_set_setting($pdo, $settings, 'smackverse_private_key', $priv);
    } catch (Exception $e) {
        return ''; // don't serve a key we couldn't persist
    }
    return $pub;
}

// ─── SSRF-guarded remote fetch ───────────────────────────────────────────────

/**
 * Resolve a URL for outbound contact. Returns ['host','port','ip','pin']
 * when the target is public (http(s), resolvable, NOT private/reserved/
 * loopback), null otherwise. The caller MUST hand 'pin' to CURLOPT_RESOLVE
 * so cURL contacts the exact IP that passed this check — without the pin,
 * a hostile DNS server can answer the validation lookup with a public IP
 * and cURL's second lookup with a LAN address (DNS rebinding).
 */
function sv_resolve_public(string $url): ?array {
    $p = parse_url($url);
    if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true)) return null;
    $host = $p['host'] ?? '';
    if ($host === '') return null;
    $port = (int)($p['port'] ?? (($p['scheme'] === 'https') ? 443 : 80));
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return null; // did not resolve
    if (!filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    return ['host' => $host, 'port' => $port, 'ip' => $ip,
            'pin' => [$host . ':' . $port . ':' . $ip]];
}

/** Boolean convenience wrapper over sv_resolve_public() for validation-only checks. */
function sv_url_is_public(string $url): bool {
    return sv_resolve_public($url) !== null;
}

/**
 * Inbox rate limit — reuses snap_rate_limits / snap_ip_bans (the login /
 * FLKR FCKR pattern). Every inbox POST costs a signature check including a
 * remote key fetch, so it is not free to serve: cap 60 per 10 minutes per
 * IP; a sustained flood (>180 in the window) earns a 24-hour auto-ban.
 * Best-effort: a limiter failure never blocks legitimate federation.
 */
function sv_inbox_rate_ok(PDO $pdo, string $ip): bool {
    try {
        $b = $pdo->prepare("SELECT 1 FROM snap_ip_bans WHERE ip = ? AND expires_at > NOW() LIMIT 1");
        $b->execute([$ip]);
        if ($b->fetchColumn()) return false;

        $pdo->prepare(
            "INSERT INTO snap_rate_limits (ip, action, count, window_start)
             VALUES (?, 'smackverse_inbox', 1, NOW())
             ON DUPLICATE KEY UPDATE
               count        = IF(window_start < DATE_SUB(NOW(), INTERVAL 10 MINUTE), 1, count + 1),
               window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL 10 MINUTE), NOW(), window_start)"
        )->execute([$ip]);
        $q = $pdo->prepare(
            "SELECT count FROM snap_rate_limits
             WHERE ip = ? AND action = 'smackverse_inbox'
               AND window_start >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );
        $q->execute([$ip]);
        $n = (int)($q->fetchColumn() ?: 0);

        if ($n > 180) {
            $pdo->prepare(
                "INSERT INTO snap_ip_bans (ip, reason, banned_at, expires_at)
                 VALUES (?, 'auto:smackverse_inbox', NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason), banned_at = NOW(), expires_at = VALUES(expires_at)"
            )->execute([$ip]);
            return false;
        }
        return $n <= 60;
    } catch (PDOException $e) {
        return true; // never block federation on a limiter hiccup
    }
}

/**
 * GET a remote ActivityPub document (actor, mostly). No redirects, 8s
 * timeout, 512KB cap. Returns decoded array or null.
 */
function sv_fetch_ap(string $url): ?array {
    if (!function_exists('curl_init')) return null;
    $res = sv_resolve_public($url);
    if ($res === null) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE        => $res['pin'], // pin the vetted IP — DNS-rebinding guard
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/activity+json, application/ld+json',
            'User-Agent: SnapSmack-SMACKVERSE/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0'),
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) return null;
    if (strlen($body) > 524288) return null;
    $doc = json_decode($body, true);
    return is_array($doc) ? $doc : null;
}

// ─── HTTP Signatures (draft-cavage — Mastodon dialect) ──────────────────────

/** Parse the Signature header into its key="value" params. */
function sv_parse_signature_header(string $header): array {
    $out = [];
    foreach (preg_split('/\s*,\s*/', trim($header)) as $part) {
        if (preg_match('/^(\w+)="(.*)"$/s', $part, $m)) $out[strtolower($m[1])] = $m[2];
    }
    return $out;
}

/** Request header value by lowercase name, from $_SERVER. */
function sv_request_header(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) return $_SERVER[$key];
    if ($name === 'content-type' && isset($_SERVER['CONTENT_TYPE']))     return $_SERVER['CONTENT_TYPE'];
    if ($name === 'content-length' && isset($_SERVER['CONTENT_LENGTH'])) return $_SERVER['CONTENT_LENGTH'];
    return '';
}

/**
 * Verify the inbound inbox POST: Digest matches the body, Date is within
 * ±1h, and the draft-cavage signature checks out against the publicKeyPem
 * of the actor named by keyId. Returns the remote ACTOR DOCUMENT on
 * success, null on any failure. The caller must additionally confirm the
 * actor id matches the activity's actor field.
 */
function sv_verify_signature(string $raw_body): ?array {
    // Diagnostic: logs the exact rejection reason to the PHP error log with a
    // greppable prefix ("SMACKVERSE sig:"). Cheap; helps chase interop issues.
    $reject = function (string $why): void { error_log('SMACKVERSE sig: REJECT — ' . $why); };

    $sig_header = sv_request_header('signature');
    if ($sig_header === '') { $reject('no Signature header'); return null; }
    $sig = sv_parse_signature_header($sig_header);
    if (empty($sig['keyid']) || empty($sig['signature']) || empty($sig['headers'])) {
        $reject('Signature header missing keyId/signature/headers'); return null;
    }

    // Digest: required for POSTs; must match the raw body. Compare case-
    // insensitively on the algorithm token (SHA-256 vs sha-256) — only the
    // base64 payload is security-relevant and must match exactly.
    $digest_hdr = trim(sv_request_header('digest'));
    if ($digest_hdr === '') { $reject('no Digest header'); return null; }
    $want_b64 = base64_encode(hash('sha256', $raw_body, true));
    $got_b64  = preg_replace('/^sha-256=/i', '', $digest_hdr);
    if (!hash_equals($want_b64, $got_b64)) {
        $reject('digest mismatch (body hash != Digest header)'); return null;
    }

    // Date: within ±1 hour (replay guard).
    $date_hdr = sv_request_header('date');
    if ($date_hdr === '') { $reject('no Date header'); return null; }
    $ts = strtotime($date_hdr);
    if ($ts === false || abs(time() - $ts) > 3600) { $reject('Date outside ±1h window'); return null; }

    // Rebuild the signing string from the signed-header list. Require the
    // security-critical pair (request-target)+digest; date is standard but not
    // all signers include it, so we don't hard-fail on it (the ±1h window above
    // already limits replay).
    $signed_names = preg_split('/\s+/', strtolower(trim($sig['headers'])));
    if (!in_array('(request-target)', $signed_names, true) || !in_array('digest', $signed_names, true)) {
        $reject('signed headers missing (request-target) or digest: ' . implode(' ', $signed_names)); return null;
    }
    // Assemble the signed header lines. (request-target) is deferred: it has two
    // legitimate constructions (below) and we resolve it per candidate.
    $fixed_lines = [];
    foreach ($signed_names as $name) {
        if ($name === '(request-target)') {
            $fixed_lines[] = null; // placeholder — filled per candidate below
        } elseif ($name === 'host') {
            $fixed_lines[] = 'host: ' . ($_SERVER['HTTP_HOST'] ?? '');
        } else {
            $fixed_lines[] = $name . ': ' . sv_request_header($name);
        }
    }

    // (request-target) has two legitimate forms in the wild. The draft-cavage
    // spec includes the full request path WITH its query string; Pixelfed (and
    // some others) sign the PATH ONLY, dropping the query. Our inbox URL carries
    // a ?ap=inbox routing param, so a query-dropping signer builds a different
    // string than the naive full-URI one — this is what made every Pixelfed
    // Follow 401 (confirmed: only the path-only form verifies). Accept BOTH.
    // Each is still cryptographically bound to the same key, the same body (via
    // Digest, already checked) and the same ±1h Date window, so tolerating the
    // two constructions costs no security.
    $method = strtolower($_SERVER['REQUEST_METHOD'] ?? 'post');
    $uri     = $_SERVER['REQUEST_URI'] ?? '/';
    $path    = parse_url($uri, PHP_URL_PATH) ?: $uri;
    $rt_candidates = array_values(array_unique([
        $method . ' ' . $uri,   // standards-strict: path + query
        $method . ' ' . $path,  // Pixelfed & friends: path only
    ]));
    $build = function (string $rt) use ($fixed_lines): string {
        $lines = $fixed_lines;
        foreach ($lines as $i => $l) {
            if ($l === null) { $lines[$i] = '(request-target): ' . $rt; }
        }
        return implode("\n", $lines);
    };

    // Fetch the key owner's actor document (keyId minus fragment).
    $actor_url = preg_replace('/#.*$/', '', $sig['keyid']);
    $actor = sv_fetch_ap($actor_url);
    if (!$actor) { $reject('could not fetch signer actor: ' . $actor_url); return null; }
    $pem = $actor['publicKey']['publicKeyPem'] ?? '';
    if ($pem === '') { $reject('signer actor has no publicKeyPem'); return null; }
    // The key must belong to the actor document that serves it.
    $owner = $actor['publicKey']['owner'] ?? ($actor['id'] ?? '');
    if ($owner !== ($actor['id'] ?? '')) { $reject('publicKey.owner != actor.id'); return null; }

    $pubkey = openssl_pkey_get_public($pem);
    if ($pubkey === false) { $reject('openssl could not parse publicKeyPem'); return null; }
    $sig_bytes = base64_decode($sig['signature']);

    // Verify against each (request-target) form; accept on the first that matches.
    $signing_string = $build($rt_candidates[0]);
    $ok = 0;
    foreach ($rt_candidates as $rt) {
        if (openssl_verify($build($rt), $sig_bytes, $pubkey, OPENSSL_ALGO_SHA256) === 1) {
            $ok = 1; $signing_string = $build($rt); break;
        }
    }
    if ($ok !== 1) {
        // verify=0 for every form = signature doesn't match string+key.
        error_log('SMACKVERSE sig: verify=0'
            . ' openssl_err=' . (openssl_error_string() ?: 'none')
            . ' sig_bytes=' . strlen((string)$sig_bytes)
            . ' keyId=' . $sig['keyid']
            . ' fetched_actor=' . ($actor['id'] ?? '?')
            . ' pem_fp=' . substr(hash('sha256', $pem), 0, 16));
        foreach ($rt_candidates as $rt) {
            error_log('SMACKVERSE sig: tried [' . str_replace("\n", ' ⏎ ', $build($rt)) . ']');
        }
        error_log('SMACKVERSE sig: raw Signature header = [' . sv_request_header('signature') . ']');
        $reject('openssl_verify failed for all request-target forms. Signed: ' . implode(' ', $signed_names));
        return null;
    }
    return $actor;
}

/**
 * Signed headers for an outbound POST of $body to $url. Signs
 * (request-target) host date digest content-type with our private key.
 * Returns a curl-ready header array, or null without a key.
 */
function sv_signed_headers(array $settings, string $url, string $body): ?array {
    $priv = $settings['smackverse_private_key'] ?? '';
    if ($priv === '') return null;
    $pkey = openssl_pkey_get_private($priv);
    if ($pkey === false) return null;

    $p      = parse_url($url);
    $host   = $p['host'] ?? '';
    $path   = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    $date   = gmdate('D, d M Y H:i:s') . ' GMT';
    $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
    $ctype  = 'application/activity+json';

    $signing_string = "(request-target): post {$path}\n"
                    . "host: {$host}\n"
                    . "date: {$date}\n"
                    . "digest: {$digest}\n"
                    . "content-type: {$ctype}";
    if (!openssl_sign($signing_string, $raw_sig, $pkey, OPENSSL_ALGO_SHA256)) return null;

    $sig_header = 'keyId="' . sv_key_id($settings) . '",'
                . 'algorithm="rsa-sha256",'
                . 'headers="(request-target) host date digest content-type",'
                . 'signature="' . base64_encode($raw_sig) . '"';
    return [
        'Host: ' . $host,
        'Date: ' . $date,
        'Digest: ' . $digest,
        'Content-Type: ' . $ctype,
        'Signature: ' . $sig_header,
        'Accept: application/activity+json',
        'User-Agent: SnapSmack-SMACKVERSE/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0'),
    ];
}

/** POST a signed activity to a remote inbox. Returns [bool ok, string info]. */
function sv_deliver(array $settings, string $inbox_url, string $activity_json): array {
    if (!function_exists('curl_init')) return [false, 'curl missing'];
    $res = sv_resolve_public($inbox_url);
    if ($res === null) return [false, 'inbox url not public'];
    $headers = sv_signed_headers($settings, $inbox_url, $activity_json);
    if ($headers === null) return [false, 'no signing key'];

    $ch = curl_init($inbox_url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $activity_json,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE        => $res['pin'], // pin the vetted IP — DNS-rebinding guard
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($code >= 200 && $code < 300) return [true, (string)$code];
    return [false, $err !== '' ? substr($err, 0, 200) : 'HTTP ' . $code];
}

// ─── Delivery queue ──────────────────────────────────────────────────────────

/** Queue one activity JSON for a remote inbox. */
function sv_queue_delivery(PDO $pdo, string $inbox_url, string $activity_json): void {
    $pdo->prepare("INSERT INTO snap_ap_deliveries (inbox_url, activity_json) VALUES (?, ?)")
        ->execute([$inbox_url, $activity_json]);
}

/** Base gap in seconds between consecutive POSTS in a paced drain (clamped
 *  1..120, default 10). The breathing room a remote gets to ingest one Note
 *  before the next lands — the cure for out-of-order posts on a burst. */
function sv_delivery_cadence(array $settings): int {
    return max(1, min(120, (int)($settings['smackverse_delivery_cadence_secs'] ?? 10)));
}

/** Extra seconds added per EXTRA carousel layer (clamped 0..30, default 2). A
 *  post with L attachments earns post_gap + layer_gap·(L−1) of settle time
 *  before the next send, so the remote finishes fetching every frame of a fat
 *  stack — the cure for cover-lands-but-stack-doesn't. A single image (L=1)
 *  pays nothing extra. */
function sv_layer_cadence(array $settings): int {
    return max(0, min(30, (int)($settings['smackverse_layer_cadence_secs'] ?? 2)));
}

/** Attachment (carousel layer) count carried by a queued activity's Note, so
 *  the paced drain can size the post-send settle gap. 0 for non-Note activities
 *  (Delete/Follow/Accept/Undo). */
function sv_activity_attachment_count(string $activity_json): int {
    $a   = json_decode($activity_json, true);
    $att = is_array($a) ? ($a['object']['attachment'] ?? null) : null;
    return is_array($att) ? count($att) : 0;
}

/** Distinct delivery inboxes for all active followers (sharedInbox preferred). */
function sv_follower_inboxes(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT inbox_url, shared_inbox_url FROM snap_ap_followers WHERE is_active = 1"
    )->fetchAll(PDO::FETCH_ASSOC);
    $inboxes = [];
    foreach ($rows as $r) {
        $u = trim($r['shared_inbox_url'] ?? '') !== '' ? $r['shared_inbox_url'] : $r['inbox_url'];
        if ($u) $inboxes[$u] = true;
    }
    return array_keys($inboxes);
}

/**
 * Process due queued deliveries. Success → row deleted; failure → backoff
 * (5min · 2^attempts, capped 24h); parked as status=failed after 8 tries.
 * Returns [sent, failed_now].
 *
 * MEASURED CADENCE ($cadence_secs > 0): pause between consecutive sends so a
 * remote ingests one activity — and finishes fetching its media — before the
 * next arrives. Rows are already ordered oldest-first (id ASC), so a paced run
 * lands posts on the remote in strict chronological order with no concurrent
 * async workers to shuffle same-second timestamps or drop half a carousel
 * stack. The gap SCALES with the layer count of the post just sent: a fat
 * carousel earns cadence + layer_gap·(layers−1) of settle time so the remote
 * finishes pulling every frame before the next Note lands; a single image pays
 * only the base gap. Only ever call with a cadence from a detached context
 * (CLI cron or a post-fastcgi_finish_request web tail) — never inline before a
 * response.
 */
function sv_process_deliveries(PDO $pdo, array $settings, int $limit = 30, int $cadence_secs = 0): array {
    $now  = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "SELECT * FROM snap_ap_deliveries
         WHERE status = 'queued' AND next_try_at <= ?
         ORDER BY id ASC LIMIT " . (int)$limit
    );
    $stmt->execute([$now]);
    $due = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $layer_gap = ($cadence_secs > 0) ? sv_layer_cadence($settings) : 0;
    $sent = 0; $failed = 0; $prev_layers = 0; $i = 0;
    foreach ($due as $row) {
        // Settle gap BEFORE every send except the first, sized by the PREVIOUS
        // post's layer count — one activity in flight at a time, oldest first,
        // heavy stacks given proportionally longer to fully land.
        if ($cadence_secs > 0 && $i++ > 0) {
            sleep($cadence_secs + $layer_gap * max(0, $prev_layers - 1));
        }
        $prev_layers = sv_activity_attachment_count($row['activity_json']);
        list($ok, $info) = sv_deliver($settings, $row['inbox_url'], $row['activity_json']);
        if ($ok) {
            $pdo->prepare("DELETE FROM snap_ap_deliveries WHERE id = ?")->execute([$row['id']]);
            $sent++;
            continue;
        }
        $failed++;
        $attempts = (int)$row['attempts'] + 1;
        if ($attempts >= 8) {
            $pdo->prepare(
                "UPDATE snap_ap_deliveries SET status='failed', attempts=?, last_error=? WHERE id=?"
            )->execute([$attempts, $info, $row['id']]);
        } else {
            $delay = min(300 * (2 ** $attempts), 86400);
            $pdo->prepare(
                "UPDATE snap_ap_deliveries
                 SET attempts=?, last_error=?, next_try_at=DATE_ADD(NOW(), INTERVAL ? SECOND)
                 WHERE id=?"
            )->execute([$attempts, $info, $delay, $row['id']]);
        }
    }
    return [$sent, $failed];
}

// ─── Interaction crossing — target resolution ───────────────────────────────

/**
 * Resolve one of OUR Note URLs (the id we publish, ?ap=note&id=/&post=/&comment=)
 * to a target descriptor, or null if the URL isn't ours. Used to match an
 * inbound reply's inReplyTo / a Like's object back to the content it's about.
 */
function sv_resolve_target(string $url): ?array {
    // Path-style ids (0.7.350+): /ap/note/{p|i|c}/N
    if (preg_match('~/ap/note/([pic])/(\d+)~', $url, $m)) {
        $map = ['p' => 'post', 'i' => 'image', 'c' => 'comment'];
        return ['type' => $map[$m[1]], 'id' => (int)$m[2]];
    }
    // Legacy query-string ids (pre-0.7.350) — anything already federated.
    if (strpos($url, 'smackverse.php') === false) return null;
    $q = parse_url($url, PHP_URL_QUERY) ?: '';
    parse_str($q, $p);
    if (($p['ap'] ?? '') !== 'note') return null;
    if (isset($p['comment'])) return ['type' => 'comment', 'id' => (int)$p['comment']];
    if (isset($p['post']))    return ['type' => 'post',    'id' => (int)$p['post']];
    if (isset($p['id']))      return ['type' => 'image',   'id' => (int)$p['id']];
    return null;
}

/** Map a target to the snap_comments.img_id it should attach to (0 if none). */
function sv_target_image_id(PDO $pdo, array $target): int {
    if ($target['type'] === 'image') return (int)$target['id'];
    if ($target['type'] === 'post') {
        $s = $pdo->prepare(
            "SELECT image_id FROM snap_post_images WHERE post_id = ?
             ORDER BY is_cover DESC, sort_position ASC LIMIT 1"
        );
        $s->execute([(int)$target['id']]);
        return (int)($s->fetchColumn() ?: 0);
    }
    if ($target['type'] === 'comment') {
        $s = $pdo->prepare("SELECT img_id FROM snap_comments WHERE id = ? LIMIT 1");
        $s->execute([(int)$target['id']]);
        return (int)($s->fetchColumn() ?: 0);
    }
    return 0;
}

// ─── Inbox handling ──────────────────────────────────────────────────────────

/**
 * Handle a signature-VERIFIED inbox activity. $actor_doc is the remote
 * actor returned by sv_verify_signature(); its id MUST equal the
 * activity's actor (checked here). Returns an HTTP status code.
 */
// ─── Reader / engagement ingest (0.7.365) ───────────────────────────────────

/** Upsert a remote actor doc into the render cache (avatar/name/handle/inbox). */
function sv_cache_actor(PDO $pdo, array $actor_doc): void {
    $url = (string)($actor_doc['id'] ?? '');
    if ($url === '') return;
    $host   = parse_url($url, PHP_URL_HOST) ?: '';
    $user   = (string)($actor_doc['preferredUsername'] ?? '');
    $handle = ($user !== '' && $host !== '') ? $user . '@' . $host : '';
    $avatar = '';
    $icon = $actor_doc['icon'] ?? null;
    if (is_array($icon))      $avatar = (string)($icon['url'] ?? ($icon[0]['url'] ?? ''));
    elseif (is_string($icon)) $avatar = $icon;
    $shared = $actor_doc['endpoints']['sharedInbox'] ?? null;
    try {
        $pdo->prepare(
            "INSERT INTO snap_ap_actors
                (actor_url, handle, name, avatar_url, inbox_url, shared_inbox_url, summary, profile_url, fetched_at)
             VALUES (?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE handle=VALUES(handle), name=VALUES(name), avatar_url=VALUES(avatar_url),
                 inbox_url=VALUES(inbox_url), shared_inbox_url=VALUES(shared_inbox_url),
                 summary=VALUES(summary), profile_url=VALUES(profile_url), fetched_at=NOW()"
        )->execute([
            substr($url, 0, 500), substr($handle, 0, 190),
            substr((string)($actor_doc['name'] ?? $user), 0, 255), substr($avatar, 0, 600),
            substr((string)($actor_doc['inbox'] ?? ''), 0, 500),
            $shared ? substr((string)$shared, 0, 500) : null,
            (string)($actor_doc['summary'] ?? ''),
            substr((string)($actor_doc['url'] ?? $url), 0, 600),
        ]);
    } catch (Exception $e) { /* table may lag on a fresh install */ }
}

/** Record an inbound engagement notification (deduped on type+actor+object). */
function sv_notify(PDO $pdo, string $ntype, string $actor_url, string $handle = '',
                   ?string $object_id = null, ?string $target_url = null, ?string $content = null): void {
    if ($actor_url === '') return;
    try {
        $pdo->prepare(
            "INSERT IGNORE INTO snap_ap_notifications
                (ntype, actor_url, actor_handle, object_id, target_url, content)
             VALUES (?,?,?,?,?,?)"
        )->execute([
            $ntype, substr($actor_url, 0, 500), substr($handle, 0, 190),
            $object_id !== null ? substr($object_id, 0, 500) : null,
            $target_url !== null ? substr($target_url, 0, 500) : null,
            $content !== null ? mb_substr($content, 0, 2000) : null,
        ]);
    } catch (Exception $e) { /* table may lag on a fresh install */ }
}

/** True when the blog follows this actor (accepted) — gates timeline ingest. */
function sv_is_following(PDO $pdo, string $actor_url): bool {
    if ($actor_url === '') return false;
    try {
        $s = $pdo->prepare("SELECT 1 FROM snap_ap_following WHERE actor_url = ? AND state = 'accepted' LIMIT 1");
        $s->execute([$actor_url]);
        return (bool)$s->fetchColumn();
    } catch (Exception $e) { return false; }
}

/** Ingest a remote Note into the home timeline (image posts only, de-duped). */
function sv_ingest_timeline(PDO $pdo, array $obj, string $actor_url, string $handle = '',
                            bool $is_boost = false, ?string $boosted_by = null): void {
    $otype = $obj['type'] ?? '';
    if ($otype !== 'Note' && $otype !== 'Image') return;
    $object_id = (string)($obj['id'] ?? '');
    if ($object_id === '') return;

    $images = [];
    foreach (($obj['attachment'] ?? []) as $att) {
        if (!is_array($att)) continue;
        $mt = (string)($att['mediaType'] ?? '');
        if ($mt !== '' && stripos($mt, 'image/') !== 0) continue;
        $u = $att['url'] ?? '';
        if (is_array($u)) $u = $u['href'] ?? ($u[0]['href'] ?? '');
        if ((string)$u !== '') $images[] = (string)$u;
    }
    if (!$images) return;   // photo client — skip text-only

    $pub = isset($obj['published']) ? date('Y-m-d H:i:s', strtotime((string)$obj['published'])) : null;
    try {
        $pdo->prepare(
            "INSERT INTO snap_ap_timeline
                (object_id, actor_url, actor_handle, content, media_json, url, in_reply_to, is_boost, boosted_by, source, published)
             VALUES (?,?,?,?,?,?,?,?,?,'home',?)
             ON DUPLICATE KEY UPDATE content=VALUES(content), media_json=VALUES(media_json), fetched_at=NOW()"
        )->execute([
            substr($object_id, 0, 500), substr($actor_url, 0, 500), substr($handle, 0, 190),
            mb_substr(trim(strip_tags((string)($obj['content'] ?? ''))), 0, 4000),
            json_encode($images, JSON_UNESCAPED_SLASHES),
            substr((string)($obj['url'] ?? $object_id), 0, 600),
            isset($obj['inReplyTo']) && is_string($obj['inReplyTo']) ? substr($obj['inReplyTo'], 0, 500) : null,
            $is_boost ? 1 : 0, $boosted_by ? substr($boosted_by, 0, 500) : null, $pub,
        ]);
    } catch (Exception $e) { /* table may lag on a fresh install */ }
}

function sv_handle_inbox(PDO $pdo, array &$settings, array $activity, array $actor_doc): int {
    $actor_id = $actor_doc['id'] ?? '';
    $act_actor = is_array($activity['actor'] ?? null)
        ? ($activity['actor']['id'] ?? '') : ($activity['actor'] ?? '');
    if ($actor_id === '' || $act_actor !== $actor_id) return 401;

    $type = $activity['type'] ?? '';

    if ($type === 'Follow') {
        $object = is_array($activity['object'] ?? null)
            ? ($activity['object']['id'] ?? '') : ($activity['object'] ?? '');
        if ($object !== sv_actor_url($settings)) return 202; // not us — ignore politely

        $inbox  = $actor_doc['inbox'] ?? '';
        if ($inbox === '' || !sv_url_is_public($inbox)) return 202;
        $shared = $actor_doc['endpoints']['sharedInbox'] ?? null;
        if ($shared !== null && !sv_url_is_public($shared)) $shared = null;
        $handle = ($actor_doc['preferredUsername'] ?? '') . '@' . (parse_url($actor_id, PHP_URL_HOST) ?: '');

        $pdo->prepare(
            "INSERT INTO snap_ap_followers (actor_url, actor_handle, inbox_url, shared_inbox_url, is_active)
             VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE actor_handle=VALUES(actor_handle), inbox_url=VALUES(inbox_url),
                                     shared_inbox_url=VALUES(shared_inbox_url), is_active=1"
        )->execute([$actor_id, substr($handle, 0, 190), $inbox, $shared]);

        // Queue the Accept back to the follower's own inbox.
        $accept = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id'       => sv_actor_url($settings) . '#accept-' . bin2hex(random_bytes(8)),
            'type'     => 'Accept',
            'actor'    => sv_actor_url($settings),
            'object'   => $activity,
        ];
        sv_queue_delivery($pdo, $inbox, json_encode($accept, JSON_UNESCAPED_SLASHES));

        // Backfill: send this NEW follower our most-recent posts to THAT
        // follower's inbox only (not a network blast), so their view of the
        // blog isn't an empty grid. Recent-only; count = smackverse_backfill_count
        // (default 10, 0 disables). Skipped on a re-follow (already following).
        $backfill = (int)($settings['smackverse_backfill_count'] ?? 10);
        $is_refollow = (bool)$pdo->query(
            "SELECT 1 FROM snap_ap_followers WHERE actor_url = " . $pdo->quote($actor_id)
            . " AND followed_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE) LIMIT 1"
        )->fetchColumn();
        if ($backfill > 0 && !$is_refollow) {
            foreach (sv_recent_creates($pdo, $settings, $backfill) as $create_json) {
                sv_queue_delivery($pdo, $inbox, $create_json);
            }
        }
        sv_cache_actor($pdo, $actor_doc);
        sv_notify($pdo, 'follow', $actor_id, $handle, null, sv_actor_url($settings), null);
        return 202;
    }

    if ($type === 'Accept' || $type === 'Reject') {
        // Response to OUR outbound Follow (sv_follow_actor). The sender is the
        // account we followed (signature already verified upstream); match on
        // our Follow activity id, fall back to the actor when servers echo a
        // bare object id instead of the full Follow.
        $obj = $activity['object'] ?? [];
        $fid = is_array($obj) ? (string)($obj['id'] ?? '') : (string)$obj;
        $new_state = ($type === 'Accept') ? 'accepted' : 'rejected';
        try {
            $upd = $pdo->prepare(
                "UPDATE snap_ap_following SET state = ? WHERE follow_id = ? AND actor_url = ?"
            );
            $upd->execute([$new_state, $fid, $actor_id]);
            if ($upd->rowCount() === 0) {
                $pdo->prepare(
                    "UPDATE snap_ap_following SET state = ? WHERE actor_url = ? AND state = 'pending'"
                )->execute([$new_state, $actor_id]);
            }
        } catch (Exception $e) { /* table may lag on a fresh install */ }
        return 202;
    }

    if ($type === 'Undo') {
        $obj   = $activity['object'] ?? [];
        $otype = is_array($obj) ? ($obj['type'] ?? '') : '';
        if ($otype === 'Follow') {
            $pdo->prepare("UPDATE snap_ap_followers SET is_active = 0 WHERE actor_url = ?")
                ->execute([$actor_id]);
        } elseif ($otype === 'Like') {
            // Un-like: drop the federated like on our target.
            $liked = is_array($obj['object'] ?? null) ? ($obj['object']['id'] ?? '') : ($obj['object'] ?? '');
            $t = is_string($liked) ? sv_resolve_target($liked) : null;
            if ($t && $t['type'] !== 'comment') {
                $pdo->prepare("DELETE FROM snap_ap_likes WHERE target_type = ? AND target_id = ? AND actor_url = ?")
                    ->execute([$t['type'], (int)$t['id'], $actor_id]);
            }
        }
        return 202;
    }

    if ($type === 'Like') {
        // A remote actor liked one of our posts → federated like (combined tally).
        $obj = is_array($activity['object'] ?? null) ? ($activity['object']['id'] ?? '') : ($activity['object'] ?? '');
        $t = is_string($obj) ? sv_resolve_target($obj) : null;
        if ($t && $t['type'] !== 'comment') {
            try {
                $pdo->prepare("INSERT IGNORE INTO snap_ap_likes (target_type, target_id, actor_url) VALUES (?, ?, ?)")
                    ->execute([$t['type'], (int)$t['id'], $actor_id]);
            } catch (Exception $e) { /* table may lag on a fresh install */ }
            $handle = ($actor_doc['preferredUsername'] ?? 'someone') . '@' . (parse_url($actor_id, PHP_URL_HOST) ?: '');
            sv_cache_actor($pdo, $actor_doc);
            sv_notify($pdo, 'like', $actor_id, $handle, (string)$obj, (string)$obj, null);
        }
        return 202;
    }

    if ($type === 'Create') {
        $obj = $activity['object'] ?? [];
        if (!is_array($obj) || (($obj['type'] ?? '') !== 'Note' && ($obj['type'] ?? '') !== 'Image')) return 202;
        $handle   = ($actor_doc['preferredUsername'] ?? 'someone') . '@' . (parse_url($actor_id, PHP_URL_HOST) ?: '');
        $note_id  = (string)($obj['id'] ?? '');
        $in_reply = is_string($obj['inReplyTo'] ?? null) ? (string)$obj['inReplyTo'] : '';

        // (a) A reply to one of OUR posts → moderation comment + a notification.
        $target = ($in_reply !== '') ? sv_resolve_target($in_reply) : null;
        $img_id = $target ? sv_target_image_id($pdo, $target) : 0;
        if ($img_id > 0) {
            if ($note_id !== '') {
                $text = trim(html_entity_decode(strip_tags((string)($obj['content'] ?? '')), ENT_QUOTES, 'UTF-8'));
                if ($text !== '') {
                    if (mb_strlen($text) > 5000) $text = mb_substr($text, 0, 5000);
                    try {
                        // is_approved = 0 → normal comment moderation queue.
                        $pdo->prepare(
                            "INSERT INTO snap_comments
                                (img_id, comment_author, comment_url, comment_text, comment_date,
                                 is_approved, ap_source, ap_actor_url, ap_object_id, ap_in_reply_to)
                             VALUES (?, ?, ?, ?, NOW(), 0, 'fediverse', ?, ?, ?)
                             ON DUPLICATE KEY UPDATE comment_text = VALUES(comment_text)"
                        )->execute([$img_id, substr($handle, 0, 100), $actor_id, $text, $actor_id, $note_id, $in_reply]);
                    } catch (Exception $e) { /* dup or column lag — ignore */ }
                    sv_cache_actor($pdo, $actor_doc);
                    sv_notify($pdo, 'reply', $actor_id, $handle, $note_id, $in_reply, $text);
                }
            }
            return 202;
        }

        // (b) Not a reply to us, but it @-mentions us → a mention notification.
        $mentions_us = false;
        foreach (($obj['tag'] ?? []) as $tg) {
            if (is_array($tg) && ($tg['type'] ?? '') === 'Mention' && ($tg['href'] ?? '') === sv_actor_url($settings)) {
                $mentions_us = true; break;
            }
        }
        if ($mentions_us && $note_id !== '') {
            sv_cache_actor($pdo, $actor_doc);
            sv_notify($pdo, 'mention', $actor_id, $handle, $note_id, sv_actor_url($settings),
                      trim(strip_tags((string)($obj['content'] ?? ''))));
        }

        // (c) A normal post from an account we FOLLOW → the home timeline (reader).
        if (sv_is_following($pdo, $actor_id)) {
            sv_cache_actor($pdo, $actor_doc);
            sv_ingest_timeline($pdo, $obj, $actor_id, $handle);
        }
        return 202;
    }

    if ($type === 'Delete') {
        // Remote actor deleting itself → drop from followers; deleting a Note →
        // remove the federated comment it produced.
        $obj = is_array($activity['object'] ?? null)
            ? ($activity['object']['id'] ?? '') : ($activity['object'] ?? '');
        if ($obj === $actor_id) {
            $pdo->prepare("UPDATE snap_ap_followers SET is_active = 0 WHERE actor_url = ?")
                ->execute([$actor_id]);
        } elseif (is_string($obj) && $obj !== '') {
            try {
                $pdo->prepare("DELETE FROM snap_comments WHERE ap_object_id = ? AND ap_source = 'fediverse'")
                    ->execute([$obj]);
            } catch (Exception $e) { /* ignore */ }
        }
        return 202;
    }

    if ($type === 'Announce') {
        // A boost (reblog). $object is the boosted post (usually a URL).
        $obj    = $activity['object'] ?? '';
        $obj_id = is_array($obj) ? (string)($obj['id'] ?? '') : (string)$obj;
        if ($obj_id !== '') {
            $handle = ($actor_doc['preferredUsername'] ?? 'someone') . '@' . (parse_url($actor_id, PHP_URL_HOST) ?: '');
            // Boost of OUR post → notification.
            $t = sv_resolve_target($obj_id);
            if ($t && $t['type'] !== 'comment') {
                sv_cache_actor($pdo, $actor_doc);
                sv_notify($pdo, 'boost', $actor_id, $handle, $obj_id, $obj_id, null);
            }
            // Boost BY someone we follow → surface the boosted post in home.
            if (sv_is_following($pdo, $actor_id) && stripos($obj_id, 'https://') === 0) {
                $boosted = sv_fetch_ap($obj_id);
                if (is_array($boosted)) {
                    $battr = is_array($boosted['attributedTo'] ?? null)
                        ? (string)($boosted['attributedTo']['id'] ?? '')
                        : (string)($boosted['attributedTo'] ?? '');
                    sv_ingest_timeline($pdo, $boosted, $battr !== '' ? $battr : $actor_id, '', true, $actor_id);
                }
            }
        }
        return 202;
    }

    // Everything else: acknowledged, not stored.
    return 202;
}

// ─── Documents: webfinger / actor / outbox / followers ──────────────────────

/**
 * Bio → actor summary HTML. The fediverse renders summary as HTML, so we
 * escape the admin's plain-text bio (no raw-HTML injection) and then turn any
 * http(s):// URL into a real clickable link — the admin just types the full
 * URL (e.g. https://fauxlaroid.fyi) and it becomes a link in the fediverse bio.
 * rel="nofollow noopener me" — the "me" enables fediverse link verification if
 * the linked site links back.
 */
function sv_bio_html(string $raw): string {
    $esc = htmlspecialchars(trim($raw), ENT_QUOTES);
    $esc = preg_replace_callback('~\bhttps?://[^\s<]+~i', function ($m) {
        $u = rtrim($m[0], '.,;:)!?');   // don't swallow trailing punctuation
        return '<a href="' . $u . '" rel="nofollow noopener me">' . $u . '</a>';
    }, $esc);
    return nl2br($esc);
}

/**
 * The actor summary: the admin's bio (linkified) with an automatic
 * "See <blog> for more." link back to the blog appended — so the fediverse
 * profile always points home without the admin editing the description. The
 * link text is the blog's domain; rel="me" lets the blog verify the link if it
 * links back. Skipped only if the bio already contains the site URL.
 */
function sv_actor_summary(array $settings): string {
    $bio  = sv_bio_html($settings['site_description'] ?? '');
    $base = rtrim(sv_base($settings), '/');
    $host = parse_url($base, PHP_URL_HOST) ?: $base;
    if ($base === '' || $host === '') return $bio;

    // Don't duplicate if the admin already linked the site in the bio.
    if (stripos($bio, $host) !== false) return $bio;

    $link = 'See <a href="' . htmlspecialchars($base, ENT_QUOTES)
          . '" rel="nofollow noopener me">' . htmlspecialchars($host, ENT_QUOTES)
          . '</a> for more.';
    return $bio === '' ? $link : $bio . '<br><br>' . $link;
}

/**
 * Resolve the blog avatar as an ActivityStreams Image, or null.
 *
 * skin_avatar is a PER-SKIN setting stored prefixed ("<skin>__skin_avatar");
 * the bare key is empty in raw snap_settings, so we apply the active skin's
 * overlay first (exactly like the front end does). The file must exist on disk
 * — we never advertise a broken icon — and the mediaType is derived from the
 * extension (Pixelfed/Mastodon reject a PNG served as image/jpeg).
 */
function sv_avatar(array $settings): ?array {
    $slug = trim($settings['active_skin'] ?? '');
    $s = $settings;
    if ($slug !== '') {
        if (!function_exists('snapsmack_apply_skin_settings')) {
            @require_once __DIR__ . '/skin-settings.php';
        }
        if (function_exists('snapsmack_apply_skin_settings')) {
            snapsmack_apply_skin_settings($s, $slug);
        }
    }
    $path = trim($s['skin_avatar'] ?? '');
    if ($path === '') return null;
    $abs = dirname(__DIR__) . '/' . ltrim($path, '/');  // core/.. = site root
    if (!is_file($abs)) return null;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
              'gif' => 'image/gif', 'webp' => 'image/webp'];
    return [
        'type'      => 'Image',
        'mediaType' => $types[$ext] ?? 'image/jpeg',
        'url'       => sv_base($settings) . ltrim($path, '/'),
    ];
}

/** WebFinger JRD for acct:<handle>@<domain>. Returns null on mismatch. */
function sv_webfinger(string $resource, array $settings): ?array {
    $acct = 'acct:' . sv_handle($settings) . '@' . sv_domain($settings);
    if (strcasecmp(trim($resource), $acct) !== 0) return null;
    return [
        'subject' => $acct,
        'aliases' => [sv_actor_url($settings), rtrim(sv_base($settings), '/')],
        'links'   => [
            ['rel' => 'self', 'type' => 'application/activity+json', 'href' => sv_actor_url($settings)],
            ['rel' => 'http://webfinger.net/rel/profile-page', 'type' => 'text/html',
             'href' => rtrim(sv_base($settings), '/')],
        ],
    ];
}

/** The actor (Person) document. One blog = one actor = the site itself. */
function sv_actor_doc(PDO $pdo, array &$settings): array {
    $actor = sv_actor_url($settings);
    $doc = [
        '@context' => [
            'https://www.w3.org/ns/activitystreams',
            'https://w3id.org/security/v1',
        ],
        'id'                => $actor,
        'type'              => 'Person',
        'preferredUsername' => sv_handle($settings),
        'name'              => $settings['site_name'] ?? 'SnapSmack',
        'summary'           => sv_actor_summary($settings),
        'url'               => rtrim(sv_base($settings), '/'),
        'inbox'             => sv_inbox_url($settings),
        'outbox'            => sv_outbox_url($settings),
        'followers'         => sv_followers_url($settings),
        'following'         => sv_following_url($settings),
        'endpoints'         => ['sharedInbox' => sv_inbox_url($settings)],
        'manuallyApprovesFollowers' => false,
        'discoverable'      => true,
    ];

    $icon = sv_avatar($settings);
    if ($icon !== null) $doc['icon'] = $icon;

    $pub = sv_ensure_keys($pdo, $settings);
    if ($pub !== '') {
        $doc['publicKey'] = [
            'id'           => sv_key_id($settings),
            'owner'        => $actor,
            'publicKeyPem' => $pub,
        ];
    }
    return $doc;
}

/** Followers collection (totalItems only — member list stays private). */
function sv_followers_doc(PDO $pdo, array $settings): array {
    $total = 0;
    try {
        $total = (int)$pdo->query(
            "SELECT COUNT(*) FROM snap_ap_followers WHERE is_active = 1"
        )->fetchColumn();
    } catch (Exception $e) { /* table may not exist yet */ }
    return [
        '@context'   => 'https://www.w3.org/ns/activitystreams',
        'id'         => sv_followers_url($settings),
        'type'       => 'OrderedCollection',
        'totalItems' => $total,
    ];
}

/** Following collection — accounts the blog actor follows (totalItems only;
 *  the list stays private, mirroring the followers collection). */
function sv_following_doc(array $settings, ?PDO $pdo = null): array {
    $total = 0;
    if ($pdo !== null) {
        try {
            $total = (int)$pdo->query(
                "SELECT COUNT(*) FROM snap_ap_following WHERE state = 'accepted'"
            )->fetchColumn();
        } catch (Exception $e) { /* table may not exist yet */ }
    }
    return [
        '@context'   => 'https://www.w3.org/ns/activitystreams',
        'id'         => sv_following_url($settings),
        'type'       => 'OrderedCollection',
        'totalItems' => $total,
    ];
}

// ─── Outbound Follow (0.7.356) — the blog actor follows people ──────────────
// Reach mechanics: following a photographer lands the blog in THEIR
// notifications — that's how follow-backs happen. Publisher-only: we ingest
// nothing from the accounts we follow (no reader, no timeline).

/** Resolve @user@host to an actor URL via the remote server's webfinger. */
function sv_webfinger_lookup(string $handle): ?string {
    $handle = ltrim(trim($handle), '@');
    if (!preg_match('/^([^@\s\/]+)@([^@\s\/]+\.[^@\s\/]+)$/', $handle, $m)) return null;
    $doc = sv_fetch_ap('https://' . $m[2] . '/.well-known/webfinger?resource='
                       . rawurlencode('acct:' . $handle));
    foreach (($doc['links'] ?? []) as $l) {
        if (($l['rel'] ?? '') === 'self' && !empty($l['href'])
            && stripos((string)($l['type'] ?? ''), 'activity') !== false) {
            return (string)$l['href'];
        }
    }
    return null;
}

/**
 * Follow a fediverse account as the blog actor. Accepts @user@host or a raw
 * actor URL. Queues a signed Follow to their inbox and records it pending;
 * the inbound Accept flips it to accepted (Reject → rejected).
 *
 * @return array [bool ok, string message]
 */
function sv_follow_actor(PDO $pdo, array $settings, string $target): array {
    $target = trim($target);
    if ($target === '') return [false, 'Give me a handle (@user@host) or an actor URL.'];

    $actor_url = (stripos($target, 'https://') === 0)
        ? $target
        : sv_webfinger_lookup($target);
    if ($actor_url === null || $actor_url === '') {
        return [false, 'Could not resolve "' . $target . '" — check the handle (format: @user@host).'];
    }
    if ($actor_url === sv_actor_url($settings)) {
        return [false, 'That is this blog. Following yourself is cheaper in therapy.'];
    }

    $doc = sv_fetch_ap($actor_url);
    if (!is_array($doc) || empty($doc['inbox']) || empty($doc['id'])) {
        return [false, 'Fetched the actor but it has no usable inbox — server may be blocking or down.'];
    }
    $inbox = (string)$doc['inbox'];
    if (!sv_url_is_public($inbox)) return [false, 'Actor inbox is not a public URL.'];
    $canonical = (string)$doc['id'];
    $handle    = ($doc['preferredUsername'] ?? '') . '@' . (parse_url($canonical, PHP_URL_HOST) ?: '');

    $follow_id = sv_actor_url($settings) . '#follow-' . bin2hex(random_bytes(8));
    $pdo->prepare(
        "INSERT INTO snap_ap_following (actor_url, actor_handle, inbox_url, follow_id, state)
         VALUES (?, ?, ?, ?, 'pending')
         ON DUPLICATE KEY UPDATE actor_handle=VALUES(actor_handle), inbox_url=VALUES(inbox_url),
                                 follow_id=VALUES(follow_id), state='pending', followed_at=NOW()"
    )->execute([$canonical, substr($handle, 0, 190), $inbox, $follow_id]);

    $follow = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => $follow_id,
        'type'     => 'Follow',
        'actor'    => sv_actor_url($settings),
        'object'   => $canonical,
    ];
    sv_queue_delivery($pdo, $inbox, json_encode($follow, JSON_UNESCAPED_SLASHES));
    sv_process_deliveries($pdo, $settings, 10);
    return [true, 'Follow sent to ' . $handle . ' — it shows as PENDING until their server accepts (usually seconds).'];
}

/** Unfollow: signed Undo wrapping the original Follow, then drop the row. */
function sv_unfollow_actor(PDO $pdo, array $settings, int $row_id): array {
    $s = $pdo->prepare("SELECT * FROM snap_ap_following WHERE id = ? LIMIT 1");
    $s->execute([$row_id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return [false, 'Unknown follow row.'];

    $undo = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => $row['follow_id'] . '#undo-' . bin2hex(random_bytes(6)),
        'type'     => 'Undo',
        'actor'    => sv_actor_url($settings),
        'object'   => [
            'id'     => $row['follow_id'],
            'type'   => 'Follow',
            'actor'  => sv_actor_url($settings),
            'object' => $row['actor_url'],
        ],
    ];
    sv_queue_delivery($pdo, $row['inbox_url'], json_encode($undo, JSON_UNESCAPED_SLASHES));
    sv_process_deliveries($pdo, $settings, 10);
    $pdo->prepare("DELETE FROM snap_ap_following WHERE id = ?")->execute([$row_id]);
    return [true, 'Unfollowed ' . ($row['actor_handle'] ?: $row['actor_url']) . '.'];
}

// ─── Pixelfed client: remote profile crawl + interactions (0.7.362) ─────────

/**
 * Normalise a remote actor into the fields the Pixelfed client renders.
 * Accepts @user@host or a raw actor URL (webfingers a handle first). Returns
 * null when the actor can't be resolved or fetched. Follower/following/post
 * counts are best-effort (collection shells are small); a null count is hidden
 * in the UI rather than shown as zero.
 */
function sv_crawl_actor(string $target): ?array {
    $target = trim($target);
    if ($target === '') return null;
    $actor_url = (stripos($target, 'https://') === 0) ? $target : sv_webfinger_lookup($target);
    if ($actor_url === null || $actor_url === '') return null;
    $doc = sv_fetch_ap($actor_url);
    if (!is_array($doc) || empty($doc['id'])) return null;

    $host   = parse_url((string)$doc['id'], PHP_URL_HOST) ?: '';
    $user   = (string)($doc['preferredUsername'] ?? '');
    $handle = ($user !== '' && $host !== '') ? '@' . $user . '@' . $host : '';

    // Avatar: `icon` may be an object, an array of objects, or a bare string.
    $avatar = '';
    $icon = $doc['icon'] ?? null;
    if (is_array($icon)) {
        if (isset($icon['url']))         $avatar = (string)$icon['url'];
        elseif (isset($icon[0]['url']))  $avatar = (string)$icon[0]['url'];
    } elseif (is_string($icon)) {
        $avatar = $icon;
    }

    $count = static function ($url): ?int {
        if (!is_string($url) || $url === '') return null;
        $c = sv_fetch_ap($url);
        return (is_array($c) && isset($c['totalItems'])) ? (int)$c['totalItems'] : null;
    };

    return [
        'id'        => (string)$doc['id'],
        'handle'    => $handle,
        'username'  => $user,
        'host'      => $host,
        'name'      => (string)($doc['name'] ?? $user),
        'summary'   => isset($doc['summary']) ? (string)$doc['summary'] : '',
        'avatar'    => $avatar,
        'url'       => (string)($doc['url'] ?? $doc['id']),
        'inbox'     => (string)($doc['inbox'] ?? ''),
        'outbox'    => (string)($doc['outbox'] ?? ''),
        'followers' => $count($doc['followers'] ?? ''),
        'following' => $count($doc['following'] ?? ''),
        'posts'     => $count($doc['outbox'] ?? ''),
    ];
}

/**
 * One outbox unit → a render-ready post, or null to skip. Only original
 * image-bearing Creates/Notes pass; boosts (Announce) and text-only notes are
 * dropped (this is a photo grid). A URL-string object is dereferenced once.
 */
function sv_outbox_item_to_post($act): ?array {
    if (!is_array($act)) return null;
    $type = $act['type'] ?? '';
    if ($type !== 'Create' && $type !== 'Note') return null;   // skip Announce/boost etc.
    $obj = ($type === 'Note') ? $act : ($act['object'] ?? null);
    if (is_string($obj)) $obj = sv_fetch_ap($obj);
    if (!is_array($obj)) return null;
    $otype = $obj['type'] ?? '';
    if ($otype !== 'Note' && $otype !== 'Image') return null;

    $images = [];
    foreach (($obj['attachment'] ?? []) as $att) {
        if (!is_array($att)) continue;
        $mt = (string)($att['mediaType'] ?? '');
        if ($mt !== '' && stripos($mt, 'image/') !== 0) continue;   // images only
        $u = $att['url'] ?? '';
        if (is_array($u)) $u = $u['href'] ?? ($u[0]['href'] ?? '');
        $u = (string)$u;
        if ($u === '') continue;
        $images[] = $u;
    }
    if (!$images) return null;

    return [
        'id'        => (string)($obj['id'] ?? ''),
        'url'       => (string)($obj['url'] ?? $obj['id'] ?? ''),
        'published' => (string)($obj['published'] ?? ''),
        'text'      => trim(strip_tags((string)($obj['content'] ?? ''))),
        'images'    => $images,
        'count'     => count($images),
    ];
}

/**
 * Walk a remote actor's paginated outbox and return up to $max recent image
 * posts, newest first — mirroring how Pixelfed's own remote-import path reads
 * an outbox (shell → first → follow `next`). Bounded by a page guard so a
 * hostile/huge outbox can't run us forever.
 */
function sv_crawl_outbox(string $outbox_url, int $max = 36): array {
    $posts = [];
    $outbox_url = trim($outbox_url);
    if ($outbox_url === '') return $posts;
    $shell = sv_fetch_ap($outbox_url);
    if (!is_array($shell)) return $posts;

    $first = $shell['first'] ?? '';
    $page_url = is_array($first) ? (string)($first['id'] ?? '') : (string)$first;
    // Some servers inline orderedItems directly on the shell (no `first`).
    $page = $page_url !== '' ? sv_fetch_ap($page_url) : $shell;

    // Walk enough pages to satisfy $max (our outbox pages are 20 units each);
    // the loop still stops the moment the outbox runs out (next=null).
    $guard = 0;
    $max_pages = max(4, (int)ceil($max / 15) + 2);
    while (is_array($page) && count($posts) < $max && $guard < $max_pages) {
        $guard++;
        $items = $page['orderedItems'] ?? ($page['items'] ?? []);
        if (is_array($items)) {
            foreach ($items as $act) {
                if (count($posts) >= $max) break;
                $p = sv_outbox_item_to_post($act);
                if ($p !== null) $posts[] = $p;
            }
        }
        $next = $page['next'] ?? '';
        $next_url = is_array($next) ? (string)($next['id'] ?? '') : (string)$next;
        if ($next_url === '' || $next_url === $page_url) break;
        $page_url = $next_url;
        $page = sv_fetch_ap($next_url);
    }
    return $posts;
}

/**
 * Applaud (Like) a remote object as the blog actor. Re-fetches the target's
 * author server-side to find the inbox (never trusts a client-supplied inbox),
 * delivers a signed Like. Fire-and-forget — no local state is kept for remote
 * likes (no schema for it), so the client just marks the button applauded.
 *
 * @return array [bool ok, string message]
 */
function sv_like_remote(PDO $pdo, array $settings, string $object_id, string $actor_url): array {
    $object_id = trim($object_id);
    $actor_url = trim($actor_url);
    if ($object_id === '' || stripos($object_id, 'https://') !== 0) return [false, 'That post has no usable id.'];
    if (stripos($actor_url, 'https://') !== 0) return [false, 'That account has no usable id.'];
    $actor = sv_fetch_ap($actor_url);
    if (!is_array($actor) || empty($actor['inbox'])) return [false, 'Could not reach that account to applaud.'];
    $inbox = (string)$actor['inbox'];
    if (!sv_url_is_public($inbox)) return [false, 'That account inbox is not a public URL.'];

    $like_id = sv_actor_url($settings) . '#like-' . bin2hex(random_bytes(8));
    $like = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => $like_id,
        'type'     => 'Like',
        'actor'    => sv_actor_url($settings),
        'object'   => $object_id,
    ];
    sv_queue_delivery($pdo, $inbox, json_encode($like, JSON_UNESCAPED_SLASHES));
    sv_process_deliveries($pdo, $settings, 10);
    try {
        $pdo->prepare("INSERT INTO snap_ap_outbound_likes (object_id, like_id) VALUES (?,?)
                       ON DUPLICATE KEY UPDATE like_id=VALUES(like_id)")
            ->execute([substr($object_id, 0, 500), substr($like_id, 0, 600)]);
    } catch (Exception $e) { /* table may lag */ }
    return [true, 'Applause sent.'];
}

/** Undo a remote applause (Like) — sends Undo{Like} and drops the state row. */
function sv_unlike_remote(PDO $pdo, array $settings, string $object_id, string $actor_url): array {
    $object_id = trim($object_id);
    $actor_url = trim($actor_url);
    $s = $pdo->prepare("SELECT like_id FROM snap_ap_outbound_likes WHERE object_id = ? LIMIT 1");
    $s->execute([$object_id]);
    $like_id = (string)($s->fetchColumn() ?: '');
    if ($like_id === '') return [false, 'You have not applauded that.'];
    $actor = sv_fetch_ap($actor_url);
    if (!is_array($actor) || empty($actor['inbox'])) return [false, 'Could not reach that account.'];
    $inbox = (string)$actor['inbox'];
    if (!sv_url_is_public($inbox)) return [false, 'That account inbox is not a public URL.'];
    $undo = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => $like_id . '#undo-' . bin2hex(random_bytes(6)),
        'type'     => 'Undo',
        'actor'    => sv_actor_url($settings),
        'object'   => ['id' => $like_id, 'type' => 'Like', 'actor' => sv_actor_url($settings), 'object' => $object_id],
    ];
    sv_queue_delivery($pdo, $inbox, json_encode($undo, JSON_UNESCAPED_SLASHES));
    sv_process_deliveries($pdo, $settings, 10);
    $pdo->prepare("DELETE FROM snap_ap_outbound_likes WHERE object_id = ?")->execute([$object_id]);
    return [true, 'Applause withdrawn.'];
}

/** Has the blog applauded this remote object? (like-state for the UI) */
function sv_has_liked(PDO $pdo, string $object_id): bool {
    if ($object_id === '') return false;
    try {
        $s = $pdo->prepare("SELECT 1 FROM snap_ap_outbound_likes WHERE object_id = ? LIMIT 1");
        $s->execute([$object_id]);
        return (bool)$s->fetchColumn();
    } catch (Exception $e) { return false; }
}

/** Boost (Announce) a remote post to the blog's followers. */
function sv_boost_remote(PDO $pdo, array $settings, string $object_id): array {
    $object_id = trim($object_id);
    if ($object_id === '' || stripos($object_id, 'https://') !== 0) return [false, 'That post has no usable id.'];
    $inboxes = sv_follower_inboxes($pdo);
    if (!$inboxes) return [false, 'No followers to boost to yet.'];
    $announce = [
        '@context'  => 'https://www.w3.org/ns/activitystreams',
        'id'        => sv_actor_url($settings) . '#boost-' . bin2hex(random_bytes(8)),
        'type'      => 'Announce',
        'actor'     => sv_actor_url($settings),
        'published' => gmdate('Y-m-d\TH:i:s\Z'),
        'to'        => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc'        => [sv_followers_url($settings)],
        'object'    => $object_id,
    ];
    $json = json_encode($announce, JSON_UNESCAPED_SLASHES);
    foreach ($inboxes as $inbox) sv_queue_delivery($pdo, $inbox, $json);
    sv_process_deliveries($pdo, $settings, 20);
    return [true, 'Boosted to your followers.'];
}

/**
 * Reply to a remote object as the blog actor. The reply gets a DURABLE row and
 * a real permalink (/ap/note/r/<token>) so it dereferences, threads, and the
 * other person can reply back — retiring the old fire-and-forget id.
 *
 * @return array [bool ok, string message]
 */
function sv_reply_remote(PDO $pdo, array $settings, string $object_id, string $actor_url, string $content): array {
    $object_id = trim($object_id);
    $actor_url = trim($actor_url);
    $content   = trim($content);
    if ($object_id === '' || stripos($object_id, 'https://') !== 0) return [false, 'That post has no usable id.'];
    if ($content === '') return [false, 'Write something first.'];
    if (mb_strlen($content) > 2000) $content = mb_substr($content, 0, 2000);
    $actor = sv_fetch_ap($actor_url);
    if (!is_array($actor) || empty($actor['inbox']) || empty($actor['id'])) return [false, 'Could not reach that account to reply.'];
    $inbox = (string)$actor['inbox'];
    if (!sv_url_is_public($inbox)) return [false, 'That account inbox is not a public URL.'];

    $their_id     = (string)$actor['id'];
    $their_user   = (string)($actor['preferredUsername'] ?? '');
    $their_handle = $their_user . '@' . (parse_url($their_id, PHP_URL_HOST) ?: '');

    $token = bin2hex(random_bytes(12));
    try {
        $pdo->prepare(
            "INSERT INTO snap_ap_outbound_replies (token, in_reply_to, to_actor_url, to_handle, content)
             VALUES (?,?,?,?,?)"
        )->execute([$token, substr($object_id, 0, 500), substr($their_id, 0, 500), substr($their_handle, 0, 190), $content]);
    } catch (Exception $e) { return [false, 'Could not save the reply.']; }

    $note = sv_outbound_reply_doc($pdo, $token, $settings);
    if ($note === null) return [false, 'Could not build the reply.'];
    $create = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => $note['id'] . '/activity',
        'type'     => 'Create',
        'actor'    => sv_actor_url($settings),
        'to'       => $note['to'],
        'cc'       => $note['cc'],
        'object'   => $note,
    ];
    sv_queue_delivery($pdo, $inbox, json_encode($create, JSON_UNESCAPED_SLASHES));
    sv_process_deliveries($pdo, $settings, 10);
    return [true, 'Reply sent to @' . $their_handle . '.'];
}

/** Build the dereferenceable AP Note for a stored outbound reply, or null. */
function sv_outbound_reply_doc(PDO $pdo, string $token, array $settings): ?array {
    if (!preg_match('/^[a-f0-9]{8,48}$/', $token)) return null;
    $s = $pdo->prepare("SELECT * FROM snap_ap_outbound_replies WHERE token = ? LIMIT 1");
    $s->execute([$token]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $their_id   = (string)$r['to_actor_url'];
    $their_user = explode('@', (string)$r['to_handle'])[0];
    $safe       = nl2br(htmlspecialchars((string)$r['content'], ENT_QUOTES, 'UTF-8'));
    $permalink  = sv_base($settings) . 'ap/note/r/' . $r['token'];
    return [
        '@context'     => 'https://www.w3.org/ns/activitystreams',
        'id'           => $permalink,
        'type'         => 'Note',
        'attributedTo' => sv_actor_url($settings),
        'inReplyTo'    => (string)$r['in_reply_to'],
        'published'    => gmdate('Y-m-d\TH:i:s\Z', strtotime((string)$r['published'])),
        'url'          => $permalink,
        'content'      => '<p><span class="h-card"><a href="' . htmlspecialchars($their_id, ENT_QUOTES)
                          . '">@' . htmlspecialchars($their_user, ENT_QUOTES) . '</a></span> ' . $safe . '</p>',
        'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc'           => [$their_id, sv_followers_url($settings)],
        'tag'          => [['type' => 'Mention', 'href' => $their_id, 'name' => '@' . (string)$r['to_handle']]],
    ];
}

// ─── Reader / engagement queries (for the client) ───────────────────────────

/** Recent inbound notifications joined with the actor cache, newest first. */
function sv_notifications(PDO $pdo, int $limit = 60): array {
    try {
        $s = $pdo->prepare(
            "SELECT n.*, a.name AS actor_name, a.avatar_url, a.profile_url
             FROM snap_ap_notifications n
             LEFT JOIN snap_ap_actors a ON a.actor_url = n.actor_url
             ORDER BY n.created_at DESC LIMIT " . (int)$limit
        );
        $s->execute();
        return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { return []; }
}

/** Count of unread notifications (badge). */
function sv_unread_count(PDO $pdo): int {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM snap_ap_notifications WHERE is_read = 0")->fetchColumn(); }
    catch (Exception $e) { return 0; }
}

/** Mark all notifications read. */
function sv_mark_notifications_read(PDO $pdo): void {
    try { $pdo->exec("UPDATE snap_ap_notifications SET is_read = 1 WHERE is_read = 0"); }
    catch (Exception $e) { /* table may lag */ }
}

/** Home reader from the ingested timeline, newest first (client post shape). */
function sv_home_timeline(PDO $pdo, int $limit = 60): array {
    try {
        $s = $pdo->prepare(
            "SELECT t.*, a.name AS actor_name, a.avatar_url
             FROM snap_ap_timeline t
             LEFT JOIN snap_ap_actors a ON a.actor_url = t.actor_url
             WHERE t.source = 'home'
             ORDER BY COALESCE(t.published, t.fetched_at) DESC LIMIT " . (int)$limit
        );
        $s->execute();
        $rows = $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Exception $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $imgs = json_decode((string)$r['media_json'], true);
        if (!is_array($imgs) || !$imgs) continue;
        $out[] = [
            'id' => $r['object_id'], 'url' => $r['url'], 'published' => $r['published'],
            'text' => $r['content'], 'images' => $imgs, 'count' => count($imgs),
            'is_boost' => (int)$r['is_boost'],
            'author' => [
                'handle' => $r['actor_handle'], 'name' => $r['actor_name'] ?: $r['actor_handle'],
                'avatar' => $r['avatar_url'], 'id' => $r['actor_url'],
                'host'   => parse_url((string)$r['actor_url'], PHP_URL_HOST) ?: '',
            ],
        ];
    }
    return $out;
}

/** Local/Global discovery: a chosen instance's public timeline via REST. */
function sv_public_timeline(string $host, bool $local, int $limit = 30): array {
    $host = trim($host);
    if ($host === '') return [];
    $base = 'https://' . $host;
    $q = 'limit=' . max(1, min($limit, 40)) . '&only_media=true' . ($local ? '&local=true' : '');
    $rows = sv_fetch_json($base . '/api/pixelfed/v1/timelines/public?' . $q);
    if (!is_array($rows) || !$rows) $rows = sv_fetch_json($base . '/api/v1/timelines/public?' . $q);
    if (!is_array($rows)) return [];
    $out = [];
    foreach ($rows as $st) {
        if (!is_array($st)) continue;
        $imgs = [];
        foreach (($st['media_attachments'] ?? []) as $m) {
            if (is_array($m) && ($m['type'] ?? '') === 'image') {
                $u = (string)($m['url'] ?? ($m['preview_url'] ?? '')); if ($u !== '') $imgs[] = $u;
            }
        }
        if (!$imgs) continue;
        $acct = is_array($st['account'] ?? null) ? $st['account'] : [];
        $out[] = [
            'id'        => (string)($st['uri'] ?? ($st['url'] ?? '')),
            'url'       => (string)($st['url'] ?? ($st['uri'] ?? '')),
            'published' => (string)($st['created_at'] ?? ''),
            'text'      => (isset($st['content_text']) && $st['content_text'] !== '')
                            ? (string)$st['content_text']
                            : trim(strip_tags((string)($st['content'] ?? ''))),
            'images'    => $imgs, 'count' => count($imgs),
            'author'    => [
                'handle' => (string)($acct['acct'] ?? ''),
                'name'   => (string)($acct['display_name'] ?? ($acct['username'] ?? '')),
                'avatar' => (string)($acct['avatar'] ?? ''),
                'id'     => (string)($acct['url'] ?? ''),
                'host'   => parse_url((string)($acct['url'] ?? ''), PHP_URL_HOST) ?: $host,
            ],
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}

/**
 * Follow-state lookup for a resolved actor URL: returns [state, row_id] where
 * state is '', 'pending', 'accepted' or 'rejected'. Lets the client show the
 * right Follow/Pending/Unfollow control on a crawled profile.
 */
function sv_following_state(PDO $pdo, string $actor_url): array {
    if ($actor_url === '') return ['', 0];
    $s = $pdo->prepare("SELECT id, state FROM snap_ap_following WHERE actor_url = ? LIMIT 1");
    $s->execute([$actor_url]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['', 0];
    return [(string)$row['state'], (int)$row['id']];
}

/**
 * Fetch a plain-JSON document (Mastodon-compatible REST API). Separate from
 * sv_fetch_ap so we send Accept: application/json and a bigger size cap — a
 * statuses list is far larger than one Note. Same SSRF guard + IP pin.
 */
function sv_fetch_json(string $url, int $timeout = 12): ?array {
    if (!function_exists('curl_init')) return null;
    $res = sv_resolve_public($url);
    if ($res === null) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE        => $res['pin'],
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: SnapSmack-SMACKVERSE/' . (defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : '0'),
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) return null;
    if (strlen($body) > 2097152) return null;   // 2MB
    $doc = json_decode($body, true);
    return is_array($doc) ? $doc : null;
}

/**
 * A remote account's recent MEDIA posts via the Mastodon-compatible REST API
 * (implemented by BOTH Pixelfed and Mastodon). This is the real way to read a
 * Pixelfed account's photos: Pixelfed's ActivityPub outbox is a bare stub
 * (totalItems only, no items/pages), so an AP outbox crawl always comes back
 * empty for Pixelfed. Steps: lookup the acct → numeric id, then pull statuses
 * with only_media. Returns [] when the instance has no REST API (pure-AP
 * servers) so the caller can fall back to the outbox crawl.
 */
function sv_masto_statuses(string $host, string $username, int $max = 36): array {
    $host = trim($host);
    $username = trim($username);
    if ($host === '' || $username === '') return [];
    $base = 'https://' . $host;

    $look = sv_fetch_json($base . '/api/v1/accounts/lookup?acct=' . rawurlencode($username));
    if (!is_array($look) || empty($look['id'])) return [];
    $acct_id = preg_replace('/[^A-Za-z0-9]/', '', (string)$look['id']);
    if ($acct_id === '') return [];

    $limit = max(1, min($max, 40));
    // Mastodon serves /api/v1/accounts/{id}/statuses publicly. Pixelfed GATES
    // that route behind auth (it returns empty for a guest) but exposes the SAME
    // data unauthenticated at /api/pixelfed/v1/... — the route its own logged-out
    // web UI uses. Try Mastodon first (covers real Mastodon), fall back to the
    // Pixelfed public route (covers Pixelfed).
    $rows  = sv_fetch_json($base . '/api/v1/accounts/' . $acct_id . '/statuses?limit=' . $limit . '&only_media=1');
    $posts = sv_masto_map_statuses(is_array($rows) ? $rows : [], $max);
    if (!$posts) {
        $rows  = sv_fetch_json($base . '/api/pixelfed/v1/accounts/' . $acct_id . '/statuses?limit=' . $limit);
        $posts = sv_masto_map_statuses(is_array($rows) ? $rows : [], $max);
    }
    return $posts;
}

/**
 * Map a Mastodon/Pixelfed status list into our render-ready post shape. Both
 * the Mastodon and the Pixelfed public routes return the same status entity
 * (Pixelfed adds `content_text`, which we prefer for a clean caption). Photos
 * only — a status with no image attachment is skipped.
 */
function sv_masto_map_statuses(array $rows, int $max): array {
    $posts = [];
    foreach ($rows as $st) {
        if (!is_array($st)) continue;
        $imgs = [];
        foreach (($st['media_attachments'] ?? []) as $m) {
            if (!is_array($m)) continue;
            if (($m['type'] ?? '') !== 'image') continue;   // photos only
            $u = (string)($m['url'] ?? ($m['preview_url'] ?? ''));
            if ($u !== '') $imgs[] = $u;
        }
        if (!$imgs) continue;
        $text = (isset($st['content_text']) && $st['content_text'] !== '')
            ? (string)$st['content_text']
            : trim(strip_tags((string)($st['content'] ?? '')));
        $posts[] = [
            'id'        => (string)($st['uri'] ?? ($st['url'] ?? '')),   // canonical AP id for like/reply
            'url'       => (string)($st['url'] ?? ($st['uri'] ?? '')),   // HTML permalink
            'published' => (string)($st['created_at'] ?? ''),
            'text'      => $text,
            'images'    => $imgs,
            'count'     => count($imgs),
        ];
        if (count($posts) >= $max) break;
    }
    return $posts;
}

/**
 * Universal recent-photo fetch for a crawled actor: Mastodon REST API first
 * (Pixelfed + Mastodon), AP outbox crawl as fallback (pure-AP servers such as
 * our own SnapSmack blog, whose outbox IS fully populated). $actor is a
 * sv_crawl_actor() result.
 */
function sv_fetch_gallery(array $actor, int $max = 36): array {
    $posts = sv_masto_statuses((string)($actor['host'] ?? ''), (string)($actor['username'] ?? ''), $max);
    if ($posts) return $posts;
    return sv_crawl_outbox((string)($actor['outbox'] ?? ''), $max);
}

/**
 * Home feed by live crawl: merge the recent photos of the accounts the blog
 * FOLLOWS (accepted only), newest first. No ingest tables — this pulls on
 * demand, bounded by an account cap and an ~18s wall-clock budget so a busy
 * follow list can't stall the request. A push/ingest timeline is the Phase-3
 * upgrade for scale; this lights up Home now.
 */
function sv_home_feed(PDO $pdo, int $per_account = 6, int $max = 40): array {
    $rows = $pdo->query(
        "SELECT actor_url FROM snap_ap_following WHERE state='accepted' ORDER BY followed_at DESC LIMIT 15"
    )->fetchAll(PDO::FETCH_COLUMN);

    $feed = [];
    $deadline = microtime(true) + 18.0;
    foreach ($rows as $actor_url) {
        if (microtime(true) > $deadline) break;
        $actor = sv_crawl_actor((string)$actor_url);
        if ($actor === null) continue;
        foreach (sv_fetch_gallery($actor, $per_account) as $p) {
            $p['author'] = [
                'handle' => $actor['handle'],
                'name'   => $actor['name'],
                'avatar' => $actor['avatar'],
                'id'     => $actor['id'],
                'host'   => $actor['host'],
                'url'    => $actor['url'],
            ];
            $feed[] = $p;
        }
    }
    usort($feed, static function ($a, $b) { return strcmp((string)$b['published'], (string)$a['published']); });
    return array_slice($feed, 0, $max);
}

// ─── Notes (one published snap_image = one Note, mirroring rss.php) ─────────

/** Media type from an image file extension. */
function sv_media_type(string $file): string {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp'];
    return $map[$ext] ?? 'image/jpeg';
}

/**
 * Pre-baked federation frame (`f_` sidecar) URL for an image, or null.
 * Pixelfed/Mastodon can't CSS-frame, so the elegant matte/border/shadow is
 * baked into `f_<file>` (client-side at post time; server-baked only inside
 * the bounded backfill window). Convention-based like t_/a_ — no schema.
 * Spec: _spec/smackverse-frame-bake-spec-v0_1.md
 */
function sv_frame_url(array $img, array $settings): ?string {
    $file = $img['img_file'] ?? '';
    if ($file === '') return null;
    $rel = dirname($file) . '/thumbs/f_' . basename($file);
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $abs = dirname(__DIR__) . '/' . $rel;   // core/.. = site root
    if (!is_file($abs)) return null;
    return sv_base($settings) . $rel;
}

/**
 * ActivityStreams focalPoint [x,y] from the stored img_focus_x/y (0..100),
 * or null when centered/absent. Lets Pixelfed's own square grid-tile crop
 * center on the composed focal point instead of dead-center.
 *   x: 0→-1 (left) .. 100→+1 (right);  y: 0(top)→+1 .. 100(bottom)→-1.
 */
function sv_focal_point(array $img): ?array {
    if (!isset($img['img_focus_x'], $img['img_focus_y'])) return null;
    $fx = (int)$img['img_focus_x']; $fy = (int)$img['img_focus_y'];
    if ($fx === 50 && $fy === 50) return null;   // center = default, omit
    return [round(($fx / 100) * 2 - 1, 3), round(1 - ($fy / 100) * 2, 3)];
}

/**
 * The FEDIVERSE BAKE (p_) — the 1080² square render carrying the curated
 * crop + frame (snapsmack_generate_fedi_bake). Convention-based, no schema:
 * thumbs/p_<basename>.jpg next to the source. Null when not (yet) baked.
 */
function sv_fedi_bake_url(array $img, array $settings): ?string {
    $file = $img['img_file'] ?? '';
    if ($file === '') return null;
    $rel = dirname($file) . '/thumbs/p_' . pathinfo($file, PATHINFO_FILENAME) . '.jpg';
    $rel = ltrim(str_replace('\\', '/', $rel), '/');
    $abs = dirname(__DIR__) . '/' . $rel;   // core/.. = site root
    if (!is_file($abs)) return null;
    return sv_base($settings) . $rel;
}

/**
 * Build one image Document attachment. Preference order:
 *   1. p_ fediverse bake — the curated square (crop + frame), 1080². The
 *      remote grid mirrors the blog's feed (Sean + Opus decision).
 *   2. f_ legacy frame bake (older convention), 3. raw file at full res.
 * focalPoint ships only with the raw file — the bakes ARE the crop.
 */
function sv_image_attachment(array $img, array $settings, string $alt = '', bool $derive = true): array {
    $bake  = sv_fedi_bake_url($img, $settings);
    $frame = $bake ?? sv_frame_url($img, $settings);
    $url   = $frame ?? (sv_base($settings) . ltrim($img['img_file'] ?? '', '/'));
    $type  = $frame ? 'image/jpeg' : sv_media_type($img['img_file'] ?? '');
    // $derive=false lets a carousel pass a per-image alt (or none) WITHOUT the
    // description fallback — otherwise every frame of a post whose members all
    // carry the same caption gets that whole caption repeated as its alt text.
    if ($alt === '' && $derive) {
        $alt = trim($img['img_title'] ?? '');
        if ($alt === '') $alt = trim($img['img_description'] ?? '');
    }
    $att = ['type' => 'Document', 'mediaType' => $type, 'url' => $url, 'name' => $alt];
    if ($frame === null) {   // raw file only — the bakes already ARE the crop
        $fp = sv_focal_point($img);
        if ($fp !== null) $att['focalPoint'] = $fp;
        // Dimension metadata — only trustworthy for the raw file. A frame/bake
        // pads or crops the original px, and we don't read the baked file off
        // disk here, so emitting img_width/img_height against a baked URL
        // would lie about its actual size. Raw-file case: the DB columns are
        // exactly the served file's dimensions.
        $w = (int)($img['img_width'] ?? 0);
        $h = (int)($img['img_height'] ?? 0);
        if ($w > 0 && $h > 0) { $att['width'] = $w; $att['height'] = $h; }
    }
    return $att;
}

/**
 * Fetch one published STANDALONE image row by id, or null. Grouped images
 * (post_id set) federate through their post's Note, never individually —
 * that is what keeps carousels Pixelfed-shaped (one Note, many attachments).
 */
function sv_image_row(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        "SELECT * FROM snap_images
         WHERE id = ? AND img_status = 'published' AND img_date <= NOW() AND post_id IS NULL"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Fetch one published grouped post (single/carousel/panorama) by id, or null. */
function sv_post_row(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        "SELECT * FROM snap_posts
         WHERE id = ? AND status = 'published' AND created_at <= NOW()
           AND post_type IN ('single','carousel','panorama')"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** The post's images in carousel order (is_cover first within ties). */
function sv_post_images(PDO $pdo, int $post_id): array {
    $stmt = $pdo->prepare(
        // pi.img_focus_x/y override i.* so focalPoint reflects the per-image
        // crop the composer set (they alias over the base image columns).
        "SELECT i.*, pi.sort_position, pi.is_cover,
                pi.img_focus_x, pi.img_focus_y, pi.img_zoom,
                pi.img_border_px, pi.img_border_color, pi.img_bg_color, pi.img_shadow, pi.img_size_pct
         FROM snap_post_images pi
         JOIN snap_images i ON i.id = pi.image_id
         WHERE pi.post_id = ? AND i.img_status = 'published'
         ORDER BY pi.sort_position ASC, pi.is_cover DESC, i.id ASC"
    );
    $stmt->execute([$post_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Build the Note for an image row. id = dereferenceable JSON at
 * ?ap=note&id=N; url = the human permalink (site_url + img_slug — the
 * exact rss.php formula). Hashtags come from snap_image_tags/snap_tags.
 */
function sv_note_for_image(PDO $pdo, array $img, array $settings): array {
    $base      = sv_base($settings);
    $permalink = $base . $img['img_slug'];
    $note_id   = $base . 'ap/note/i/' . (int)$img['id'];

    $title = trim($img['img_title'] ?? '');
    $desc  = trim($img['img_description'] ?? '');
    // No title → no caption line. Never federate the literal word "Untitled"
    // (Pixelfed posts have no titles anyway — an untitled post reads as a clean
    // image + whatever caption/tags exist).
    $content = '';
    if ($title !== '') {
        $content .= '<p><a href="' . htmlspecialchars($permalink) . '">'
                  . htmlspecialchars($title) . '</a></p>';
    }
    if ($desc !== '') {
        $content .= '<p>' . nl2br(htmlspecialchars($desc)) . '</p>';
    }

    // Hashtags → content line + tag[] objects.
    $tags = [];
    try {
        $tstmt = $pdo->prepare(
            "SELECT t.tag, t.slug FROM snap_image_tags it
             JOIN snap_tags t ON t.id = it.tag_id WHERE it.image_id = ?"
        );
        $tstmt->execute([(int)$img['id']]);
        $tags = $tstmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* tags optional */ }
    if ($tags) {
        $bits = [];
        $tagobjs = [];
        foreach ($tags as $t) {
            $href = $base . '?tag=' . rawurlencode($t['slug']);
            $name = '#' . preg_replace('/\s+/', '', $t['tag']);
            $bits[] = '<a href="' . htmlspecialchars($href) . '" rel="tag">' . htmlspecialchars($name) . '</a>';
            $tagobjs[] = ['type' => 'Hashtag', 'href' => $href, 'name' => $name];
        }
        $content .= '<p>' . implode(' ', $bits) . '</p>';
    }

    $note = [
        '@context'     => 'https://www.w3.org/ns/activitystreams',
        'id'           => $note_id,
        'type'         => 'Note',
        'attributedTo' => sv_actor_url($settings),
        'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc'           => [sv_followers_url($settings)],
        'published'    => gmdate('Y-m-d\TH:i:s\Z', strtotime($img['img_date'])),
        'url'          => $permalink,
        'content'      => $content,
        'attachment'   => [sv_image_attachment($img, $settings, $title !== '' ? $title : $desc)],
    ];
    if (!empty($tagobjs)) $note['tag'] = $tagobjs;
    return $note;
}

/**
 * Build ONE Note for a grouped post (single/carousel/panorama) with the
 * post's images as ORDERED multi-attachments — the shape Pixelfed expects
 * for a carousel (and Mastodon renders as a gallery). Permalink = the
 * cover image's slug (exactly the URL the grid tiles link to); Note id is
 * the dereferenceable JSON at ?ap=note&post=N. Hashtags = the union of
 * the member images' tags. Returns null if the post has no live images.
 */
function sv_note_for_post(PDO $pdo, array $post, array $settings): ?array {
    $images = sv_post_images($pdo, (int)$post['id']);
    if (!$images) return null;

    $base    = sv_base($settings);
    $cover   = $images[0];
    foreach ($images as $im) { if (!empty($im['is_cover'])) { $cover = $im; break; } }
    $permalink = $base . $cover['img_slug'];
    $note_id   = $base . 'ap/note/p/' . (int)$post['id'];

    $title = trim($post['title'] ?? '');
    $desc  = trim($post['description'] ?? '');
    // No title → no caption line. Never federate the literal word "Untitled".
    $content = '';
    if ($title !== '') {
        $content .= '<p><a href="' . htmlspecialchars($permalink) . '">'
                  . htmlspecialchars($title) . '</a></p>';
    }
    if ($desc !== '') {
        $content .= '<p>' . nl2br(htmlspecialchars($desc)) . '</p>';
    }

    // Trigram slots federate INDIVIDUALLY: the blog keeps the 3-across banner
    // presentation; Pixelfed gets each slot as a normal post. For a trigram
    // CAROUSEL the slice-cover FEDERATES and LEADS the attachments (Sean's
    // call, 2026-07-02 — overriding the earlier drop-the-cover decision): the
    // remote grid tile is the Note's FIRST attachment, so leading with the
    // slice makes the three tiles reassemble the 3-across banner on the
    // remote profile — same WYSIWYG rule as the frames. Members follow in
    // carousel order. (A trigram SINGLE's only image IS its slice; it
    // federates as-is.)
    if (!empty($post['trigram_id']) && count($images) > 1) {
        $lead = []; $rest = [];
        foreach ($images as $im) {
            if (!empty($im['is_cover'])) $lead[] = $im;
            else                         $rest[] = $im;
        }
        $images = array_merge($lead, $rest);
    }

    // Attachments: every member image, carousel order, alt text per image.
    // Hard cap 10 — Pixelfed's carousel ceiling. The composer and editor both
    // enforce 10 total now, but the editor allowed 20 before 0.7.341, so any
    // legacy oversized carousel federates its first ten rather than bouncing.
    $images = array_slice($images, 0, 10);
    $attachments = [];
    foreach ($images as $im) {
        // Prefer the baked f_ frame (elegance) + per-image focalPoint; the
        // sv_post_images query carries img_focus_x/y for the focal crop.
        // Per-image alt only (this image's own title) — NEVER the shared post
        // caption, or every carousel frame repeats the whole essay. derive=false
        // stops the description fallback.
        $imAlt = trim($im['img_title'] ?? '');
        $attachments[] = sv_image_attachment($im, $settings, $imAlt, false);
    }

    // Hashtags: union of member images' tags (GRAM stores tags per image).
    $tagobjs = []; $bits = []; $seen = [];
    try {
        $ids = implode(',', array_map(function ($im) { return (int)$im['id']; }, $images));
        $trows = $pdo->query(
            "SELECT DISTINCT t.tag, t.slug FROM snap_image_tags it
             JOIN snap_tags t ON t.id = it.tag_id WHERE it.image_id IN ($ids)"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($trows as $t) {
            if (isset($seen[$t['slug']])) continue;
            $seen[$t['slug']] = true;
            $href = $base . '?tag=' . rawurlencode($t['slug']);
            $name = '#' . preg_replace('/\s+/', '', $t['tag']);
            $bits[] = '<a href="' . htmlspecialchars($href) . '" rel="tag">' . htmlspecialchars($name) . '</a>';
            $tagobjs[] = ['type' => 'Hashtag', 'href' => $href, 'name' => $name];
        }
    } catch (Exception $e) { /* tags optional */ }
    if ($bits) $content .= '<p>' . implode(' ', $bits) . '</p>';

    $note = [
        '@context'     => 'https://www.w3.org/ns/activitystreams',
        'id'           => $note_id,
        'type'         => 'Note',
        'attributedTo' => sv_actor_url($settings),
        'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc'           => [sv_followers_url($settings)],
        'published'    => gmdate('Y-m-d\TH:i:s\Z', strtotime($post['created_at'])),
        'url'          => $permalink,
        'summary'      => null,
        'sensitive'    => false,
        'content'      => $content,
        'attachment'   => $attachments,
    ];
    if ($tagobjs) $note['tag'] = $tagobjs;
    return $note;
}

/**
 * The federated Note that represents the content a comment sits on: the
 * image's POST Note if the image is grouped, else the standalone image Note.
 */
function sv_content_note_id_for_image(PDO $pdo, int $img_id, array $settings): ?string {
    $s = $pdo->prepare("SELECT post_id FROM snap_images WHERE id = ? LIMIT 1");
    $s->execute([$img_id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $base = sv_base($settings);
    return !empty($row['post_id'])
        ? $base . 'ap/note/p/' . (int)$row['post_id']
        : $base . 'ap/note/i/' . $img_id;
}

/**
 * Build a Note for a LOCAL blog comment being federated out. Single-actor
 * rule: it's authored by the blog, with "<Author> wrote:" in the body so the
 * fediverse sees who said it. Threads under the content's Note (or the parent
 * comment's Note for a fediverse reply chain).
 */
function sv_note_for_comment(PDO $pdo, array $c, array $settings): ?array {
    $img_id = (int)$c['img_id'];
    $parent = trim($c['ap_in_reply_to'] ?? '');
    if ($parent === '') $parent = sv_content_note_id_for_image($pdo, $img_id, $settings);
    if (!$parent) return null;

    $base    = sv_base($settings);
    $note_id = $c['ap_note_id'] ?: ($base . 'ap/note/c/' . (int)$c['id']);
    $author  = trim($c['comment_author'] ?? '') ?: 'Someone';
    $body    = trim($c['comment_text'] ?? '');
    $content = '<p><strong>' . htmlspecialchars($author) . '</strong> wrote:</p><p>'
             . nl2br(htmlspecialchars($body)) . '</p>';

    // If this replies to a FEDIVERSE comment, MENTION its author — the Mention
    // tag is what makes their server notify them. That's the difference
    // between shouting into the void and an actual conversation.
    $mention = null;
    if ($parent !== '') {
        try {
            $ps = $pdo->prepare(
                "SELECT ap_actor_url, comment_author FROM snap_comments
                 WHERE ap_object_id = ? AND ap_source = 'fediverse' LIMIT 1"
            );
            $ps->execute([$parent]);
            $pr = $ps->fetch(PDO::FETCH_ASSOC);
            $ah = $pr ? trim($pr['ap_actor_url'] ?? '') : '';
            if ($ah !== '') {
                $host = parse_url($ah, PHP_URL_HOST) ?: '';
                $user = trim((string)($pr['comment_author'] ?? ''));
                $user = $user !== '' ? preg_replace('/[@\s].*$/', '', ltrim($user, '@'))
                                     : basename(parse_url($ah, PHP_URL_PATH) ?: '');
                $name = '@' . $user . ($host !== '' ? '@' . $host : '');
                $mention = ['type' => 'Mention', 'href' => $ah, 'name' => $name];
                $content = '<p><a href="' . htmlspecialchars($ah) . '" class="u-url mention">'
                         . htmlspecialchars($name) . '</a></p>' . $content;
            }
        } catch (Exception $e) { /* mention is best-effort */ }
    }

    $note = [
        '@context'     => 'https://www.w3.org/ns/activitystreams',
        'id'           => $note_id,
        'type'         => 'Note',
        'attributedTo' => sv_actor_url($settings),
        'inReplyTo'    => $parent,
        'to'           => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc'           => [sv_followers_url($settings)],
        'published'    => gmdate('Y-m-d\TH:i:s\Z', strtotime($c['comment_date'] ?? 'now')),
        'content'      => $content,
    ];
    if ($mention !== null) {
        $note['tag']  = [$mention];
        $note['cc'][] = $mention['href'];
    }
    return $note;
}

/**
 * Federate an APPROVED local comment out as the blog actor. No-op for remote
 * comments (never echo them back) and unapproved ones. Called from the comment
 * approval path. Assigns + persists a stable Note id, then queues delivery to
 * every follower.
 */
function sv_federate_comment(PDO $pdo, int $comment_id, array $settings): void {
    if (!sv_enabled($settings)) return;
    $s = $pdo->prepare("SELECT * FROM snap_comments WHERE id = ? LIMIT 1");
    $s->execute([$comment_id]);
    $c = $s->fetch(PDO::FETCH_ASSOC);
    if (!$c) return;
    if (($c['ap_source'] ?? 'local') === 'fediverse') return;  // don't boomerang remote comments
    if ((int)($c['is_approved'] ?? 0) !== 1) return;           // only approved

    if (empty($c['ap_note_id'])) {
        $c['ap_note_id'] = sv_base($settings) . 'ap/note/c/' . (int)$c['id'];
        $pdo->prepare("UPDATE snap_comments SET ap_note_id = ? WHERE id = ?")
            ->execute([$c['ap_note_id'], (int)$c['id']]);
    }
    $note = sv_note_for_comment($pdo, $c, $settings);
    if ($note === null) return;

    $create = [
        '@context'  => 'https://www.w3.org/ns/activitystreams',
        'id'        => $note['id'] . '#create',
        'type'      => 'Create',
        'actor'     => sv_actor_url($settings),
        'published' => $note['published'],
        'to'        => $note['to'],
        'cc'        => $note['cc'],
        'object'    => $note,
    ];
    $json = json_encode($create, JSON_UNESCAPED_SLASHES);
    foreach (sv_follower_inboxes($pdo) as $inbox) {
        sv_queue_delivery($pdo, $inbox, $json);
    }

    // Deliver to the MENTIONED commenter's own inbox too — they may not be a
    // follower, and without this a reply to their comment never reaches them.
    foreach (($note['tag'] ?? []) as $t) {
        if (($t['type'] ?? '') === 'Mention' && !empty($t['href'])) {
            $adoc  = sv_fetch_ap((string)$t['href']);
            $inbox = is_array($adoc) ? (string)($adoc['inbox'] ?? '') : '';
            if ($inbox !== '') sv_queue_delivery($pdo, $inbox, $json);
        }
    }
}

/** Combined like tally for a target: native snap_likes + federated snap_ap_likes. */
function sv_combined_like_count(PDO $pdo, string $target_type, int $target_id, int $native): int {
    $ap = 0;
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM snap_ap_likes WHERE target_type = ? AND target_id = ?");
        $s->execute([$target_type, $target_id]);
        $ap = (int)$s->fetchColumn();
    } catch (Exception $e) { /* table may lag */ }
    return $native + $ap;
}

/** Federated-only like count for a target (for a "+N fediverse" breakout). */
function sv_fedi_like_count(PDO $pdo, string $target_type, int $target_id): int {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM snap_ap_likes WHERE target_type = ? AND target_id = ?");
        $s->execute([$target_type, $target_id]);
        return (int)$s->fetchColumn();
    } catch (Exception $e) { return 0; }
}

/** Wrap a Note in its Create activity. */
function sv_create_for_note(array $note, array $settings): array {
    return [
        '@context'  => 'https://www.w3.org/ns/activitystreams',
        'id'        => $note['id'] . '#create',
        'type'      => 'Create',
        'actor'     => sv_actor_url($settings),
        'published' => $note['published'],
        'to'        => $note['to'],
        'cc'        => $note['cc'],
        'object'    => $note,
    ];
}

/** Wrap a Note in an Update activity — replaces the remote's cached copy IN
 *  PLACE (same Note id, keeping permalinks/likes/replies). Update is how the
 *  fediverse propagates edits: unlike a Delete it leaves no tombstone, and
 *  unlike a re-Create it is not deduped away. Stamps `updated` on both the
 *  activity and the Note so Mastodon/Pixelfed treat it as a genuine edit and
 *  re-render — an Update whose object carries no newer `updated` than the
 *  cached copy is ignored. Fresh unique activity id so it is never deduped. */
function sv_update_for_note(array $note, array $settings): array {
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $note['updated'] = $now;
    return [
        '@context'  => 'https://www.w3.org/ns/activitystreams',
        'id'        => $note['id'] . '#update-' . bin2hex(random_bytes(6)),
        'type'      => 'Update',
        'actor'     => sv_actor_url($settings),
        'published' => $note['published'],
        'updated'   => $now,
        'to'        => $note['to'],
        'cc'        => $note['cc'],
        'object'    => $note,
    ];
}

/** Delete activity for a Note id — tells remote servers to drop their cached
 *  copy (Tombstone object, Mastodon convention). Unique fragment id so a
 *  re-issued Delete is never deduped away. */
function sv_delete_for_note_id(string $note_id, array $settings): array {
    return [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id'       => $note_id . '#delete-' . bin2hex(random_bytes(6)),
        'type'     => 'Delete',
        'actor'    => sv_actor_url($settings),
        'to'       => ['https://www.w3.org/ns/activitystreams#Public'],
        'object'   => ['id' => $note_id, 'type' => 'Tombstone'],
    ];
}

/**
 * RESYNC: re-federate the N most recent posts to all active followers.
 * Remote servers cache statuses by Note id and DEDUP re-delivered Creates,
 * so a changed render (new bakes, slice covers, fixed attachments, the full
 * carousel stack under a cover) never reaches a server that already ingested
 * the old copy.
 *
 * We push a signed Update per Note — the standard fediverse edit path. Update
 * carries the CURRENT complete Note (cover leading + every stack attachment)
 * and replaces the remote's cached copy IN PLACE, keeping the same Note id so
 * permalinks, likes and replies survive. This deliberately replaces the old
 * Delete→settle→re-Create dance: a Delete tombstones the Note id permanently,
 * so the follow-up Create for that same id was silently dropped by Pixelfed/
 * Mastodon and the posts vanished instead of refreshing.
 *
 * ENQUEUE ONLY — this just fills the delivery queue (oldest-first) and returns.
 * The caller drains at MEASURED CADENCE from a detached context (see
 * sv_process_deliveries) so the Updates land on the remote one at a time, in
 * order, with no burst to shuffle timestamps or truncate carousel stacks. A
 * duplicate Update (e.g. the cron picking up a straggler the web tail already
 * sent) is harmless — an Update is idempotent, unlike a Create or a Delete.
 *
 * @return array [notes_resynced, update_deliveries_queued]
 */
function sv_resync_recent(PDO $pdo, array $settings, ?int $limit = null): array {
    if ($limit === null) $limit = (int)($settings['smackverse_backfill_count'] ?? 10);
    $creates = sv_recent_creates($pdo, $settings, $limit);
    $inboxes = sv_follower_inboxes($pdo);
    if (!$creates || !$inboxes) return [0, 0];

    // One Update per Note (the Note rides inside each Create we already built,
    // so we unwrap it and re-wrap as an Update — same id, freshly stamped).
    $n = 0;
    foreach ($creates as $cjson) {
        $c    = json_decode($cjson, true);
        $note = $c['object'] ?? null;
        if (!is_array($note) || empty($note['id'])) continue;
        $upd = json_encode(sv_update_for_note($note, $settings), JSON_UNESCAPED_SLASHES);
        foreach ($inboxes as $ib) { sv_queue_delivery($pdo, $ib, $upd); $n++; }
    }
    return [count($creates), $n];
}

/**
 * Outbox — a FULLY CRAWLABLE, paginated OrderedCollection (the "load existing
 * inventory" surface a remote server pulls to backfill a profile's whole back
 * catalogue, not just the newest page).
 *
 *   $page < 1  → the shell: OrderedCollection with totalItems + first + last.
 *   $page >= 1 → an OrderedCollectionPage: 20 items newest-first, chained with
 *                next (older) / prev (newer) so a puller can walk the entire
 *                history. A crawler follows `first`, then `next` until it runs
 *                out — Mastodon does this natively; Pixelfed's on-follow
 *                importer walks `next` too. Without these links a puller only
 *                ever sees the latest page, which was our old dead-end.
 *
 * Content units mirror the site's public streams: standalone images (post_id
 * NULL) each as one Note; grouped posts as ONE multi-attachment Note (Pixelfed
 * carousel shape). The window is produced by a single UNION ordered + sliced in
 * SQL, so only the page's own Notes are ever built regardless of library size.
 */
function sv_outbox_doc(PDO $pdo, array $settings, int $page = 0): array {
    $outbox  = sv_outbox_url($settings);
    $perPage = 20;

    // Total published content units (standalone images + grouped posts).
    $total = 0;
    try {
        $total  = (int)$pdo->query(
            "SELECT COUNT(*) FROM snap_images
             WHERE img_status = 'published' AND img_date <= NOW() AND post_id IS NULL"
        )->fetchColumn();
        $total += (int)$pdo->query(
            "SELECT COUNT(*) FROM snap_posts
             WHERE status = 'published' AND created_at <= NOW()
               AND post_type IN ('single','carousel','panorama')"
        )->fetchColumn();
    } catch (Exception $e) { /* shell/page stay valid on error */ }

    $lastPage = max(1, (int)ceil($total / $perPage));

    // Shell — advertise first AND last so the collection is walkable both ways.
    if ($page < 1) {
        return [
            '@context'   => 'https://www.w3.org/ns/activitystreams',
            'id'         => $outbox,
            'type'       => 'OrderedCollection',
            'totalItems' => $total,
            'first'      => $outbox . '?page=1',
            'last'       => $outbox . '?page=' . $lastPage,
        ];
    }

    if ($page > $lastPage) $page = $lastPage;
    $offset = ($page - 1) * $perPage;

    // One merged, globally-ordered window (newest first). The DB sorts + slices,
    // so pages never overlap or skip; deterministic tie-break on kind + id keeps
    // pagination stable when timestamps collide.
    $index = [];
    try {
        $stmt = $pdo->prepare(
            "SELECT id, d, kind FROM (
                SELECT id, img_date AS d, 'image' AS kind FROM snap_images
                  WHERE img_status = 'published' AND img_date <= NOW() AND post_id IS NULL
                UNION ALL
                SELECT id, created_at AS d, 'post' AS kind FROM snap_posts
                  WHERE status = 'published' AND created_at <= NOW()
                    AND post_type IN ('single','carousel','panorama')
             ) u
             ORDER BY d DESC, kind ASC, id DESC
             LIMIT " . (int)$perPage . " OFFSET " . (int)$offset
        );
        $stmt->execute();
        $index = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* empty page */ }

    // Bulk-fetch the full rows for just this window, keyed by id per kind.
    $imgIds  = [];
    $postIds = [];
    foreach ($index as $u) {
        if ($u['kind'] === 'post') $postIds[] = (int)$u['id'];
        else                       $imgIds[]  = (int)$u['id'];
    }
    $imgRows = [];
    $postRows = [];
    try {
        if ($imgIds) {
            $in = implode(',', array_map('intval', $imgIds));
            foreach ($pdo->query("SELECT * FROM snap_images WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $imgRows[(int)$r['id']] = $r;
            }
        }
        if ($postIds) {
            $in = implode(',', array_map('intval', $postIds));
            foreach ($pdo->query("SELECT * FROM snap_posts WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $postRows[(int)$r['id']] = $r;
            }
        }
    } catch (Exception $e) { /* partial page rather than a hard fail */ }

    $items = [];
    foreach ($index as $u) {
        $id = (int)$u['id'];
        if ($u['kind'] === 'post') {
            $row  = $postRows[$id] ?? null;
            $note = $row ? sv_note_for_post($pdo, $row, $settings) : null;
        } else {
            $row  = $imgRows[$id] ?? null;
            $note = $row ? sv_note_for_image($pdo, $row, $settings) : null;
        }
        if ($note !== null) $items[] = sv_create_for_note($note, $settings);
    }

    $doc = [
        '@context'     => 'https://www.w3.org/ns/activitystreams',
        'id'           => $outbox . '?page=' . $page,
        'type'         => 'OrderedCollectionPage',
        'partOf'       => $outbox,
        'orderedItems' => $items,
    ];
    if ($page < $lastPage) $doc['next'] = $outbox . '?page=' . ($page + 1);
    if ($page > 1)         $doc['prev'] = $outbox . '?page=' . ($page - 1);
    return $doc;
}

/**
 * Build Create-activity JSON for the N most-recent content units (standalone
 * images + grouped posts merged, newest first, then reversed to oldest-first
 * for tidy delivery, with trigram trios re-reversed to slot order 3→2→1 —
 * see sv_reverse_trigram_group_units). Used for the backfill sent to a NEW
 * follower and by RESYNC. Reuses the same Note builders as the sweep, so
 * trigram/carousel shaping is identical.
 */
function sv_recent_creates(PDO $pdo, array $settings, int $limit = 10): array {
    if ($limit < 1) return [];
    $units = [];
    try {
        $imgs = $pdo->query(
            "SELECT * FROM snap_images
             WHERE img_status = 'published' AND img_date <= NOW() AND post_id IS NULL
             ORDER BY img_date DESC LIMIT " . (int)$limit
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($imgs as $img) $units[] = ['date' => $img['img_date'], 'kind' => 'image', 'row' => $img];

        $posts = $pdo->query(
            "SELECT * FROM snap_posts
             WHERE status = 'published' AND created_at <= NOW()
               AND post_type IN ('single','carousel','panorama')
             ORDER BY created_at DESC LIMIT " . (int)$limit
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($posts as $p) $units[] = ['date' => $p['created_at'], 'kind' => 'post', 'row' => $p];
    } catch (Exception $e) {
        return [];
    }
    usort($units, fn($a, $b) => strcmp($b['date'], $a['date']));
    $units = array_slice($units, 0, $limit);

    // Never backfill a PARTIAL trigram — a trio is a 3-across banner and 1-2 of
    // 3 looks broken. Drop any trigram whose full trio isn't inside the window,
    // so an all-trigram blog lands on a multiple of 3 (a limit of 10 trims to 9;
    // set smackverse_backfill_count to 12 to send four trios). Non-trigram posts
    // and standalone images are unaffected.
    $trio_counts = [];
    foreach ($units as $u) {
        if ($u['kind'] === 'post' && (int)($u['row']['trigram_id'] ?? 0) > 0) {
            $tg = (int)$u['row']['trigram_id'];
            $trio_counts[$tg] = ($trio_counts[$tg] ?? 0) + 1;
        }
    }
    $units = array_values(array_filter($units, function ($u) use ($trio_counts) {
        if ($u['kind'] !== 'post') return true;
        $tg = (int)($u['row']['trigram_id'] ?? 0);
        if ($tg === 0) return true;
        return ($trio_counts[$tg] ?? 0) >= 3;   // keep only complete trios
    }));

    $units = array_reverse($units);  // oldest-first for tidy delivery
    $units = sv_reverse_trigram_group_units($units);  // then fix trio slot order

    $out = [];
    foreach ($units as $u) {
        $note = ($u['kind'] === 'post')
            ? sv_note_for_post($pdo, $u['row'], $settings)
            : sv_note_for_image($pdo, $u['row'], $settings);
        if ($note !== null) $out[] = json_encode(sv_create_for_note($note, $settings), JSON_UNESCAPED_SLASHES);
    }
    return $out;
}

// ─── Publish sweep (PULL model — zero posting-flow edits) ────────────────────

/**
 * Reverse each consecutive run of posts sharing a trigram_id so the trio
 * DELIVERS in slot order 3→2→1. Remote timelines are reverse-chronological
 * (newest on top / leftmost), so arriving 3,2,1 makes followers read
 * L→M→R (1,2,3) top-down — the same left-to-right the blog banner reads.
 */
function sv_reverse_trigram_groups(array $rows): array {
    $out = []; $buf = []; $tg = 0;
    $flush = function () use (&$out, &$buf) {
        if ($buf) { $out = array_merge($out, array_reverse($buf)); $buf = []; }
    };
    foreach ($rows as $r) {
        $rtg = (int)($r['trigram_id'] ?? 0);
        if ($rtg !== 0 && $rtg === $tg) { $buf[] = $r; continue; }
        $flush();
        if ($rtg !== 0) { $tg = $rtg; $buf[] = $r; }
        else            { $tg = 0;    $out[] = $r; }
    }
    $flush();
    return $out;
}

/**
 * Same consecutive-run reversal as sv_reverse_trigram_groups, but for the
 * {date,kind,row} unit wrapper sv_recent_creates uses (standalone images and
 * grouped posts interleaved). An image unit — or a post with trigram_id 0 —
 * breaks a run, same rule as the sweep's post-only version. Without this,
 * backfill-on-follow and RESYNC deliver trigram trios in whatever order
 * created_at happened to sort them, which only reads L→M→R on the remote
 * grid by coincidence; the sweep path has always applied this, backfill/
 * resync never did.
 */
function sv_reverse_trigram_group_units(array $units): array {
    $out = []; $buf = []; $tg = 0;
    $flush = function () use (&$out, &$buf) {
        if ($buf) { $out = array_merge($out, array_reverse($buf)); $buf = []; }
    };
    foreach ($units as $u) {
        $rtg = ($u['kind'] === 'post') ? (int)($u['row']['trigram_id'] ?? 0) : 0;
        if ($rtg !== 0 && $rtg === $tg) { $buf[] = $u; continue; }
        $flush();
        if ($rtg !== 0) { $tg = $rtg; $buf[] = $u; }
        else            { $tg = 0;    $out[] = $u; }
    }
    $flush();
    return $out;
}

/**
 * Federate content published since the last sweep. Two independent
 * streams, each with its own datetime marker (no item can be skipped by
 * the per-sweep cap — the next cron resumes where this one stopped):
 *   - standalone images (post_id NULL)  → one Note each
 *     marker: smackverse_last_img_federated_at
 *   - grouped posts (single/carousel/panorama) → ONE multi-attachment
 *     Note per post (Pixelfed carousel shape)
 *     marker: smackverse_last_post_federated_at
 * First run initialises both markers to NOW and federates NOTHING — an
 * existing library (e.g. 10k Flickr imports) is never blasted at
 * followers. Scheduled (future-dated) content federates once its date
 * arrives. Caps at 20 units per stream per sweep. Returns [units, queued].
 */
function sv_sweep_new_posts(PDO $pdo, array &$settings): array {
    $now = date('Y-m-d H:i:s');
    $img_marker  = trim($settings['smackverse_last_img_federated_at'] ?? '');
    $post_marker = trim($settings['smackverse_last_post_federated_at'] ?? '');
    if ($img_marker === '' || $post_marker === '') {
        if ($img_marker === '')  sv_set_setting($pdo, $settings, 'smackverse_last_img_federated_at', $now);
        if ($post_marker === '') sv_set_setting($pdo, $settings, 'smackverse_last_post_federated_at', $now);
        sv_set_setting($pdo, $settings, 'smackverse_last_img_federated_id', '0');
        sv_set_setting($pdo, $settings, 'smackverse_last_post_federated_id', '0');
        return [0, 0];
    }
    // Markers are (datetime, id) PAIRS: the id tie-break means same-second
    // content is never skipped when a sweep stops mid-second (e.g. batch
    // posts sharing a timestamp, or a deferred trigram trio).
    $img_marker_id  = (int)($settings['smackverse_last_img_federated_id']  ?? 0);
    $post_marker_id = (int)($settings['smackverse_last_post_federated_id'] ?? 0);

    $inboxes = sv_follower_inboxes($pdo);
    $units = 0; $queued = 0;

    $fanout = function (?array $note) use ($pdo, $settings, $inboxes, &$queued) {
        if ($note === null || !$inboxes) return;
        $create = json_encode(sv_create_for_note($note, $settings), JSON_UNESCAPED_SLASHES);
        foreach ($inboxes as $inbox) {
            sv_queue_delivery($pdo, $inbox, $create);
            $queued++;
        }
    };

    // Stream 1: standalone images.
    $stmt = $pdo->prepare(
        "SELECT * FROM snap_images
         WHERE img_status = 'published' AND post_id IS NULL
           AND (img_date > ? OR (img_date = ? AND id > ?)) AND img_date <= ?
         ORDER BY img_date ASC, id ASC LIMIT 20"
    );
    $stmt->execute([$img_marker, $img_marker, $img_marker_id, $now]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $img) {
        $fanout(sv_note_for_image($pdo, $img, $settings));
        $units++;
    }
    if ($rows) {
        $last = $rows[count($rows) - 1];
        sv_set_setting($pdo, $settings, 'smackverse_last_img_federated_at', $last['img_date']);
        sv_set_setting($pdo, $settings, 'smackverse_last_img_federated_id', (string)(int)$last['id']);
    }

    // Stream 2: grouped posts — one Note per post, all images attached.
    // Trigram trios are reversed so they DELIVER 3→2→1: remote
    // reverse-chronological timelines then read L→M→R, matching the banner.
    $stmt = $pdo->prepare(
        "SELECT * FROM snap_posts
         WHERE status = 'published' AND post_type IN ('single','carousel','panorama')
           AND (created_at > ? OR (created_at = ? AND id > ?)) AND created_at <= ?
         ORDER BY created_at ASC, id ASC LIMIT 21"
    );
    $stmt->execute([$post_marker, $post_marker, $post_marker_id, $now]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Never split a trigram trio across sweeps: if the window ends mid-trio,
    // defer the partial trio to the next run (LIMIT 21 gives look-ahead; the
    // id tie-break marker guarantees the deferred rows are picked up next).
    while (count($rows) > 1) {
        $last = $rows[count($rows) - 1];
        $tg   = (int)($last['trigram_id'] ?? 0);
        if ($tg === 0) break;
        $trio = array_filter($rows, function ($r) use ($tg) { return (int)($r['trigram_id'] ?? 0) === $tg; });
        if (count($trio) >= 3 && count($rows) <= 20) break;  // trio complete and inside the cap
        array_pop($rows);                                    // defer the partial trio
    }
    $rows = array_slice($rows, 0, 20);
    foreach (sv_reverse_trigram_groups($rows) as $post) {
        $fanout(sv_note_for_post($pdo, $post, $settings));
        $units++;
    }
    if ($rows) {
        $last = $rows[count($rows) - 1];   // last KEPT row in ASC order
        sv_set_setting($pdo, $settings, 'smackverse_last_post_federated_at', $last['created_at']);
        sv_set_setting($pdo, $settings, 'smackverse_last_post_federated_id', (string)(int)$last['id']);
    }

    return [$units, $queued];
}
// ===== SNAPSMACK EOF =====
