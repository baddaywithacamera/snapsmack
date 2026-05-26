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

function flkrfckr_auth(PDO $pdo): bool {
    flkrfckr_ensure_key_type($pdo);
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
        return false;
    }
    $hash = hash('sha256', $m[1]);
    $stmt = $pdo->prepare("
        SELECT id FROM snap_ohsnap_keys
        WHERE key_hash = ? AND is_active = 1 AND key_type = 'flkrfckr'
        LIMIT 1
    ");
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) return false;
    $pdo->prepare("UPDATE snap_ohsnap_keys SET last_used_at = NOW() WHERE id = ?")
        ->execute([$row['id']]);
    return true;
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

if (!flkrfckr_auth($pdo)) {
    flkrfckr_error(401, 'Invalid or missing API key.');
}

// ---------------------------------------------------------------------------
// Route parsing
// ---------------------------------------------------------------------------

$route  = $_GET['route'] ?? '';
$sub    = preg_replace('#^flkrfckr/?#', '', $route);
$method = $_SERVER['REQUEST_METHOD'];

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

    if ($name === '') {
        flkrfckr_error(400, 'name is required.');
    }

    // Case-insensitive match on name
    $stmt = $pdo->prepare("
        SELECT id FROM snap_albums
        WHERE LOWER(album_name) = LOWER(?) LIMIT 1
    ");
    $stmt->execute([$name]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        flkrfckr_ok(['album_id' => (int)$existing['id'], 'created' => false]);
    }

    $pdo->prepare("
        INSERT INTO snap_albums (album_name, album_description, created_at)
        VALUES (?, ?, NOW())
    ")->execute([$name, $desc]);

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
    $img_orientation  = trim($body['img_orientation']   ?? 'landscape');
    $img_exif         = trim($body['img_exif']           ?? '');
    $img_source_file  = trim($body['img_source_file']   ?? '');
    $img_thumb_square = trim($body['img_thumb_square']  ?? '');
    $img_thumb_aspect = trim($body['img_thumb_aspect']  ?? '');
    $album_ids        = $body['album_ids']               ?? [];
    $tags             = $body['tags']                    ?? [];
    $status           = trim($body['status']             ?? 'published');

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

    // Get next sort_order (avoid self-referencing subquery in INSERT VALUES)
    $sort_row = $pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS next_sort FROM snap_images")->fetch(PDO::FETCH_ASSOC);
    $sort_order = (int)($sort_row['next_sort'] ?? 1);

    // Insert image record
    $pdo->prepare("
        INSERT INTO snap_images (
            img_slug, img_file, img_title, img_description,
            img_date, img_width, img_height, img_orientation,
            img_exif, img_source_file,
            img_thumb_square, img_thumb_aspect,
            img_status, sort_order, created_at
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?, NOW()
        )
    ")->execute([
        $slug, $img_file, $img_title, $img_description,
        $img_date, $img_width, $img_height, $img_orientation,
        $img_exif, $img_source_file,
        $img_thumb_square ?: null, $img_thumb_aspect ?: null,
        $status, $sort_order,
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
    