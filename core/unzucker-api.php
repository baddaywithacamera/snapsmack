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
 *   POST   unzucker/posts   — create post from FTP'd image paths
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/snap-tags.php';

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

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

function unzucker_auth(PDO $pdo): bool {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
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
// GET unzucker/ping
// ---------------------------------------------------------------------------

if ($sub === 'ping' && $method === 'GET') {
    $cat_count   = (int)$pdo->query("SELECT COUNT(*) FROM snap_categories")->fetchColumn();
    $album_count = (int)$pdo->query("SELECT COUNT(*) FROM snap_albums")->fetchColumn();
    uz_ok([
        'message'     => 'Connected.',
        'cat_count'   => $cat_count,
        'album_count' => $album_count,
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
// POST unzucker/posts
// ---------------------------------------------------------------------------

if ($sub === 'posts' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

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

        // All Instagram imports are square; img_orientation is INT (0=landscape/square)
        $img_ori = 0;

        $img_source = $ig_id !== '' ? "instagram:{$ig_id}_{$seq}" : '';

        // Per-image duplicate check
        if ($img_source !== '') {
            $dup_img = $pdo->prepare("SELECT id FROM snap_images WHERE img_source_file = ? LIMIT 1");
            $dup_img->execute([$img_source]);
            if ($row = $dup_img->fetch(PDO::FETCH_ASSOC)) {
                $image_ids[] = (int)$row['id'];
                continue;
            }
        }

        $img_slug = uz_unique_img_slug($pdo, uz_slug_base($ig_id, $post_date, $seq));

        $sort_row   = $pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 AS n FROM snap_images")->fetch(PDO::FETCH_ASSOC);
        $sort_order = (int)($sort_row['n'] ?? 1);

        $pdo->prepare("
            INSERT INTO snap_images (
                img_slug, img_file, img_title, img_description,
                img_date, img_width, img_height, img_orientation,
                img_source_file, img_status, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'published', ?)
        ")->execute([
            $img_slug, $img_path, '', $desc,
            $post_date, $img_w, $img_h, $img_ori,
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
        '', $post_slug, $desc, $post_type_final,
        $ig_id ?: null,
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

uz_error(404, 'Unknown unzucker route.');
// ===== SNAPSMACK EOF =====
