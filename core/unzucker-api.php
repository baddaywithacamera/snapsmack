<?php
/**
 * SNAPSMACK - Unzucker Instagram Import API
 *
 * Authenticated JSON API for the Unzucker Instagram→SnapSmack migration
 * desktop tool. All requests require a Bearer token issued from
 * smack-api-keys.php (key_type = 'unzucker').
 *
 * Routes (via api.php?route=unzucker/...):
 *   GET    unzucker/ping    — connection test
 *   GET    unzucker/site    — categories + albums
 *   POST   unzucker/upload  — upload a single JPEG; returns {path} for img_file
 *   POST   unzucker/posts   — create post record from already-uploaded image paths
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

// Defensive schema guard — table may be absent on installs that predate
// the Oh Snap! key table; columns key_type and key_prefix may be absent
// on installs that predate their respective migrations.
$pdo->exec("CREATE TABLE IF NOT EXISTS snap_ohsnap_keys (
    id           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    label        VARCHAR(100)     NOT NULL DEFAULT '',
    key_type     VARCHAR(20)      NOT NULL DEFAULT 'ohsnap',
    key_hash     VARCHAR(64)      NOT NULL,
    key_prefix   VARCHAR(8)       NOT NULL DEFAULT '',
    created_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME         DEFAULT NULL,
    is_active    TINYINT(1)       NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_key_hash (key_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("ALTER TABLE snap_ohsnap_keys
    ADD COLUMN IF NOT EXISTS key_type VARCHAR(20)
    NOT NULL DEFAULT 'ohsnap' AFTER label");
$pdo->exec("ALTER TABLE snap_ohsnap_keys
    ADD COLUMN IF NOT EXISTS key_prefix VARCHAR(8)
    NOT NULL DEFAULT '' AFTER key_hash");
// Defensive schema guard — title must be TEXT to hold Instagram captions up to 2200 chars.
// Runs on every API request so it fires before any INSERT, regardless of which endpoint hits first.
$pdo->exec("ALTER TABLE snap_posts MODIFY title TEXT COLLATE utf8mb4_unicode_ci NOT NULL");

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

function unzucker_auth(PDO $pdo): bool {
    // Apache FastCGI strips Authorization; check all known landing spots.
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';
    if (!$header && function_exists('getallheaders')) {
        $h = getallheaders();
        $header = $h['Authorization'] ?? $h['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $header, $m)) {
        return false;
    }
    $hash = hash('sha256', $m[1]);
    $stmt = $pdo->prepare("
        SELECT id FROM snap_ohsnap_keys
        WHERE key_hash = ? AND is_active = 1 AND key_type = 'unzucker'
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

function uz_error(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function uz_ok(array $data): void {
    echo json_encode(array_merge(['status' => 'ok'], $data));
    exit;
}

// ---------------------------------------------------------------------------
// Slug helpers
// ---------------------------------------------------------------------------

function uz_slugify(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $s);
    $s = preg_replace('/[\s_-]+/', '-', $s);
    return trim($s, '-');
}

function uz_unique_post_slug(PDO $pdo, string $base): string {
    if ($base === '') $base = 'post';
    $slug = $base;
    $n    = 1;
    while (true) {
        $stmt = $pdo->prepare("SELECT id FROM snap_posts WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        if (!$stmt->fetch()) break;
        $slug = $base . '-' . $n++;
    }
    return $slug;
}

function uz_unique_img_slug(PDO $pdo, string $base): string {
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

// Generate a slug base from ig_id (preferred) or timestamp.
// ig_id is the IG media ID from the export filename — already URL-safe digits.
function uz_slug_base(string $ig_id, string $post_date, int $seq = 0): string {
    if ($ig_id !== '') {
        $base = 'ig-' . $ig_id;
    } else {
        $base = 'ig-' . date('Ymd-His', strtotime($post_date) ?: time());
    }
    return $seq > 0 ? $base . '-' . $seq : $base;
}

// ---------------------------------------------------------------------------
// Gate auth
// ---------------------------------------------------------------------------

if (!unzucker_auth($pdo)) {
    uz_error(401, 'Invalid or missing API key.');
}

// ---------------------------------------------------------------------------
// Route parsing
// ---------------------------------------------------------------------------

$route  = $_GET['route'] ?? '';
$sub    = preg_replace('#^unzucker/?#', '', $route);
$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------------
// Import-safety context + guards (server-enforced)
//
// Unzucker is a GRAMOFSMACK (carousel) tool ONLY, is single-site (NO hub/mesh
// awareness), and is additive-only (no DELETE / no UPDATE-of-existing path).
// Two guards protect the target site from this "data bazooka":
//   1. Install-mode lock — refuse any write unless site_mode === 'carousel'.
//   2. Non-empty-site lock — if the site already holds > 5 items, refuse writes
//      until the owner authorizes an import in the admin (Settings -> API Access),
//      which sets import_authorized_until. Empty/new sites import freely.
// GET reads (ping, site) are unaffected so the tool can preflight and report.
// ---------------------------------------------------------------------------
$uz_site_mode = (string)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_mode' LIMIT 1")->fetchColumn() ?: 'photoblog');
$uz_content   = max(
    (int)$pdo->query("SELECT COUNT(*) FROM snap_posts")->fetchColumn(),
    (int)$pdo->query("SELECT COUNT(*) FROM snap_images")->fetchColumn()
);
$uz_import_authorized = ((int)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='import_authorized_until' LIMIT 1")->fetchColumn() ?: 0)) > time();

if ($method === 'POST') {
    if ($uz_site_mode !== 'carousel') {
        uz_error(409, "Unzucker imports Instagram into GRAMOFSMACK (carousel) installs only. This site is '{$uz_site_mode}' — no bueno.");
    }
    if ($uz_content > 5 && !$uz_import_authorized) {
        uz_error(403, "This site already holds {$uz_content} items. To import into a site that is not empty, authorize the import on the admin API Keys page first.");
    }
}

// ---------------------------------------------------------------------------
// GET unzucker/ping
// ---------------------------------------------------------------------------

if ($sub === 'ping' && $method === 'GET') {
    $cat_count   = (int)$pdo->query("SELECT COUNT(*) FROM snap_categories")->fetchColumn();
    $album_count = (int)$pdo->query("SELECT COUNT(*) FROM snap_albums")->fetchColumn();
    uz_ok([
        'message'           => 'Connected.',
        'cat_count'         => $cat_count,
        'album_count'       => $album_count,
        'site_mode'         => $uz_site_mode,
        'compatible'        => ($uz_site_mode === 'carousel'),
        'content_count'     => $uz_content,
        'import_authorized' => $uz_import_authorized,
    ]);
}

// ---------------------------------------------------------------------------
// GET unzucker/site
// ---------------------------------------------------------------------------

if ($sub === 'site' && $method === 'GET') {
    $cats = $pdo->query("
        SELECT id, cat_name AS name
        FROM snap_categories
        ORDER BY cat_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $albums = $pdo->query("
        SELECT id, album_name AS name
        FROM snap_albums
        ORDER BY album_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    uz_ok([
        'categories' => $cats,
        'albums'     => $albums,
    ]);
}

// ---------------------------------------------------------------------------
// POST unzucker/posts/check  — bulk existence check by ig_id
// ---------------------------------------------------------------------------

if ($sub === 'posts/check' && $method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $ig_ids = array_values(array_filter(array_map('strval', $body['ig_ids'] ?? [])));
    if (empty($ig_ids)) uz_ok(['existing' => (object)[]]);

    $placeholders = implode(',', array_fill(0, count($ig_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT import_id, id
        FROM snap_posts
        WHERE import_source = 'instagram'
          AND import_id IN ($placeholders)
    ");
    $stmt->execute($ig_ids);
    $existing = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing[$row['import_id']] = (int)$row['id'];
    }
    uz_ok(['existing' => $existing ?: (object)[]]);
}

// ---------------------------------------------------------------------------
// POST unzucker/posts
// ---------------------------------------------------------------------------

if ($sub === 'posts' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $title     = trim($body['title']     ?? '');
    $desc      = trim($body['body']      ?? '');
    $post_date = trim($body['post_date'] ?? '');
    $ig_id     = trim($body['ig_id']     ?? '');
    $images    = $body['images']    ?? [];
    $tags      = $body['tags']      ?? [];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $post_date)) {
        $post_date = date('Y-m-d H:i:s');
    }
    if (empty($images)) uz_error(400, 'images array is required.');

    // Duplicate check via ig_id
    if ($ig_id !== '') {
        $dup = $pdo->prepare("
            SELECT id FROM snap_posts
            WHERE import_source = 'instagram' AND import_id = ? LIMIT 1
        ");
        $dup->execute([$ig_id]);
        if ($row = $dup->fetch(PDO::FETCH_ASSOC)) {
            uz_ok(['post_id' => (int)$row['id'], 'duplicate' => true]);
        }
    }

    // Build tag string for sync
    $tag_string = '';
    if (is_array($tags) && count($tags) > 0) {
        $tag_string = implode(' ', array_map(fn($t) => '#' . ltrim($t, '#'), $tags));
    }

    $pdo->beginTransaction();
    try {

    // --- Create snap_images records ---
    $image_ids = [];
    foreach ($images as $seq => $img_data) {
        $img_path = trim($img_data['path'] ?? '');
        $img_w    = (int)($img_data['width']  ?? 0);
        $img_h    = (int)($img_data['height'] ?? 0);

        if ($img_path === '') continue;

        // Validate img_path: must be ≤500 chars and end in a JPEG extension.
        // The path is pre-uploaded by the client via FTP; we're not fetching it —
        // but storing an arbitrary string in img_file could poison rendering code.
        if (strlen($img_path) > 500) {
            uz_error(400, "images[$seq].path exceeds maximum length.");
        }
        $ext = strtolower(pathinfo($img_path, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg'], true)) {
            uz_error(400, "images[$seq].path must be a .jpg or .jpeg file.");
        }

        // All Instagram imports are square; img_orientation is INT (0=landscape/square)
        $img_ori = 0;

        $img_source = $ig_id !== '' ? "instagram:{$ig_id}_{$seq}" : '';

        // Per-image duplicate check — only reuse if the image isn't already
        // linked to another post (snap_post_images uq_image would collide).
        if ($img_source !== '') {
            $dup_img = $pdo->prepare("
                SELECT si.id
                FROM snap_images si
                LEFT JOIN snap_post_images spi ON spi.image_id = si.id
                WHERE si.img_source_file = ?
                  AND spi.image_id IS NULL
                LIMIT 1
            ");
            $dup_img->execute([$img_source]);
            if ($row = $dup_img->fetch(PDO::FETCH_ASSOC)) {
                // Orphan image exists (no post_images row) — safe to reuse.
                $image_ids[] = (int)$row['id'];
                continue;
            }
        }

        $img_slug = uz_unique_img_slug($pdo, uz_slug_base($ig_id, $post_date, $seq));

        $sort_row   = $pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM snap_images")->fetch(PDO::FETCH_ASSOC);
        $sort_order = (int)($sort_row['n'] ?? 1);

        // Generate the 400px square + aspect thumbnails the skins expect. The
        // uploaded JPEG is already on disk (saved by unzucker/upload). Without
        // this, img_thumb_square stays empty and both The Grid and Photogram
        // fall back to serving the full ~2000px original. 400px matches
        // RecoveryEngine::regenerateAndChecksum() so backfills stay consistent.
        $img_thumb_square = null;
        $img_thumb_aspect = null;
        $_thumb = snapsmack_generate_thumbs($img_path, dirname(__DIR__), 400, 400);
        if ($_thumb !== false) {
            $img_thumb_square = $_thumb['sq_path'];
            $img_thumb_aspect = $_thumb['asp_path'];
        }

        $pdo->prepare("
            INSERT INTO snap_images (
                img_slug, img_file, img_title, img_description,
                img_date, img_width, img_height, img_orientation,
                img_thumb_square, img_thumb_aspect,
                img_source_file, img_status, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', ?)
        ")->execute([
            $img_slug, $img_path, '', $desc,
            $post_date, $img_w, $img_h, $img_ori,
            $img_thumb_square, $img_thumb_aspect,
            $img_source ?: null, $sort_order,
        ]);

        $img_id      = (int)$pdo->lastInsertId();
        $image_ids[] = $img_id;

        // Tags
        if ($tag_string !== '') {
            snap_sync_tags($pdo, $img_id, $tag_string);
        }
    }

    if (empty($image_ids)) uz_error(422, 'No valid images could be created.');

    // --- Create snap_posts record ---
    $post_slug       = uz_unique_post_slug($pdo, uz_slug_base($ig_id, $post_date));
    $post_type_final = count($image_ids) > 1 ? 'carousel' : 'single';

    $pdo->prepare("
        INSERT INTO snap_posts (
            title, slug, description, post_type, status,
            import_source, import_id, created_at, updated_at
        ) VALUES (?, ?, ?, ?, 'published', 'instagram', ?, ?, NOW())
    ")->execute([
        $title, $post_slug, $desc, $post_type_final,
        ($ig_id !== '' ? $ig_id : null),
        $post_date,
    ]);

    $post_id = (int)$pdo->lastInsertId();

    // --- snap_post_images pivot + denormalized post_id on snap_images ---
    $pi_stmt  = $pdo->prepare("
        INSERT INTO snap_post_images (post_id, image_id, sort_position, is_cover)
        VALUES (?, ?, ?, ?)
    ");
    $upd_stmt = $pdo->prepare("UPDATE snap_images SET post_id = ? WHERE id = ?");
    foreach ($image_ids as $pos => $img_id) {
        $pi_stmt->execute([$post_id, $img_id, $pos, ($pos === 0 ? 1 : 0)]);
        $upd_stmt->execute([$post_id, $img_id]);
    }

    $pdo->commit();

    } catch (Throwable $e) {
        $pdo->rollBack();
        uz_error(500, 'Import failed: ' . $e->getMessage());
    }

    uz_ok([
        'post_id'   => $post_id,
        'post_slug' => $post_slug,
        'image_ids' => $image_ids,
        'duplicate' => false,
    ]);
}

// ---------------------------------------------------------------------------
// POST unzucker/upload
// ---------------------------------------------------------------------------

if ($sub === 'upload' && $method === 'POST') {
    if (empty($_FILES['image'])) {
        uz_error(400, 'No file uploaded. Expected multipart field: image');
    }

    $file = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $err_labels = [
            1 => 'exceeds upload_max_filesize',
            2 => 'exceeds MAX_FILE_SIZE',
            3 => 'partially uploaded',
            4 => 'no file sent',
            6 => 'no temp dir',
            7 => 'write failed',
            8 => 'extension blocked',
        ];
        uz_error(400, 'Upload error: ' . ($err_labels[$file['error']] ?? 'code ' . $file['error']));
    }

    // Verify real MIME type — don't trust client Content-Type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if ($mime !== 'image/jpeg') {
        uz_error(400, 'Only JPEG images are accepted (detected: ' . $mime . ').');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg'], true)) {
        uz_error(400, 'Only .jpg/.jpeg files are accepted.');
    }

    // Sanity cap — client resizes before sending, so >20 MB is a bug or attack
    if ($file['size'] > 20 * 1024 * 1024) {
        uz_error(400, 'File too large (max 20 MB).');
    }

    // Sanitise client filename — keep only safe chars
    $client_name = preg_replace('/[^a-z0-9_.-]/', '', strtolower(basename($file['name'])));
    if (!preg_match('/\.jpe?g$/', $client_name) || strlen($client_name) > 120) {
        $client_name = '';
    }
    $filename = $client_name ?: (date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg');

    // Build destination — img_uploads/YYYY/MM/ relative to site root
    $year_month = date('Y/m');
    $site_root  = dirname(__DIR__);
    $dest_dir   = $site_root . '/img_uploads/' . $year_month;

    if (!is_dir($dest_dir)) {
        mkdir($dest_dir, 0755, true);
    }
    if (!is_dir($dest_dir . '/thumbs')) {
        mkdir($dest_dir . '/thumbs', 0755, true);
    }

    // Avoid collisions
    $dest_path = $dest_dir . '/' . $filename;
    if (file_exists($dest_path)) {
        $filename  = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.jpg';
        $dest_path = $dest_dir . '/' . $filename;
    }

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        uz_error(500, 'Failed to save uploaded file. Check directory permissions on img_uploads/.');
    }

    // Return the path relative to img_uploads/ — this is what goes in snap_images.img_file
    uz_ok(['path' => 'img_uploads/' . $year_month . '/' . $filename]);
}

// ---------------------------------------------------------------------------
// POST unzucker/trigram
// ---------------------------------------------------------------------------
// Second call in the two-call trigram import flow.  Called after all three
// posts have been created via POST unzucker/posts.
//
// Body: {
//   "post_id_1": <int>,   // L slot
//   "post_id_2": <int>,   // M slot
//   "post_id_3": <int>,   // R slot
//   "orientation": "h"    // optional, default "h"
// }
//
// Creates snap_trigrams row (type='group'), sets trigram_id on all three
// posts, assigns consecutive sort_order values at the next row-boundary
// slot (≡ 0 mod 3 after current MAX(sort_order)).

if ($sub === 'trigram' && $method === 'POST') {
    require_once __DIR__ . '/trigram.php';

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $pid1        = (int)($body['post_id_1']   ?? 0);
    $pid2        = (int)($body['post_id_2']   ?? 0);
    $pid3        = (int)($body['post_id_3']   ?? 0);
    $orientation = trim($body['orientation']  ?? 'h');

    if (!$pid1 || !$pid2 || !$pid3) {
        uz_error(400, 'post_id_1, post_id_2, and post_id_3 are all required.');
    }
    if (!in_array($orientation, ['h', 'v'], true)) {
        $orientation = 'h';
    }

    // Validate all three posts exist and belong to this install.
    $ph    = '?,?,?';
    $check = $pdo->prepare("SELECT id FROM snap_posts WHERE id IN ($ph)");
    $check->execute([$pid1, $pid2, $pid3]);
    $found = $check->fetchAll(PDO::FETCH_COLUMN);
    if (count($found) !== 3) {
        uz_error(404, 'One or more post IDs not found.');
    }

    // Guard: reject if any post already belongs to a trigram (prevents duplicate rows).
    $dup = $pdo->prepare("SELECT id FROM snap_posts WHERE id IN ($ph) AND trigram_id IS NOT NULL LIMIT 1");
    $dup->execute([$pid1, $pid2, $pid3]);
    if ($dup->fetch()) {
        uz_error(409, 'One or more posts are already part of a trigram.');
    }

    // Defensive schema guard — trigram_type column may not exist yet.
    $pdo->exec("ALTER TABLE snap_trigrams
        ADD COLUMN IF NOT EXISTS trigram_type ENUM('slice','group') NOT NULL DEFAULT 'slice'
        COMMENT 'slice=GD/Imagick cut; group=pre-sliced external import' AFTER id");
    $pdo->exec("ALTER TABLE snap_trigrams MODIFY source_path VARCHAR(500) NULL");
    $pdo->exec("ALTER TABLE snap_trigrams MODIFY cut_a SMALLINT UNSIGNED NULL");
    $pdo->exec("ALTER TABLE snap_trigrams MODIFY cut_b SMALLINT UNSIGNED NULL");
    $pdo->beginTransaction();
    try {
        // Create the trigram record (group type — no source_path or cut points).
        $pdo->prepare("
            INSERT INTO snap_trigrams
                (trigram_type, source_path, orientation, cut_a, cut_b, post_id_1, post_id_2, post_id_3)
            VALUES ('group', NULL, ?, NULL, NULL, ?, ?, ?)
        ")->execute([$orientation, $pid1, $pid2, $pid3]);

        $trigram_id = (int)$pdo->lastInsertId();

        // Link all three posts to the trigram.
        $upd = $pdo->prepare("UPDATE snap_posts SET trigram_id = ? WHERE id = ?");
        $upd->execute([$trigram_id, $pid1]);
        $upd->execute([$trigram_id, $pid2]);
        $upd->execute([$trigram_id, $pid3]);

        // Assign consecutive sort_order values at the next row-boundary slot.
        // sort_order is 1-indexed; row starts are 1, 4, 7, 10... (≡ 1 mod 3).
        $max_so     = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM snap_posts")->fetchColumn();
        $col_offset = (1 - ($max_so % 3) + 3) % 3;
        $start      = $max_so + ($col_offset === 0 ? 3 : $col_offset);

        $so_stmt = $pdo->prepare("UPDATE snap_posts SET sort_order = ? WHERE id = ?");
        $so_stmt->execute([$start,     $pid1]);
        $so_stmt->execute([$start + 1, $pid2]);
        $so_stmt->execute([$start + 2, $pid3]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        uz_error(500, 'Trigram creation failed: ' . $e->getMessage());
    }

    uz_ok([
        'trigram_id' => $trigram_id,
        'post_id_1'  => $pid1,
        'post_id_2'  => $pid2,
        'post_id_3'  => $pid3,
    ]);
}

uz_error(404, 'Unknown unzucker route.');
// ===== SNAPSMACK EOF =====
