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

    // NOTE: snap_albums has no created_at column (canonical) — do not insert one.
    $pdo->prepare("
        INSERT INTO snap_albums (album_name, album_description)
        VALUES (?, ?)
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
            img_license, img_status, sort_order
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?, ?
        )
    ")->execute([
        $slug, $img_file, $img_title, $img_description,
        $img_date, $img_width, $img_height, $img_orientation,
        $img_exif, $img_source_file,
        $img_thumb_square ?: null, $img_thumb_aspect ?: null,
        $img_license ?: null, $status, $sort_order,
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

    // Verify the REAL MIME type — never trust the client Content-Type.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if ($mime !== 'image/jpeg') {
        flkrfckr_error(400, 'Only JPEG images are accepted (detected: ' . $mime . ').');
    }

    // Client resizes before sending, so anything over 25 MB is a bug or attack.
    if ($file['size'] > 25 * 1024 * 1024) {
        flkrfckr_error(400, 'File too large (max 25 MB).');
    }

    // Sanitise the client filename — keep only safe chars.
    $client_name = preg_replace('/[^a-z0-9_.-]/', '', strtolower(basename($file['name'] ?? '')));
    if (!preg_match('/\.jpe?g$/', $client_name) || strlen($client_name) > 120) {
        $client_name = '';
    }
    $filename = $client_name ?: (date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg');

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
        $filename  = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        $dest_path = $dest_dir . '/' . $filename;
    }

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        flkrfckr_error(500, 'Failed to save uploaded file. Check permissions on img_uploads/.');
    }

    $rel_path = 'img_uploads/' . $year_month . '/' . $filename;

    // Generate square + aspect thumbnails with the shared core helper.
    $thumb_sq = '';
    $thumb_as = '';
    $width    = 0;
    $height   = 0;
    $thumbs = snapsmack_generate_thumbs($rel_path, $site_root);
    if ($thumbs !== false) {
        $thumb_sq = $thumbs['sq_path'];
        $thumb_as = $thumbs['asp_path'];
        $width    = (int)$thumbs['width'];
        $height   = (int)$thumbs['height'];
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
// ===== SNAPSMACK EOF =====
