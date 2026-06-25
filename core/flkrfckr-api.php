<?php
/**
 * SNAPSMACK - FLKR FCKR Migration API
 *
 * Authenticated JSON API for the FLKR FCKR Flickr→SnapSmack migration
 * desktop tool. All requests require a Bearer token issued from
 * smack-api-keys.php (key_type = 'flkrfckr').
 *
 * Routes (via api.php?route=flkrfckr/...):
 *   GET    flkrfckr/albums        — list all albums
 *   POST   flkrfckr/albums        — create album if not exists (match on name)
 *   POST   flkrfckr/images        — create image record (FTP'd file already on server)
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/snap-tags.php';
require_once __DIR__ . '/thumb-generator.php';
require_once __DIR__ . '/totp.php';   // totp_verify() for the step-up authorize endpoint

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

function flkrfckr_ensure_key_type(PDO $pdo): void {
    try {
        $pdo->query("SELECT key_type FROM snap_ohsnap_keys LIMIT 0");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE snap_ohsnap_keys ADD COLUMN key_type VARCHAR(20) NOT NULL DEFAULT 'ohsnap' AFTER label");
    }
}

function flkrfckr_ensure_key_user(PDO $pdo): void {
    try {
        $pdo->query("SELECT user_id FROM snap_ohsnap_keys LIMIT 0");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE snap_ohsnap_keys ADD COLUMN user_id INT UNSIGNED DEFAULT NULL AFTER is_active");
    }
}

/**
 * Validate the Bearer key. Returns the key row ['id','user_id'] on success,
 * or false if the key is missing/invalid. NOTE: user_id may be NULL on a
 * legacy key issued before per-user attribution — callers that write content
 * MUST reject a NULL user_id (force the user to regenerate the key).
 *
 * @return array|false
 */
function flkrfckr_auth(PDO $pdo) {
    flkrfckr_ensure_key_type($pdo);
    flkrfckr_ensure_key_user($pdo);
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
        return false;
    }
    $hash = hash('sha256', $m[1]);
    $stmt = $pdo->prepare("
        SELECT id, user_id FROM snap_ohsnap_keys
        WHERE key_hash = ? AND is_active = 1 AND key_type = 'flkrfckr'
        LIMIT 1
    ");
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    $pdo->prepare("UPDATE snap_ohsnap_keys SET last_used_at = NOW() WHERE id = ?")
        ->execute([$row['id']]);
    return $row;
}

// ---------------------------------------------------------------------------
// Leased step-up authorization window (per user)
//
// The import key is session continuity, NOT a credential. Every write requires
// an ACTIVE, time-boxed window for the key's user, opened ONLY by password+TOTP
// via the flkrfckr/authorize endpoint. The key alone cannot open it. The window
// is stored in snap_settings as flkrfckr_window_u<user_id> = expiry unix ts.
// ---------------------------------------------------------------------------

function flkrfckr_window_active(PDO $pdo, int $uid): bool {
    if ($uid <= 0) return false;
    $stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute(['flkrfckr_window_u' . $uid]);
    return ((int)($stmt->fetchColumn() ?: 0)) > time();
}

function flkrfckr_window_minutes(PDO $pdo): int {
    $m = (int)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='flkrfckr_window_minutes' LIMIT 1")->fetchColumn() ?: 0);
    return ($m > 0 && $m <= 1440) ? $m : 240;   // default 4h; hard cap 24h
}

// ---------------------------------------------------------------------------
// IP rate-limit (reuses snap_rate_limits / snap_ip_bans — same as login)
// ---------------------------------------------------------------------------

function flkrfckr_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function flkrfckr_ip_banned(PDO $pdo, string $ip): bool {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM snap_ip_bans WHERE ip = ? AND expires_at > NOW() LIMIT 1");
        $stmt->execute([$ip]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) { return false; }
}

function flkrfckr_record_auth_failure(PDO $pdo, string $ip): void {
    try {
        $pdo->prepare(
            "INSERT INTO snap_rate_limits (ip, action, count, window_start)
             VALUES (?, 'flkrfckr_auth_fail', 1, NOW())
             ON DUPLICATE KEY UPDATE
               count        = IF(window_start < DATE_SUB(NOW(), INTERVAL 10 MINUTE), 1, count + 1),
               window_start = IF(window_start < DATE_SUB(NOW(), INTERVAL 10 MINUTE), NOW(), window_start)"
        )->execute([$ip]);
        $row = $pdo->prepare(
            "SELECT count FROM snap_rate_limits
             WHERE ip = ? AND action = 'flkrfckr_auth_fail'
               AND window_start >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
        );
        $row->execute([$ip]);
        if ((int)($row->fetchColumn() ?: 0) >= 5) {
            $pdo->prepare(
                "INSERT INTO snap_ip_bans (ip, reason, banned_at, expires_at)
                 VALUES (?, 'auto:flkrfckr_auth', NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason), banned_at = NOW(), expires_at = VALUES(expires_at)"
            )->execute([$ip]);
            $pdo->prepare("DELETE FROM snap_rate_limits WHERE ip = ? AND action = 'flkrfckr_auth_fail'")->execute([$ip]);
        }
    } catch (PDOException $e) { /* rate-limit is best-effort; never block on its failure */ }
}

// ---------------------------------------------------------------------------
// Response helpers
// ---------------------------------------------------------------------------

function flkrfckr_error(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function flkrfckr_ok(array $data): void {
    echo json_encode(array_merge(['status' => 'ok'], $data));
    exit;
}

// ---------------------------------------------------------------------------
// Slug helpers
// ---------------------------------------------------------------------------

if (!function_exists('flkrfckr_slugify')) {
    function flkrfckr_slugify(string $s): string {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $s);
        $s = preg_replace('/[\s_-]+/', '-', $s);
        return trim($s, '-');
    }
}

function flkrfckr_unique_slug(PDO $pdo, string $title): string {
    $base = flkrfckr_slugify($title);
    if ($base === '') $base = 'photo';
    $slug = $base;
    $n    = 1;
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM snap_images WHERE img_slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        if (!$stmt->fetch()) break;
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

// ---------------------------------------------------------------------------
// Gate auth
// ---------------------------------------------------------------------------

$fl_key = flkrfckr_auth($pdo);
if ($fl_key === false) {
    flkrfckr_error(401, 'Invalid or missing API key.');
}
// The user this key acts as. NULL on a legacy (pre-attribution) key — writes are
// refused below until the key is regenerated.
$fl_user_id = (isset($fl_key['user_id']) && $fl_key['user_id'] !== null) ? (int)$fl_key['user_id'] : 0;

// ---------------------------------------------------------------------------
// Route parsing
// ---------------------------------------------------------------------------

$route  = $_GET['route'] ?? '';
$sub    = preg_replace('#^flkrfckr/?#', '', $route);
$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------------
// POST flkrfckr/authorize — step-up: open a leased import window (password+TOTP)
//
// Handled BEFORE the write gate (this is how you GET authorized). Requires the
// Bearer key (identifies the user) AND that user's password + TOTP. You can only
// open a window for the user the key is bound to — no impersonation. IP rate-
// limited to blunt credential brute-force.
// ---------------------------------------------------------------------------

if ($sub === 'authorize' && $method === 'POST') {
    $ip = flkrfckr_client_ip();
    if (flkrfckr_ip_banned($pdo, $ip)) {
        flkrfckr_error(429, 'Too many attempts. Try again later.');
    }
    if ($fl_user_id <= 0) {
        flkrfckr_error(403, 'This import key is not bound to a user. Regenerate it in admin → API Keys.');
    }

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim($body['username']  ?? '');
    $password = (string)($body['password'] ?? '');
    $totp     = preg_replace('/\s+/', '', (string)($body['totp_code'] ?? ''));

    $stmt = $pdo->prepare("SELECT id, password_hash, totp_enabled, totp_secret FROM snap_users WHERE username = ? LIMIT 1");
    $stmt->execute([$username]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    // The credentials must belong to the user this key is bound to.
    if (!$u || (int)$u['id'] !== $fl_user_id) {
        flkrfckr_record_auth_failure($pdo, $ip);
        flkrfckr_error(403, 'Credentials do not match the user this key belongs to.');
    }
    if ($password === '' || !password_verify($password, $u['password_hash'])) {
        flkrfckr_record_auth_failure($pdo, $ip);
        flkrfckr_error(401, 'Password incorrect.');
    }
    // Step-up is ALWAYS password + TOTP — no password-only fallback (Sean policy).
    if (empty($u['totp_enabled']) || empty($u['totp_secret'])) {
        flkrfckr_error(403, 'Two-factor authentication is required to authorize imports. Enrol 2FA in admin, then retry.');
    }
    if ($totp === '' || !totp_verify($u['totp_secret'], $totp)) {
        flkrfckr_record_auth_failure($pdo, $ip);
        flkrfckr_error(401, 'Authenticator code incorrect.');
    }

    // Success — open the leased window for this user.
    $until = time() + (flkrfckr_window_minutes($pdo) * 60);
    $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    )->execute(['flkrfckr_window_u' . $fl_user_id, (string)$until]);

    flkrfckr_ok([
        'message'          => 'Import authorized.',
        'authorized_until' => $until,
        'window_minutes'   => flkrfckr_window_minutes($pdo),
    ]);
}

// ---------------------------------------------------------------------------
// Import-safety context + guards (server-enforced)
//
// Flkr Fckr is a SMACKONEOUT (photoblog) tool ONLY, is single-site (NO hub/mesh
// awareness), and is additive-only (no DELETE / no UPDATE-of-existing path).
// Guards:
//   1. Install-mode lock — refuse any write unless site_mode === 'photoblog'.
//   2. Non-empty-site lock — if the site already holds > 5 items, refuse writes
//      until the owner authorizes an import in the admin (Settings -> API Access),
//      which sets import_authorized_until. Empty/new sites import freely.
// GET reads (ping, albums) are unaffected.
// ---------------------------------------------------------------------------
$fl_site_mode = (string)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_mode' LIMIT 1")->fetchColumn() ?: 'photoblog');
$fl_content   = max(
    (int)$pdo->query("SELECT COUNT(*) FROM snap_posts")->fetchColumn(),
    (int)$pdo->query("SELECT COUNT(*) FROM snap_images")->fetchColumn()
);
// Per-user leased window — opened ONLY by flkrfckr/authorize (password+TOTP).
// The key alone is NOT a credential and cannot write. No empty-site free pass,
// no auto-slide: a >window-length import re-authorizes on resume (checkpoint
// resumes cleanly). See _continuity/per-user-keys-and-leased-auth-spec.md.
$fl_import_authorized = flkrfckr_window_active($pdo, $fl_user_id);

if ($method === 'POST') {
    if ($fl_site_mode !== 'photoblog') {
        flkrfckr_error(409, "Flkr Fckr imports Flickr into SMACKONEOUT (photoblog) installs only. This site is '{$fl_site_mode}' — no bueno.");
    }
    if ($fl_user_id <= 0) {
        flkrfckr_error(403, 'This import key is not bound to a user. Regenerate it in admin → API Keys.');
    }
    if (!$fl_import_authorized) {
        flkrfckr_error(403, 'Import session not authorized. Open a window with step-up auth (password + 2FA) via flkrfckr/authorize, then retry.');
    }
}

// ---------------------------------------------------------------------------
// GET flkrfckr/ping — preflight: reports compatibility so the tool can pre-check
// ---------------------------------------------------------------------------

if ($sub === 'ping' && $method === 'GET') {
    flkrfckr_ok([
        'message'           => 'Connected.',
        'site_mode'         => $fl_site_mode,
        'compatible'        => ($fl_site_mode === 'photoblog'),
        'content_count'     => $fl_content,
        'key_bound'         => ($fl_user_id > 0),   // false = legacy key, must regenerate
        'import_authorized' => $fl_import_authorized, // active step-up window for this user?
        'window_minutes'    => flkrfckr_window_minutes($pdo),
    ]);
}

// ---------------------------------------------------------------------------
// GET flkrfckr/albums
// ---------------------------------------------------------------------------

if ($sub === 'albums' && $method === 'GET') {
    $stmt = $pdo->query("
        SELECT id, album_name AS name, album_description AS description
        FROM snap_albums
        ORDER BY album_name ASC
    ");
    flkrfckr_ok(['albums' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ---------------------------------------------------------------------------
// POST flkrfckr/albums
// ---------------------------------------------------------------------------

if ($sub === 'albums' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($body['name'] ?? '');
    $desc = trim($body['description'] ?? '');
    $views = max(0, (int)($body['view_count'] ?? 0));   // imported Flickr album view tally

    if ($name === '') {
        flkrfckr_error(400, 'name is required.');
    }

    // Defensive: view_count column (canonical owns it; catches pre-column installs).
    try { $pdo->exec("ALTER TABLE snap_albums ADD COLUMN IF NOT EXISTS `view_count` int NOT NULL DEFAULT 0"); } catch (Exception $e) {}

    // Case-insensitive match on name
    $stmt = $pdo->prepare("
        SELECT id FROM snap_albums
        WHERE LOWER(album_name) = LOWER(?) LIMIT 1
    ");
    $stmt->execute([$name]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Refresh the imported view tally on re-import (only if a value was sent).
        if ($views > 0) {
            $pdo->prepare("UPDATE snap_albums SET view_count = ? WHERE id = ?")
                ->execute([$views, (int)$existing['id']]);
        }
        flkrfckr_ok(['album_id' => (int)$existing['id'], 'created' => false]);
    }

    // NOTE: snap_albums has no created_at column (canonical) — do not insert one.
    $pdo->prepare("
        INSERT INTO snap_albums (album_name, album_description, view_count)
        VALUES (?, ?, ?)
    ")->execute([$name, $desc, $views]);

    $album_id = (int)$pdo->lastInsertId();
    flkrfckr_ok(['album_id' => $album_id, 'created' => true]);
}

// ---------------------------------------------------------------------------
// POST flkrfckr/images
// ---------------------------------------------------------------------------

if ($sub === 'images' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $flickr_id        = trim($body['flickr_id']        ?? '');
    $img_file         = trim($body['img_file']          ?? '');
    $img_title        = trim($body['img_title']         ?? '');
    $img_description  = trim($body['img_description']   ?? '');
    $img_date         = trim($body['img_date']           ?? '');
    $img_width        = (int)($body['img_width']        ?? 0);
    $img_height       = (int)($body['img_height']       ?? 0);
    // img_orientation is an INT column (0 = landscape/square, 1 = portrait),
    // matching the Unzucker importer. The desktop client sends a human label;
    // map it to an int so the INSERT stays valid under strict-mode MySQL — a
    // raw string would raise "Incorrect integer value" and abort every import.
    $img_orientation  = (strtolower(trim($body['img_orientation'] ?? '')) === 'portrait') ? 1 : 0;
    $img_exif         = trim($body['img_exif']           ?? '');
    $img_source_file  = trim($body['img_source_file']   ?? '');
    $img_thumb_square = trim($body['img_thumb_square']  ?? '');
    $img_thumb_aspect = trim($body['img_thumb_aspect']  ?? '');
    $album_ids        = $body['album_ids']               ?? [];
    $tags             = $body['tags']                    ?? [];
    $status           = trim($body['status']             ?? 'published');
    $img_license      = trim($body['img_license']        ?? '');
    $img_like_seed    = (int)($body['img_like_seed']     ?? 0);
    $img_view_seed    = (int)($body['img_view_seed']     ?? 0);
    $img_source_url   = trim($body['img_source_url']     ?? '');

    // Defensive structural add — canonical schema carries img_like_seed, but a
    // spoke updated mid-cycle may not yet. Idempotent; pure additive.
    try {
        $pdo->exec("ALTER TABLE snap_images
            ADD COLUMN IF NOT EXISTS img_like_seed INT UNSIGNED NOT NULL DEFAULT 0
            COMMENT 'Imported like tally (e.g. Flickr fave count). Live snap_likes add on top.'");
    } catch (Throwable $e) { /* column exists or DB lacks IF NOT EXISTS — ignore */ }
    // View tally + provenance source URL (canonical carries these; defensive for
    // a spoke mid-update). Idempotent, pure additive.
    try {
        $pdo->exec("ALTER TABLE snap_images
            ADD COLUMN IF NOT EXISTS img_view_seed INT UNSIGNED NOT NULL DEFAULT 0,
            ADD COLUMN IF NOT EXISTS img_source_url VARCHAR(500) NULL DEFAULT NULL");
    } catch (Throwable $e) { /* columns exist or DB lacks IF NOT EXISTS — ignore */ }

    if ($flickr_id === '') flkrfckr_error(400, 'flickr_id is required.');
    if ($img_file  === '') flkrfckr_error(400, 'img_file is required.');
    if ($img_title === '') $img_title = 'Untitled';

    // Normalise source file key
    $source_key = 'flickr:' . $flickr_id;
    if ($img_source_file === '') $img_source_file = $source_key;

    // Duplicate check
    $stmt = $pdo->prepare("
        SELECT id, img_slug FROM snap_images
        WHERE img_source_file = ? LIMIT 1
    ");
    $stmt->execute([$source_key]);
    $dup = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dup) {
        flkrfckr_ok([
            'image_id'  => (int)$dup['id'],
            'img_slug'  => $dup['img_slug'],
            'duplicate' => true,
        ]);
    }

    // Validate status
    $allowed_statuses = ['published', 'draft', 'private'];
    if (!in_array($status, $allowed_statuses, true)) {
        $status = 'published';
    }

    // Generate slug
    $slug = flkrfckr_unique_slug($pdo, $img_title);

    // Validate/sanitise img_date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $img_date)) {
        $img_date = date('Y-m-d H:i:s');
    }

    // Validate img_exif is JSON or empty
    if ($img_exif !== '' && json_decode($img_exif) === null) {
        $img_exif = '';
    }
    if ($img_exif === '') $img_exif = null;

    // Ensure the optional img_license column exists. Structural-only add, so the
    // canonical schema sync also applies it on next update; this is the
    // belt-and-suspenders defensive ALTER for installs that haven't synced yet.
    try {
        $pdo->query("SELECT img_license FROM snap_images LIMIT 0");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE snap_images ADD COLUMN img_license VARCHAR(100) NULL DEFAULT NULL AFTER img_film");
    }

    // Ensure the per-user owner column exists (canonical owns it; defensive add
    // for installs that haven't synced yet). The owner is FORCED to the key's
    // user below — any client-supplied user is ignored (no impersonation).
    try {
        $pdo->query("SELECT user_id FROM snap_images LIMIT 0");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE snap_images ADD COLUMN user_id INT UNSIGNED DEFAULT NULL AFTER post_id");
    }

    // Get next sort_order (avoid self-referencing subquery in INSERT VALUES)
    $sort_row = $pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS next_sort FROM snap_images")->fetch(PDO::FETCH_ASSOC);
    $sort_order = (int)($sort_row['next_sort'] ?? 1);

    // Insert image record.
    // NOTE: snap_images has no created_at column (canonical uses modified_at,
    // auto-managed) — do not insert one or the statement errors.
    $pdo->prepare("
        INSERT INTO snap_images (
            img_slug, img_file, img_title, img_description,
            img_date, img_width, img_height, img_orientation,
            img_exif, img_source_file,
            img_thumb_square, img_thumb_aspect,
            img_license, img_status, sort_order, img_like_seed,
            img_view_seed, img_source_url, user_id
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?
        )
    ")->execute([
        $slug, $img_file, $img_title, $img_description,
        $img_date, $img_width, $img_height, $img_orientation,
        $img_exif, $img_source_file,
        $img_thumb_square ?: null, $img_thumb_aspect ?: null,
        $img_license ?: null, $status, $sort_order, $img_like_seed,
        $img_view_seed, $img_source_url ?: null, $fl_user_id,
    ]);

    $image_id = (int)$pdo->lastInsertId();

    // Album mappings
    if (is_array($album_ids) && count($album_ids) > 0) {
        $map_stmt = $pdo->prepare("
            INSERT IGNORE INTO snap_image_album_map (image_id, album_id)
            VALUES (?, ?)
        ");
        foreach ($album_ids as $aid) {
            $aid = (int)$aid;
            if ($aid > 0) $map_stmt->execute([$image_id, $aid]);
        }
    }

    // Tags — use snap_sync_tags if tags provided
    if (is_array($tags) && count($tags) > 0) {
        // snap_sync_tags expects a space-separated tag string with # prefixes
        $tag_string = implode(' ', array_map(fn($t) => '#' . ltrim($t, '#'), $tags));
        snap_sync_tags($pdo, $image_id, $tag_string);
    }

    flkrfckr_ok([
        'image_id'  => $image_id,
        'img_slug'  => $slug,
        'duplicate' => false,
    ]);
}

// ---------------------------------------------------------------------------
// POST flkrfckr/albums/cover — set an album's cover to an imported photo.
// Body: { album_id:int, cover_flickr_id:str }. Resolves flickr:<id> →
// snap_images.id → snap_albums.featured_post_id. Run after images are imported
// (the cover photo must already exist as a snap_images row).
// ---------------------------------------------------------------------------

if ($sub === 'albums/cover' && $method === 'POST') {
    $body            = json_decode(file_get_contents('php://input'), true) ?? [];
    $album_id        = (int)($body['album_id']         ?? 0);
    $cover_flickr_id = trim($body['cover_flickr_id']   ?? '');

    if ($album_id <= 0)          flkrfckr_error(400, 'album_id is required.');
    if ($cover_flickr_id === '') flkrfckr_error(400, 'cover_flickr_id is required.');

    // Resolve the imported photo by its Flickr source key.
    $stmt = $pdo->prepare("SELECT id FROM snap_images WHERE img_source_file = ? LIMIT 1");
    $stmt->execute(['flickr:' . $cover_flickr_id]);
    $img = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$img) {
        // Cover photo not imported (excluded/missing) — leave the album as-is.
        flkrfckr_ok(['album_id' => $album_id, 'cover_set' => false, 'reason' => 'cover photo not found']);
    } else {
        $pdo->prepare("UPDATE snap_albums SET featured_post_id = ? WHERE id = ?")
            ->execute([(int)$img['id'], $album_id]);
        flkrfckr_ok(['album_id' => $album_id, 'featured_post_id' => (int)$img['id'], 'cover_set' => true]);
    }
}

// ---------------------------------------------------------------------------
// POST flkrfckr/upload — multipart image upload (mirrors unzucker/upload).
// The desktop client sends the resized JPEG here over HTTPS; no FTP needed.
// Saves under img_uploads/YYYY/MM/ and generates t_/a_ thumbnails server-side
// via the shared core helper. Returns { path, thumb_square, thumb_aspect,
// width, height } — the client feeds path/thumbs into flkrfckr/images.
// ---------------------------------------------------------------------------

if ($sub === 'upload' && $method === 'POST') {
    if (empty($_FILES['image'])) {
        flkrfckr_error(400, 'No file uploaded. Expected multipart field: image');
    }
    $file = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        flkrfckr_error(400, 'Upload error code ' . (int)$file['error']);
    }

    // Verify the REAL MIME type — never trust the client Content-Type. Accept the
    // formats the server thumbnailer supports (JPEG, PNG, WebP). Originals are
    // stored byte-for-byte in their own format; thumbnails are always JPEG.
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mime     = $finfo->file($file['tmp_name']);
    $mime_ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($mime_ext[$mime])) {
        flkrfckr_error(400, 'Only JPEG, PNG or WebP images are accepted (detected: ' . $mime . ').');
    }
    $ext    = $mime_ext[$mime];
    $ext_re = ['jpg' => 'jpe?g', 'png' => 'png', 'webp' => 'webp'][$ext];

    // Client resizes before sending, so anything over 25 MB is a bug or attack.
    if ($file['size'] > 25 * 1024 * 1024) {
        flkrfckr_error(400, 'File too large (max 25 MB).');
    }

    // Sanitise the client filename — keep only safe chars; extension must match
    // the detected type.
    $client_name = preg_replace('/[^a-z0-9_.-]/', '', strtolower(basename($file['name'] ?? '')));
    if (!preg_match('/\.' . $ext_re . '$/', $client_name) || strlen($client_name) > 120) {
        $client_name = '';
    }
    $filename = $client_name ?: (date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext);

    // Destination: img_uploads/YYYY/MM/ relative to the site root.
    $year_month = date('Y/m');
    $site_root  = dirname(__DIR__);
    $dest_dir   = $site_root . '/img_uploads/' . $year_month;
    if (!is_dir($dest_dir)) {
        mkdir($dest_dir, 0755, true);
    }

    // Avoid collisions.
    $dest_path = $dest_dir . '/' . $filename;
    if (file_exists($dest_path)) {
        $filename  = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest_path = $dest_dir . '/' . $filename;
    }

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        flkrfckr_error(500, 'Failed to save uploaded file. Check permissions on img_uploads/.');
    }

    $rel_path = 'img_uploads/' . $year_month . '/' . $filename;

    $thumb_sq = '';
    $thumb_as = '';
    $width    = 0;
    $height   = 0;

    // ── Client-built thumbnails (load-off-the-host path) ─────────────────────
    // If the client shipped t_/a_ thumbs in this same request, save them under
    // img_uploads/YYYY/MM/thumbs/ using the canonical t_/a_<filename> names and
    // SKIP the server-side GD pass. We still MIME-verify each part and never
    // trust the client's filenames. Either both valid thumbs are accepted or we
    // fall through to server generation — we never store a half set.
    $thumb_dir_rel = 'img_uploads/' . $year_month . '/thumbs';
    $thumb_dir     = $site_root . '/' . $thumb_dir_rel;
    // Thumbs are always JPEG, so name them .jpg even when the original is PNG/WebP.
    $thumb_base    = preg_replace('/\.[^.]+$/', '.jpg', $filename);
    $sq_rel        = $thumb_dir_rel . '/t_' . $thumb_base;
    $as_rel        = $thumb_dir_rel . '/a_' . $thumb_base;

    $client_thumbs_ok = false;
    if (!empty($_FILES['thumb_square']) && !empty($_FILES['thumb_aspect'])) {
        $tsq = $_FILES['thumb_square'];
        $tas = $_FILES['thumb_aspect'];
        $finfo_t = new finfo(FILEINFO_MIME_TYPE);
        $sq_ok = ($tsq['error'] === UPLOAD_ERR_OK)
              && $tsq['size'] > 0 && $tsq['size'] <= 5 * 1024 * 1024
              && $finfo_t->file($tsq['tmp_name']) === 'image/jpeg';
        $as_ok = ($tas['error'] === UPLOAD_ERR_OK)
              && $tas['size'] > 0 && $tas['size'] <= 5 * 1024 * 1024
              && $finfo_t->file($tas['tmp_name']) === 'image/jpeg';
        if ($sq_ok && $as_ok) {
            if (!is_dir($thumb_dir)) {
                mkdir($thumb_dir, 0755, true);
            }
            if (move_uploaded_file($tsq['tmp_name'], $site_root . '/' . $sq_rel)
             && move_uploaded_file($tas['tmp_name'], $site_root . '/' . $as_rel)) {
                $thumb_sq = $sq_rel;
                $thumb_as = $as_rel;
                // Source dimensions from the original (raw px — matches the GD
                // path, which reads imagesx/imagesy without EXIF transpose).
                $dims = @getimagesize($dest_path);
                if ($dims) { $width = (int)$dims[0]; $height = (int)$dims[1]; }
                $client_thumbs_ok = true;
            }
        }
    }

    // ── Fallback: server-side generation (any tool not yet shipping thumbs) ──
    if (!$client_thumbs_ok) {
        $thumbs = snapsmack_generate_thumbs($rel_path, $site_root);
        if ($thumbs !== false) {
            $thumb_sq = $thumbs['sq_path'];
            $thumb_as = $thumbs['asp_path'];
            $width    = (int)$thumbs['width'];
            $height   = (int)$thumbs['height'];
        }
    }

    flkrfckr_ok([
        'path'         => $rel_path,
        'thumb_square' => $thumb_sq,
        'thumb_aspect' => $thumb_as,
        'width'        => $width,
        'height'       => $height,
    ]);
}

// POST flkrfckr/comments — import a single Flickr comment onto an existing post.
// Inserts into snap_community_comments as a guest comment (status=visible, auto-approved).
// Expects JSON body: { flickr_id, author_name, author_url, comment_text, comment_date }
// where flickr_id is the SnapSmack image_id returned from flkrfckr/images.
if ($method === 'POST' && $sub === 'comments') {
    $body         = json_decode(file_get_contents('php://input'), true) ?? [];
    $image_id     = (int)($body['image_id']     ?? 0);
    $author_name  = trim($body['author_name']   ?? '');
    $author_url   = trim($body['author_url']    ?? '');
    $comment_text = trim($body['comment_text']  ?? '');
    $comment_date = trim($body['comment_date']  ?? '');

    if ($image_id <= 0)         flkrfckr_error(400, 'image_id is required.');
    if ($comment_text === '')   flkrfckr_error(400, 'comment_text is required.');

    // Validate image exists
    $img_chk = $pdo->prepare("SELECT id FROM snap_images WHERE id = ? LIMIT 1");
    $img_chk->execute([$image_id]);
    if (!$img_chk->fetch()) flkrfckr_error(404, 'image_id not found.');

    // Validate date or default to now
    $dt = $comment_date ? date('Y-m-d H:i:s', strtotime($comment_date)) : date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("
        INSERT INTO snap_community_comments
            (post_id, guest_name, guest_url, comment_text, status, created_at)
        VALUES (?, ?, ?, ?, 'visible', ?)
    ");
    $stmt->execute([
        $image_id,
        $author_name ?: 'Anonymous',
        $author_url ?: null,
        $comment_text,
        $dt,
    ]);
    $comment_id = (int)$pdo->lastInsertId();

    flkrfckr_ok(['comment_id' => $comment_id]);
}

// POST flkrfckr/collections — create (or rebuild) a "best of" collection from a
// ranked list of image IDs. Body: { title, description?, published?,
// cover_image_id?, image_ids:[ordered snap_images.id] }. Idempotent on slug — a
// re-run rebuilds the same collection's membership in the given order.
if ($method === 'POST' && $sub === 'collections') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $title     = trim($body['title'] ?? '');
    if ($title === '') flkrfckr_error(400, 'title is required.');
    $desc      = trim($body['description'] ?? '');
    $published = !empty($body['published']) ? 1 : 0;
    $image_ids = array_values(array_filter(
        array_map('intval', (array)($body['image_ids'] ?? [])),
        fn($i) => $i > 0
    ));
    $cover = (int)($body['cover_image_id'] ?? ($image_ids[0] ?? 0));

    // Defensive: allow 'image' collection items (canonical carries this; a spoke
    // mid-update may still be on the post/album/category-only enum).
    try {
        $pdo->exec("ALTER TABLE snap_collection_items
                    MODIFY `item_type` ENUM('post','album','category','image')
                    COLLATE utf8mb4_unicode_ci NOT NULL");
    } catch (Throwable $e) { /* already widened, or tables not yet synced */ }

    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
    if ($slug === '') $slug = 'collection';

    // Create-or-get by slug, then rebuild membership.
    $sel = $pdo->prepare("SELECT id FROM snap_collections WHERE slug = ? LIMIT 1");
    $sel->execute([$slug]);
    $existing = $sel->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $cid = (int)$existing['id'];
        $pdo->prepare("UPDATE snap_collections
                       SET title = ?, description = ?, published = ?, cover_image_id = ?
                       WHERE id = ?")
            ->execute([$title, $desc ?: null, $published, $cover ?: null, $cid]);
        $pdo->prepare("DELETE FROM snap_collection_items WHERE collection_id = ?")->execute([$cid]);
    } else {
        $so = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM snap_collections")->fetchColumn();
        $pdo->prepare("INSERT INTO snap_collections
                       (title, slug, description, cover_image_id, sort_order, published)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$title, $slug, $desc ?: null, $cover ?: null, $so, $published]);
        $cid = (int)$pdo->lastInsertId();
    }

    // Add image items in the given rank order.
    $ins = $pdo->prepare("INSERT IGNORE INTO snap_collection_items
                          (collection_id, item_type, item_id, sort_order)
                          VALUES (?, 'image', ?, ?)");
    $rank = 0;
    foreach ($image_ids as $iid) { $ins->execute([$cid, $iid, $rank]); $rank++; }

    flkrfckr_ok(['collection_id' => $cid, 'slug' => $slug, 'items' => count($image_ids)]);
}
// ===== SNAPSMACK EOF =====
