<?php
/**
 * SNAPSMACK - TAKE YOUR SHIT WITH YOU (TYSWY) portable-export API
 *
 * Read-only, restartable, streaming export surface for the TYSWY desktop tool.
 * Spec: _spec/take-your-shit-with-you-spec-v0_1.md
 *
 * THE ONE RULE (spec section 2): anything that can crater a server is moved OFF
 * the server. This endpoint performs bounded indexed reads and streams rows from
 * an unbuffered cursor. It MUST NOT build a ZIP/TAR/WXR, walk the media tree,
 * hash the media library, hold a request open while an archive is assembled, or
 * run any job/daemon/queue. The desktop does the archive work.
 *
 * There is NO write operation here, by design. Every action is a GET.
 *
 * Routes (via api.php?route=tyswy/...&action=...):
 *   GET tyswy/api?action=preflight            - identity, counts, watermarks
 *   GET tyswy/api?action=stream&type=&after_id=&limit=&snapshot=   - NDJSON rows
 *   GET tyswy/api?action=media&id=&variant=   - one authorized media file
 *   GET tyswy/api?action=record&type=&id=     - one record, for targeted retry
 *   GET tyswy/api?action=verify&snapshot=     - fresh counts/watermarks
 *   GET tyswy/api?action=changes&since=       - bounded changed/deleted ids
 *
 * WHY COLUMNS ARE ALLOWLISTED PER TYPE: every record type below names the exact
 * columns it exports. No SELECT *. This is the property that keeps the export
 * honest over time - adding a column to snap_images or snap_settings can never
 * silently start exporting it, and a sensitive column added later is excluded by
 * default rather than by remembering to exclude it. Spec section 9 lists what
 * must never leave: password hashes, TOTP secrets, API keys, signing keys,
 * session/CSRF data, IP-ban material, raw IPs and security fingerprints.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/client-ip.php';          // SECAUDIT 035 boundary
require_once __DIR__ . '/api-input-safety.php';   // SECAUDIT 040 path containment

const TYSWY_API_VERSION   = '0.1';
const TYSWY_STREAM_FORMAT = 1;        // canonical serialization version
const TYSWY_CHUNK_DEFAULT = 500;
const TYSWY_CHUNK_MAX     = 2000;

// ---------------------------------------------------------------------------
// Request id - correlates a client-side failure with a server log line without
// exposing anything about the server.
// ---------------------------------------------------------------------------
$tyswy_request_id = bin2hex(random_bytes(8));

// ---------------------------------------------------------------------------
// Error contract (spec 6.7). Never leak SQL, paths, or config.
// ---------------------------------------------------------------------------
function tyswy_error(int $http, string $code, string $message, bool $retryable = false): void {
    global $tyswy_request_id;
    if (!headers_sent()) {
        http_response_code($http);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'ok'    => false,
        'error' => ['code' => $code, 'message' => $message, 'retryable' => $retryable],
        'request_id' => $tyswy_request_id,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

function tyswy_envelope(PDO $pdo): array {
    global $tyswy_request_id;
    return [
        'ok'           => true,
        'api_version'  => TYSWY_API_VERSION,
        'site_uuid'    => tyswy_site_uuid($pdo),
        'site_mode'    => tyswy_setting($pdo, 'site_mode', 'photoblog'),
        'site_version' => defined('SNAPSMACK_VERSION_SHORT') ? SNAPSMACK_VERSION_SHORT : 'unknown',
        'generated_at' => gmdate('c'),
        'request_id'   => $tyswy_request_id,
    ];
}

function tyswy_ok(PDO $pdo, array $data): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(tyswy_envelope($pdo), $data),
                     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------
function tyswy_setting(PDO $pdo, string $key, $default = null) {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = ? LIMIT 1");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        $cache[$key] = ($v === false) ? $default : $v;
    } catch (Throwable $e) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

/**
 * A stable identifier for THIS site, so a desktop archive can tell two exports
 * apart. Derived, never a secret: a hash of the site URL plus the install's
 * creation-ish anchor. Stored once in snap_settings if absent.
 */
function tyswy_site_uuid(PDO $pdo): string {
    $u = tyswy_setting($pdo, 'tyswy_site_uuid');
    if ($u) return $u;
    $seed = (string)tyswy_setting($pdo, 'site_url', '') . '|' . (string)tyswy_setting($pdo, 'site_name', '');
    $u = substr(hash('sha256', $seed . '|' . random_bytes(8)), 0, 32);
    try {
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('tyswy_site_uuid', ?)
                       ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")->execute([$u]);
    } catch (Throwable $e) { /* read-only DB or race - the derived value still works */ }
    return $u;
}

function tyswy_table_exists(PDO $pdo, string $table): bool {
    static $seen = [];
    if (isset($seen[$table])) return $seen[$table];
    try {
        $pdo->query("SELECT 1 FROM `{$table}` LIMIT 0");
        return $seen[$table] = true;
    } catch (Throwable $e) {
        return $seen[$table] = false;
    }
}

// ---------------------------------------------------------------------------
// Auth - scoped, read-only, HTTPS-enforced
// ---------------------------------------------------------------------------

/** HTTPS is mandatory except on loopback, which has no network path to attack. */
function tyswy_require_https(): void {
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443)
          || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($https) return;

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) return;

    tyswy_error(403, 'https_required',
        'This export API refuses plaintext HTTP. Your archive and your key would '
        . 'cross the network in the clear. Use https://.');
}

/**
 * Validate the Bearer key. Read-only and scoped to key_type 'tyswy' - a key
 * minted for any other tool must not reach this surface, and a tyswy key must
 * not reach theirs. Expiry enforced (the SECAUDIT 039 sweep rule).
 *
 * @return array{id:int,user_id:int}
 */
function tyswy_auth(PDO $pdo): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
        tyswy_error(401, 'missing_key', 'A TYSWY API key is required.');
    }
    $hash = hash('sha256', $m[1]);
    try {
        $st = $pdo->prepare("
            SELECT id, user_id FROM snap_ohsnap_keys
            WHERE key_hash = ? AND is_active = 1 AND key_type = 'tyswy'
              AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1");
        $st->execute([$hash]);
    } catch (Throwable $e) {
        $st = $pdo->prepare("
            SELECT id, user_id FROM snap_ohsnap_keys
            WHERE key_hash = ? AND is_active = 1 AND key_type = 'tyswy' LIMIT 1");
        $st->execute([$hash]);
    }
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        tyswy_error(403, 'bad_key', 'That key is not valid for portable export, or it has expired.');
    }
    try {
        $pdo->prepare("UPDATE snap_ohsnap_keys SET last_used_at = NOW() WHERE id = ?")
            ->execute([$row['id']]);
    } catch (Throwable $e) { /* last_used is telemetry, never block the export on it */ }
    return ['id' => (int)$row['id'], 'user_id' => (int)($row['user_id'] ?? 0)];
}

/**
 * Bounded per-key rate limit. Deliberately generous: a legitimate export of a
 * 10,000-image site is thousands of requests, and it must not look like an
 * attack merely for being thorough (spec section 5).
 */
function tyswy_rate_limit(PDO $pdo, int $key_id): void {
    try {
        $pdo->prepare(
            "INSERT INTO snap_rate_limits (ip, action, count, window_start)
             VALUES (?, 'tyswy_api', 1, NOW())
             ON DUPLICATE KEY UPDATE
               count        = IF(window_start < DATE_SUB(NOW(), INTERVAL 1 MINUTE), 1, count + 1),
               window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL 1 MINUTE), NOW(), window_start)"
        )->execute(['key:' . $key_id]);
        $st = $pdo->prepare(
            "SELECT count FROM snap_rate_limits
             WHERE ip = ? AND action = 'tyswy_api'
               AND window_start >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
        $st->execute(['key:' . $key_id]);
        if ((int)($st->fetchColumn() ?: 0) > 1200) {   // 20/sec sustained
            tyswy_error(429, 'rate_limited',
                'Slow down - too many requests in one minute.', true);
        }
    } catch (PDOException $e) { /* best-effort; never block a legitimate export */ }
}

// ---------------------------------------------------------------------------
// Record registry
//
// One entry per portable object type. `cols` is an ALLOWLIST - see the file
// header for why that matters. `map` renames/normalizes into the portable
// namespace so the archive does not hard-depend on SnapSmack column names.
// ---------------------------------------------------------------------------
function tyswy_types(): array {
    return [
        'posts' => [
            'table' => 'snap_posts', 'id' => 'id',
            'cols'  => ['id','title','slug','description','content','post_type','status',
                        'created_at','updated_at','allow_comments','allow_download','download_url',
                        'featured_image_id','trigram_id','sort_order','is_pinned',
                        'is_sensitive','content_warning','panorama_rows'],
        ],
        'images' => [
            'table' => 'snap_images', 'id' => 'id',
            'cols'  => ['id','img_title','img_slug','img_description','img_film','img_license',
                        'img_date','img_file','img_source_file','img_source_url','img_exif',
                        'img_width','img_height','img_status','img_orientation',
                        'img_thumb_square','img_thumb_aspect','img_checksum','img_display_options',
                        'post_id','sort_order','modified_at','blurhash',
                        'is_sensitive','content_warning','img_like_seed','img_view_seed'],
        ],
        'post_images' => [
            'table' => 'snap_post_images', 'id' => 'id',
            // Carousel order is sacred (spec 8, GRAMOFSMACK) - every positioning
            // and framing field rides along so the arrangement is reproducible.
            'cols'  => ['id','post_id','image_id','sort_position','is_cover','grid_col','grid_row',
                        'img_size_pct','img_border_px','img_border_color','img_bg_color',
                        'img_shadow','img_crop_mode','img_focus_x','img_focus_y','img_zoom'],
        ],
        'categories' => [
            'table' => 'snap_categories', 'id' => 'id',
            'cols'  => ['id','cat_name','cat_slug','cat_description','cover_image_id',
                        'show_in_archive','featured_post_id'],
        ],
        'image_category_memberships' => [
            'table' => 'snap_image_cat_map', 'id' => 'image_id',
            'cols'  => ['image_id','cat_id'],
            'composite' => true,   // no surrogate key; ordered by (image_id, cat_id)
            'order'     => 'image_id, cat_id',
        ],
        'albums' => [
            'table' => 'snap_albums', 'id' => 'id',
            'cols'  => ['id','album_name','album_description','cover_image_id',
                        'featured_post_id','view_count'],
        ],
        'image_album_memberships' => [
            'table' => 'snap_image_album_map', 'id' => 'image_id',
            'cols'  => ['image_id','album_id'],
            'composite' => true,
            'order'     => 'image_id, album_id',
        ],
        'collections' => [
            'table' => 'snap_collections', 'id' => 'id',
            'cols'  => ['id','title','slug','description','default_display','cover_image_id',
                        'sort_order','published','created_at','updated_at'],
        ],
        'collection_memberships' => [
            'table' => 'snap_collection_items', 'id' => 'id',
            'cols'  => ['id','collection_id','item_type','item_id','sort_order',
                        'added_at','image_id','position','caption'],
        ],
        'pages' => [
            'table' => 'snap_pages', 'id' => 'id',
            'cols'  => ['id','slug','title','content','image_asset','image_size','image_align',
                        'image_shadow','is_active','menu_order','created_at'],
        ],
        'comments' => [
            'table' => 'snap_community_comments', 'id' => 'id',
            // Deliberately WITHOUT ip and fp_hash: raw IPs and security
            // fingerprints are named exclusions in spec section 9. guest_email IS
            // included - it is part of the comment record and destination
            // adapters need it - and the manifest labels it as third-party
            // personal data so the boundary is stated rather than assumed.
            'cols'  => ['id','post_id','user_id','guest_name','guest_email','guest_url',
                        'comment_text','status','created_at','edited_at'],
        ],
        'reactions' => [
            'table' => 'snap_likes', 'id' => 'id',
            // guest_hash is excluded: it is a fingerprint of a third party, and
            // the portable fact is "this post has N reactions", not who.
            'cols'  => ['id','post_id','user_id','created_at'],
        ],
        'blogroll' => [
            'table' => 'snap_blogroll', 'id' => 'id',
            'cols'  => ['id','peer_name','peer_url','cat_id','peer_rss','peer_desc','sort_order'],
        ],
        'follows' => [
            'table' => 'snap_ap_followers', 'id' => 'id',
            // Reference only, never transferable identity (spec section 9). No
            // keys, no tokens - just who follows this actor.
            'cols'  => ['id'],
            'dynamic_cols' => true,   // schema varies by version; resolved at runtime
        ],
    ];
}

/** Columns that actually exist, intersected with the allowlist. */
function tyswy_live_cols(PDO $pdo, array $def): array {
    static $cache = [];
    $t = $def['table'];
    if (isset($cache[$t])) $have = $cache[$t];
    else {
        $have = [];
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM `{$t}`") as $r) {
                $have[strtolower($r['Field'])] = true;
            }
        } catch (Throwable $e) { $have = []; }
        $cache[$t] = $have;
    }
    if (!empty($def['dynamic_cols'])) {
        // Reference-only tables: take every non-secret-looking column.
        $deny = '/(key|secret|token|password|hash|private|salt|nonce|signature)/i';
        return array_values(array_filter(array_keys($have), fn($c) => !preg_match($deny, $c)));
    }
    return array_values(array_filter($def['cols'], fn($c) => isset($have[strtolower($c)])));
}

/** Canonical serialization: key-sorted JSON, so the hash is stable everywhere. */
function tyswy_canonical(array $row): string {
    ksort($row);
    return json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                           | JSON_PRESERVE_ZERO_FRACTION);
}

// ---------------------------------------------------------------------------
// Public settings allowlist
//
// snap_settings is a flat key/value bag holding SMTP passwords, API keys, hub
// secrets and signing material alongside the site title. It is therefore
// exported by EXPLICIT ALLOWLIST ONLY, with a denylist as a second gate. Never
// by pattern alone, and never wholesale.
// ---------------------------------------------------------------------------
function tyswy_public_setting_keys(): array {
    return [
        'site_name','site_url','site_tagline','site_description','site_mode',
        'site_author','site_email_public','copyright_notice','copyright_holder',
        'footer_text','about_text','timezone','date_format','posts_per_page',
        'active_skin','default_display','language','locale',
        'exif_display_enabled','downloads_enabled','download_link_required',
        'comments_enabled','comments_require_approval','blogroll_enabled',
    ];
}

function tyswy_settings_public(PDO $pdo): array {
    $out  = [];
    $deny = '/(key|secret|token|password|passwd|hash|salt|kek|private|smtp|credential|api|hub|totp|signature|license_code)/i';
    $allow = tyswy_public_setting_keys();
    $in    = implode(',', array_fill(0, count($allow), '?'));
    try {
        $st = $pdo->prepare("SELECT setting_key, setting_val FROM snap_settings WHERE setting_key IN ($in)");
        $st->execute($allow);
        foreach ($st as $r) {
            // Second gate: even an allowlisted name is dropped if it looks secret.
            if (preg_match($deny, $r['setting_key'])) continue;
            $out[$r['setting_key']] = $r['setting_val'];
        }
    } catch (Throwable $e) { /* fall through to whatever was collected */ }
    return $out;
}

// ---------------------------------------------------------------------------
// Counts and watermarks
// ---------------------------------------------------------------------------
function tyswy_watermark(PDO $pdo, string $type, array $def): array {
    if (!tyswy_table_exists($pdo, $def['table'])) {
        return ['supported' => false, 'count' => 0, 'max_id' => 0];
    }
    $idcol = $def['id'];
    try {
        $row = $pdo->query("SELECT COUNT(*) AS c, COALESCE(MAX(`{$idcol}`),0) AS m FROM `{$def['table']}`")
                   ->fetch(PDO::FETCH_ASSOC);
        return ['supported' => true, 'count' => (int)$row['c'], 'max_id' => (int)$row['m']];
    } catch (Throwable $e) {
        return ['supported' => false, 'count' => 0, 'max_id' => 0];
    }
}

// ===========================================================================
// GATE
// ===========================================================================

// The column allowlists above ARE a security control, so they get a regression
// test (tests/tyswy-export-regression.php). Defining this lets the test include
// the file for its definitions without dispatching a request. Production never
// defines it, so there is no behavioural difference.
if (defined('TYSWY_NO_DISPATCH')) return;

tyswy_require_https();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    // There is no write surface here and there never will be (spec section 2).
    tyswy_error(405, 'read_only', 'This API is read-only. Only GET is accepted.');
}

$tyswy_key = tyswy_auth($pdo);
tyswy_rate_limit($pdo, $tyswy_key['id']);

$action = (string)($_GET['action'] ?? 'preflight');

// ---------------------------------------------------------------------------
// 6.1 preflight
// ---------------------------------------------------------------------------
if ($action === 'preflight') {
    $types = [];
    foreach (tyswy_types() as $name => $def) {
        $types[$name] = tyswy_watermark($pdo, $name, $def);
    }

    // Approximate media bytes from STORED sizes only - never a filesystem walk.
    $media_bytes = null;
    try {
        $media_bytes = (int)$pdo->query(
            "SELECT COALESCE(SUM(img_width * img_height),0) FROM snap_images")->fetchColumn();
        $media_bytes = null;   // pixel area is not bytes; report unknown rather than lie
    } catch (Throwable $e) { $media_bytes = null; }

    tyswy_ok($pdo, [
        'site_name'      => tyswy_setting($pdo, 'site_name', ''),
        'site_url'       => tyswy_setting($pdo, 'site_url', ''),
        'stream_format'  => TYSWY_STREAM_FORMAT,
        'chunk_default'  => TYSWY_CHUNK_DEFAULT,
        'chunk_max'      => TYSWY_CHUNK_MAX,
        'types'          => $types,
        'media_variants' => ['original', 'web', 'thumb'],
        'media_bytes_estimate' => $media_bytes,   // null = not known without a walk
        'settings_public'      => tyswy_settings_public($pdo),
        'excluded_classes'     => [
            'password hashes', 'TOTP secrets and recovery codes', 'API keys and OAuth tokens',
            'ActivityPub private signing keys', 'database and SMTP credentials',
            'sessions and CSRF data', 'IP bans, raw IP addresses and security fingerprints',
            'internal queues, locks and caches', 'server filesystem paths',
        ],
        'included_sensitive_classes' => [
            'commenter email addresses (third-party personal data, needed for comment migration)',
            'image EXIF including GPS coordinates, preserved deliberately',
        ],
        'key_scope' => 'tyswy:read-only',
    ]);
}

// ---------------------------------------------------------------------------
// 6.2 stream - NDJSON from an unbuffered cursor
// ---------------------------------------------------------------------------
if ($action === 'stream') {
    $type = (string)($_GET['type'] ?? '');
    $defs = tyswy_types();
    if (!isset($defs[$type])) {
        tyswy_error(400, 'unknown_type', 'Unknown record type.');
    }
    $def      = $defs[$type];
    $after_id = max(0, (int)($_GET['after_id'] ?? 0));
    $limit    = (int)($_GET['limit'] ?? TYSWY_CHUNK_DEFAULT);
    $limit    = max(1, min($limit, TYSWY_CHUNK_MAX));
    $snapshot = (int)($_GET['snapshot'] ?? 0);

    header('Content-Type: application/x-ndjson');
    header('X-Accel-Buffering: no');       // don't let nginx buffer the stream
    header('Cache-Control: no-store');

    $wm = tyswy_watermark($pdo, $type, $def);
    if (!$wm['supported']) {
        // An absent optional feature is NOT an error (spec 6.2).
        echo json_encode(['record_type' => $type, 'supported' => false,
                          'stream_format' => TYSWY_STREAM_FORMAT]) . "\n";
        echo json_encode(['footer' => true, 'rows' => 0, 'last_id' => 0,
                          'has_more' => false, 'complete' => true]) . "\n";
        exit;
    }
    if ($snapshot <= 0) $snapshot = $wm['max_id'];

    $cols  = tyswy_live_cols($pdo, $def);
    if (!$cols) tyswy_error(500, 'no_columns', 'No exportable columns for this type.');
    $idcol = $def['id'];
    $order = $def['order'] ?? "`{$idcol}`";
    $sel   = implode(', ', array_map(fn($c) => "`{$c}`", $cols));

    echo json_encode([
        'record_type'    => $type,
        'supported'      => true,
        'snapshot'       => $snapshot,
        'after_id'       => $after_id,
        'source_count'   => $wm['count'],
        'source_max_id'  => $wm['max_id'],
        'stream_format'  => TYSWY_STREAM_FORMAT,
        'columns'        => $cols,
    ], JSON_UNESCAPED_SLASHES) . "\n";
    if (function_exists('ob_flush')) { @ob_flush(); } @flush();

    // Unbuffered cursor: rows are emitted as they arrive. PHP never holds the
    // chunk as an array - that is the whole point of section 2.
    $prev_buffered = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
    try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false); } catch (Throwable $e) {}

    $rows = 0; $last_id = $after_id; $rolling = hash_init('sha256'); $complete = false;
    try {
        $sql = "SELECT {$sel} FROM `{$def['table']}`
                WHERE `{$idcol}` > ? AND `{$idcol}` <= ?
                ORDER BY {$order} LIMIT {$limit}";
        $st = $pdo->prepare($sql);
        $st->execute([$after_id, $snapshot]);

        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $canon  = tyswy_canonical($row);
            $digest = hash('sha256', $canon);
            hash_update($rolling, $digest);
            echo json_encode(['id' => (int)$row[$idcol], 'sha256' => $digest, 'record' => $row],
                             JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            $last_id = (int)$row[$idcol];
            $rows++;
            if (($rows % 50) === 0) { if (function_exists('ob_flush')) { @ob_flush(); } @flush(); }
        }
        $st->closeCursor();
        $complete = true;
    } catch (Throwable $e) {
        // A truncated stream simply has no valid footer - the client rejects it.
        $complete = false;
    }
    try { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); } catch (Throwable $e) {}

    if ($complete) {
        echo json_encode([
            'footer'        => true,
            'rows'          => $rows,
            'last_id'       => $last_id,
            'has_more'      => ($last_id < $snapshot && $rows === $limit),
            'stream_sha256' => hash_final($rolling),
            'source_count'  => $wm['count'],
            'source_max_id' => $wm['max_id'],
            'complete'      => true,
        ], JSON_UNESCAPED_SLASHES) . "\n";
    }
    exit;
}

// ---------------------------------------------------------------------------
// 6.4 record - one normalized record, for targeted retry
// ---------------------------------------------------------------------------
if ($action === 'record') {
    $type = (string)($_GET['type'] ?? '');
    $defs = tyswy_types();
    if (!isset($defs[$type])) tyswy_error(400, 'unknown_type', 'Unknown record type.');
    $def = $defs[$type];
    $id  = (int)($_GET['id'] ?? 0);
    if ($id <= 0) tyswy_error(400, 'bad_id', 'A positive id is required.');
    if (!tyswy_table_exists($pdo, $def['table'])) tyswy_error(404, 'not_supported', 'Type not present on this site.');

    $cols = tyswy_live_cols($pdo, $def);
    $sel  = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
    $st   = $pdo->prepare("SELECT {$sel} FROM `{$def['table']}` WHERE `{$def['id']}` = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) tyswy_error(404, 'not_found', 'No such record.');

    $canon = tyswy_canonical($row);
    tyswy_ok($pdo, ['record_type' => $type, 'id' => $id,
                    'sha256' => hash('sha256', $canon), 'record' => $row]);
}

// ---------------------------------------------------------------------------
// 6.5 verify - fresh counts and watermarks, indexed only
// ---------------------------------------------------------------------------
if ($action === 'verify') {
    $snapshot = (int)($_GET['snapshot'] ?? 0);
    $types = [];
    foreach (tyswy_types() as $name => $def) {
        $wm = tyswy_watermark($pdo, $name, $def);
        if ($wm['supported'] && $snapshot > 0) {
            // How many rows existed at or below the snapshot boundary - the number
            // a completed export should have.
            try {
                $st = $pdo->prepare("SELECT COUNT(*) FROM `{$def['table']}` WHERE `{$def['id']}` <= ?");
                $st->execute([$snapshot]);
                $wm['count_at_snapshot'] = (int)$st->fetchColumn();
            } catch (Throwable $e) { $wm['count_at_snapshot'] = null; }
        }
        $types[$name] = $wm;
    }
    tyswy_ok($pdo, ['snapshot' => $snapshot, 'types' => $types]);
}

// ---------------------------------------------------------------------------
// 6.6 changes - bounded changed ids since a token
// ---------------------------------------------------------------------------
if ($action === 'changes') {
    $since = trim((string)($_GET['since'] ?? ''));
    $ts    = $since !== '' ? date('Y-m-d H:i:s', strtotime($since)) : null;
    $out   = [];

    // Only tables carrying a reliable modification column can answer cheaply.
    // Everything else is reported as needing a fresh bounded stream comparison,
    // rather than being silently assumed unchanged (spec 6.6).
    $mod = ['posts' => ['snap_posts','updated_at','id'],
            'images' => ['snap_images','modified_at','id'],
            'comments' => ['snap_community_comments','created_at','id'],
            'collections' => ['snap_collections','updated_at','id']];

    foreach (tyswy_types() as $name => $def) {
        if (!tyswy_table_exists($pdo, $def['table'])) { $out[$name] = ['supported' => false]; continue; }
        if (!isset($mod[$name]) || $ts === null) {
            $out[$name] = ['method' => 'restream', 'changed_ids' => null];
            continue;
        }
        [$tbl, $col, $idc] = $mod[$name];
        try {
            $st = $pdo->prepare("SELECT `{$idc}` FROM `{$tbl}` WHERE `{$col}` > ? ORDER BY `{$idc}` LIMIT 5000");
            $st->execute([$ts]);
            $out[$name] = ['method' => 'modified_since',
                           'changed_ids' => array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN))];
        } catch (Throwable $e) {
            $out[$name] = ['method' => 'restream', 'changed_ids' => null];
        }
    }
    tyswy_ok($pdo, ['since' => $since, 'types' => $out]);
}

// ---------------------------------------------------------------------------
// 6.3 media - stream one authorized file, Range-capable
// ---------------------------------------------------------------------------
if ($action === 'media') {
    $id      = (int)($_GET['id'] ?? 0);
    $variant = strtolower(trim((string)($_GET['variant'] ?? 'original')));
    if ($id <= 0) tyswy_error(400, 'bad_id', 'A positive image id is required.');
    if (!in_array($variant, ['original', 'web', 'thumb'], true)) {
        tyswy_error(400, 'bad_variant', 'Unknown media variant.');
    }

    // The path comes from the DATABASE, never from the request (spec 6.3).
    $st = $pdo->prepare("SELECT img_file, img_thumb_square, img_thumb_aspect
                         FROM snap_images WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $img = $st->fetch(PDO::FETCH_ASSOC);
    if (!$img) tyswy_error(404, 'not_found', 'No such image.');

    $rel = ($variant === 'thumb')
        ? (string)($img['img_thumb_aspect'] ?: $img['img_thumb_square'] ?: '')
        : (string)($img['img_file'] ?? '');
    if ($rel === '') tyswy_error(404, 'variant_absent', 'That variant does not exist for this image.');

    // Containment: the stored value is client-supplied on imported rows
    // (SECAUDIT 040 finding C), so it is re-checked here rather than trusted.
    if (!snap_api_safe_upload_path($rel)) {
        tyswy_error(403, 'path_refused', 'That media path is not exportable.');
    }
    $root = realpath(dirname(__DIR__));
    $abs  = realpath($root . '/' . $rel);
    if ($abs === false || strncmp($abs, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0
        || !is_file($abs)) {
        tyswy_error(404, 'file_missing', 'The file for that image is not on disk.');
    }

    $size = filesize($abs);
    $etag = '"' . substr(hash('sha256', $rel . '|' . $size . '|' . filemtime($abs)), 0, 32) . '"';
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        exit;
    }

    $start = 0; $end = $size - 1; $partial = false;
    if (!empty($_SERVER['HTTP_RANGE'])) {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)) {
            header('Content-Range: bytes */' . $size);
            tyswy_error(416, 'bad_range', 'Malformed Range header.');
        }
        if ($m[1] === '' && $m[2] === '') {
            header('Content-Range: bytes */' . $size);
            tyswy_error(416, 'bad_range', 'Malformed Range header.');
        }
        if ($m[1] === '') {                       // suffix range: last N bytes
            $start = max(0, $size - (int)$m[2]);
        } else {
            $start = (int)$m[1];
            if ($m[2] !== '') $end = (int)$m[2];
        }
        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            tyswy_error(416, 'bad_range', 'Requested range is outside the file.');
        }
        $end = min($end, $size - 1);
        $partial = true;
    }

    $length = $end - $start + 1;
    http_response_code($partial ? 206 : 200);
    header('Content-Type: ' . (function_exists('mime_content_type')
           ? (@mime_content_type($abs) ?: 'application/octet-stream') : 'application/octet-stream'));
    header('Content-Length: ' . $length);
    header('Accept-Ranges: bytes');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($abs)) . ' GMT');
    header('Content-Disposition: attachment; filename="' . basename($abs) . '"');
    if ($partial) header("Content-Range: bytes {$start}-{$end}/{$size}");

    // X-Sendfile / X-Accel-Redirect when configured - the server does the work.
    $offload = tyswy_setting($pdo, 'tyswy_sendfile_mode', '');
    if (!$partial && $offload === 'xsendfile') { header('X-Sendfile: ' . $abs); exit; }
    if (!$partial && $offload === 'xaccel')    { header('X-Accel-Redirect: /' . $rel); exit; }

    // Bounded streaming - the file is never read into memory whole.
    $fh = fopen($abs, 'rb');
    if (!$fh) tyswy_error(500, 'unreadable', 'Could not open that file.');
    if ($start > 0) fseek($fh, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fh)) {
        $buf = fread($fh, (int)min(262144, $remaining));
        if ($buf === false) break;
        echo $buf;
        $remaining -= strlen($buf);
        @flush();
    }
    fclose($fh);
    exit;
}

tyswy_error(400, 'unknown_action', 'Unknown action.');
// ===== SNAPSMACK EOF =====
