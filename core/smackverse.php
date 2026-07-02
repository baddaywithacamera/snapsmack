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

function sv_actor_url(array $settings): string     { return sv_base($settings) . 'smackverse.php?ap=actor'; }
function sv_inbox_url(array $settings): string     { return sv_base($settings) . 'smackverse.php?ap=inbox'; }
function sv_outbox_url(array $settings): string    { return sv_base($settings) . 'smackverse.php?ap=outbox'; }
function sv_followers_url(array $settings): string { return sv_base($settings) . 'smackverse.php?ap=followers'; }
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
 * True when a URL is safe to contact: http(s), resolvable, and NOT a
 * private/reserved/loopback address. Blocks redirect-free SSRF; redirects
 * are disabled on every outbound request so this check holds.
 */
function sv_url_is_public(string $url): bool {
    $p = parse_url($url);
    if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true)) return false;
    $host = $p['host'] ?? '';
    if ($host === '') return false;
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if ($ip === $host && !filter_var($ip, FILTER_VALIDATE_IP)) return false; // did not resolve
    return (bool)filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/**
 * GET a remote ActivityPub document (actor, mostly). No redirects, 8s
 * timeout, 512KB cap. Returns decoded array or null.
 */
function sv_fetch_ap(string $url): ?array {
    if (!function_exists('curl_init')) return null;
    if (!sv_url_is_public($url)) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
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
    $sig_header = sv_request_header('signature');
    if ($sig_header === '') return null;
    $sig = sv_parse_signature_header($sig_header);
    if (empty($sig['keyid']) || empty($sig['signature']) || empty($sig['headers'])) return null;

    // Digest: required for POSTs; must match the raw body.
    $digest_hdr = sv_request_header('digest');
    if ($digest_hdr === '') return null;
    $expected = 'SHA-256=' . base64_encode(hash('sha256', $raw_body, true));
    if (!hash_equals($expected, trim($digest_hdr))) return null;

    // Date: within ±1 hour.
    $date_hdr = sv_request_header('date');
    if ($date_hdr === '') return null;
    $ts = strtotime($date_hdr);
    if ($ts === false || abs(time() - $ts) > 3600) return null;

    // Rebuild the signing string from the signed-header list.
    $signed_names = preg_split('/\s+/', strtolower(trim($sig['headers'])));
    if (!in_array('(request-target)', $signed_names, true)
        || !in_array('date', $signed_names, true)
        || !in_array('digest', $signed_names, true)) {
        return null; // refuse weak signatures
    }
    $lines = [];
    foreach ($signed_names as $name) {
        if ($name === '(request-target)') {
            $target = strtolower($_SERVER['REQUEST_METHOD'] ?? 'post') . ' ' . ($_SERVER['REQUEST_URI'] ?? '/');
            $lines[] = '(request-target): ' . $target;
        } elseif ($name === 'host') {
            $lines[] = 'host: ' . ($_SERVER['HTTP_HOST'] ?? '');
        } else {
            $lines[] = $name . ': ' . sv_request_header($name);
        }
    }
    $signing_string = implode("\n", $lines);

    // Fetch the key owner's actor document (keyId minus fragment).
    $actor_url = preg_replace('/#.*$/', '', $sig['keyid']);
    $actor = sv_fetch_ap($actor_url);
    if (!$actor) return null;
    $pem = $actor['publicKey']['publicKeyPem'] ?? '';
    if ($pem === '') return null;
    // The key must belong to the actor document that serves it.
    $owner = $actor['publicKey']['owner'] ?? ($actor['id'] ?? '');
    if ($owner !== ($actor['id'] ?? '')) return null;

    $pubkey = openssl_pkey_get_public($pem);
    if ($pubkey === false) return null;
    $ok = openssl_verify($signing_string, base64_decode($sig['signature']), $pubkey, OPENSSL_ALGO_SHA256);
    return $ok === 1 ? $actor : null;
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
    if (!sv_url_is_public($inbox_url))  return [false, 'inbox url not public'];
    $headers = sv_signed_headers($settings, $inbox_url, $activity_json);
    if ($headers === null) return [false, 'no signing key'];

    $ch = curl_init($inbox_url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $activity_json,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
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
 */
function sv_process_deliveries(PDO $pdo, array $settings, int $limit = 30): array {
    $now  = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "SELECT * FROM snap_ap_deliveries
         WHERE status = 'queued' AND next_try_at <= ?
         ORDER BY id ASC LIMIT " . (int)$limit
    );
    $stmt->execute([$now]);
    $due = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sent = 0; $failed = 0;
    foreach ($due as $row) {
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

// ─── Inbox handling ──────────────────────────────────────────────────────────

/**
 * Handle a signature-VERIFIED inbox activity. $actor_doc is the remote
 * actor returned by sv_verify_signature(); its id MUST equal the
 * activity's actor (checked here). Returns an HTTP status code.
 */
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
            'id'       => sv_actor_url($settings) . '&accept=' . bin2hex(random_bytes(8)),
            'type'     => 'Accept',
            'actor'    => sv_actor_url($settings),
            'object'   => $activity,
        ];
        sv_queue_delivery($pdo, $inbox, json_encode($accept, JSON_UNESCAPED_SLASHES));
        return 202;
    }

    if ($type === 'Undo') {
        $obj = $activity['object'] ?? [];
        if (is_array($obj) && ($obj['type'] ?? '') === 'Follow') {
            $pdo->prepare("UPDATE snap_ap_followers SET is_active = 0 WHERE actor_url = ?")
                ->execute([$actor_id]);
        }
        return 202;
    }

    if ($type === 'Delete') {
        // A remote actor deleting itself — drop them from followers.
        $obj = is_array($activity['object'] ?? null)
            ? ($activity['object']['id'] ?? '') : ($activity['object'] ?? '');
        if ($obj === $actor_id) {
            $pdo->prepare("UPDATE snap_ap_followers SET is_active = 0 WHERE actor_url = ?")
                ->execute([$actor_id]);
        }
        return 202;
    }

    // Everything else (Like, Announce, Create replies, …): acknowledged,
    // not yet stored. v0.3 surfaces boosts/likes into stats.
    return 202;
}

// ─── Documents: webfinger / actor / outbox / followers ──────────────────────

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
        'summary'           => nl2br(htmlspecialchars(trim($settings['site_description'] ?? ''))),
        'url'               => rtrim(sv_base($settings), '/'),
        'inbox'             => sv_inbox_url($settings),
        'outbox'            => sv_outbox_url($settings),
        'followers'         => sv_followers_url($settings),
        'endpoints'         => ['sharedInbox' => sv_inbox_url($settings)],
        'manuallyApprovesFollowers' => false,
        'discoverable'      => true,
    ];

    $avatar = trim($settings['skin_avatar'] ?? '');
    if ($avatar !== '') {
        $doc['icon'] = [
            'type'      => 'Image',
            'mediaType' => 'image/jpeg',
            'url'       => sv_base($settings) . ltrim($avatar, '/'),
        ];
    }

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

// ─── Notes (one published snap_image = one Note, mirroring rss.php) ─────────

/** Media type from an image file extension. */
function sv_media_type(string $file): string {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp'];
    return $map[$ext] ?? 'image/jpeg';
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
        "SELECT i.*, pi.sort_position, pi.is_cover
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
    $note_id   = $base . 'smackverse.php?ap=note&id=' . (int)$img['id'];

    $title = trim($img['img_title'] ?? '');
    $desc  = trim($img['img_description'] ?? '');
    $content = '<p><a href="' . htmlspecialchars($permalink) . '">'
             . htmlspecialchars($title !== '' ? $title : 'Untitled') . '</a></p>';
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
        'attachment'   => [[
            'type'      => 'Document',
            'mediaType' => sv_media_type($img['img_file']),
            'url'       => $base . ltrim($img['img_file'], '/'),
            'name'      => $title !== '' ? $title : $desc,
        ]],
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
    $note_id   = $base . 'smackverse.php?ap=note&post=' . (int)$post['id'];

    $title = trim($post['title'] ?? '');
    $desc  = trim($post['description'] ?? '');
    $content = '<p><a href="' . htmlspecialchars($permalink) . '">'
             . htmlspecialchars($title !== '' ? $title : 'Untitled') . '</a></p>';
    if ($desc !== '') {
        $content .= '<p>' . nl2br(htmlspecialchars($desc)) . '</p>';
    }

    // Trigram slots federate INDIVIDUALLY (Sean's call, 2026-07-02): the blog
    // keeps the 3-across banner presentation; Pixelfed gets each slot as a
    // normal post. For a trigram CAROUSEL the slice-cover is blog-side
    // wayfinding, not content — drop it from attachments so the remote post
    // leads with real photos. (A trigram SINGLE's only image IS its slice; it
    // federates as-is.) The permalink above stays the slice-cover URL — that
    // is the tile the blog actually links.
    if (!empty($post['trigram_id']) && count($images) > 1) {
        $images = array_values(array_filter($images, function ($im) { return empty($im['is_cover']); }));
        if (!$images) return null;
    }

    // Attachments: every member image, carousel order, alt text per image.
    // Hard cap 10 — Pixelfed's carousel ceiling. The composer and editor both
    // enforce 10 total now, but the editor allowed 20 before 0.7.341, so any
    // legacy oversized carousel federates its first ten rather than bouncing.
    $images = array_slice($images, 0, 10);
    $attachments = [];
    foreach ($images as $im) {
        $alt = trim($im['img_title'] ?? '');
        if ($alt === '') $alt = trim($im['img_description'] ?? '');
        $attachments[] = [
            'type'      => 'Document',
            'mediaType' => sv_media_type($im['img_file']),
            'url'       => $base . ltrim($im['img_file'], '/'),
            'name'      => $alt,
        ];
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

/**
 * Outbox: OrderedCollection shell; ?page=1 returns the 20 newest Notes.
 * Content units mirror snapsmack_content_counts(): standalone images
 * (post_id NULL) each as one Note; grouped posts as ONE multi-attachment
 * Note (Pixelfed carousel shape).
 */
function sv_outbox_doc(PDO $pdo, array $settings, bool $page): array {
    $outbox = sv_outbox_url($settings);
    if (!$page) {
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
        } catch (Exception $e) { /* shell stays valid */ }
        return [
            '@context'   => 'https://www.w3.org/ns/activitystreams',
            'id'         => $outbox,
            'type'       => 'OrderedCollection',
            'totalItems' => $total,
            'first'      => $outbox . '&page=1',
        ];
    }

    // Merge the two streams, newest first, 20 items.
    $units = [];
    try {
        $imgs = $pdo->query(
            "SELECT * FROM snap_images
             WHERE img_status = 'published' AND img_date <= NOW() AND post_id IS NULL
             ORDER BY img_date DESC LIMIT 20"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($imgs as $img) $units[] = ['date' => $img['img_date'], 'kind' => 'image', 'row' => $img];

        $posts = $pdo->query(
            "SELECT * FROM snap_posts
             WHERE status = 'published' AND created_at <= NOW()
               AND post_type IN ('single','carousel','panorama')
             ORDER BY created_at DESC LIMIT 20"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($posts as $p) $units[] = ['date' => $p['created_at'], 'kind' => 'post', 'row' => $p];
    } catch (Exception $e) { /* empty page */ }

    usort($units, function ($a, $b) { return strcmp($b['date'], $a['date']); });
    $units = array_slice($units, 0, 20);

    $items = [];
    foreach ($units as $u) {
        $note = ($u['kind'] === 'post')
            ? sv_note_for_post($pdo, $u['row'], $settings)
            : sv_note_for_image($pdo, $u['row'], $settings);
        if ($note !== null) $items[] = sv_create_for_note($note, $settings);
    }
    return [
        '@context'     => 'https://www.w3.org/ns/activitystreams',
        'id'           => $outbox . '&page=1',
        'type'         => 'OrderedCollectionPage',
        'partOf'       => $outbox,
        'orderedItems' => $items,
    ];
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
