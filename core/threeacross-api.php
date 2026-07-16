<?php
/**
 * SNAPSMACK - Shared Carousel-Write API (Unzucker + SMACK YOUR BATCH UP)
 *
 * Authenticated JSON API for the two GRAMOFSMACK desktop tools that write
 * carousel/trigram content: the Unzucker Instagram→SnapSmack importer and the
 * SMACK YOUR BATCH UP offline poster (BATCH, PLEASE). ONE interface, not two — same
 * auth, carousel mode-lock, thumb-saving and snap_posts/snap_post_images
 * writes — deliberately shared to keep the file count and attack surface down.
 *
 * One lock, per-tool keys (Bearer, issued from smack-api-keys.php). Each key is
 * scoped to its own route family in the auth gate below, so a leaked key from
 * one tool cannot drive the other's routes:
 *   'unzucker' key → IG bulk-import routes (upload/posts) + shared reads/trigram
 *   'sybu'     key → SMACK YOUR BATCH UP authoring routes (gram/*)   + shared reads/trigram
 *
 * NOTE: routes are 'threeacross/*'. The legacy 'unzucker/*' prefix still works
 * as a backward-compat alias (api.php dispatches both here) so already-deployed
 * Unzucker builds keep posting until rebuilt; it will be deprecated and dropped.
 *
 * Routes (via api.php?route=threeacross/...):
 *   GET    threeacross/ping        — connection test             (either key)
 *   GET    threeacross/site        — categories + albums          (either key)
 *   POST   threeacross/upload      — IG import: upload one image   (unzucker)
 *   POST   threeacross/posts       — IG import: create post(s)     (unzucker)
 *   POST   threeacross/trigram     — link 3 posts into a trigram   (either key)
 *   POST   threeacross/gram/upload — SMACK YOUR BATCH UP: image + thumbs    (sybu)
 *   POST   threeacross/gram/post   — SMACK YOUR BATCH UP: create gram post  (sybu)
 *   GET    threeacross/gram/verify — SMACK YOUR BATCH UP: confirm a post    (sybu)
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

function unzucker_auth(PDO $pdo, array $allowed_types = ['unzucker']): bool {
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
    // One lock, per-tool keys: this shared carousel-write API is reached by the
    // Unzucker IG importer ('unzucker' key) AND the SMACK YOUR BATCH UP offline poster
    // ('sybu' key). The caller passes the key_type(s) valid for the requested
    // route family, so each tool's key is scoped to its own doors.
    $allowed_types = array_values(array_filter($allowed_types, 'strlen')) ?: ['unzucker'];
    $place = implode(',', array_fill(0, count($allowed_types), '?'));
    $hash  = hash('sha256', $m[1]);
    // Expiry-aware (mandatory ≤4-week keys, 0.7.263); fall back without the
    // expiry clause if the column predates the schema sync, so tools keep
    // working until it lands.
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM snap_ohsnap_keys
            WHERE key_hash = ? AND is_active = 1 AND key_type IN ($place)
              AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ");
        $stmt->execute(array_merge([$hash], $allowed_types));
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        $stmt = $pdo->prepare("
            SELECT id FROM snap_ohsnap_keys
            WHERE key_hash = ? AND is_active = 1 AND key_type IN ($place)
            LIMIT 1
        ");
        $stmt->execute(array_merge([$hash], $allowed_types));
        $row = $stmt->fetch();
    }
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

// SECAUDIT 2026-06-25 Findings 1+4: server-side hourly image budget for the
// SON OF A BATCH authoring routes — the real flood/storage bound (the ~50
// client cap is only a UI nicety). Generous enough for legit field batches,
// low enough to stop a runaway client or a leaked key. Exits 429 on exceed.
function uz_authoring_budget(PDO $pdo, int $add): void {
    $cap    = 300;   // images per rolling hour across gram/upload + gram/post
    $window = 3600;
    $now    = time();
    $start  = (int)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='gram_authoring_win_start' LIMIT 1")->fetchColumn() ?: 0);
    $count  = (int)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='gram_authoring_win_count' LIMIT 1")->fetchColumn() ?: 0);
    if ($now - $start > $window) { $start = $now; $count = 0; }
    if ($count + $add > $cap) {
        uz_error(429, "Offline-posting rate limit reached ({$cap} images/hour). Try again shortly.");
    }
    $count += $add;
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('gram_authoring_win_start', ?)
                   ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")->execute([(string)$start]);
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('gram_authoring_win_count', ?)
                   ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)")->execute([(string)$count]);
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
// Route parsing — done before auth so each key is scoped to its route family.
// ---------------------------------------------------------------------------

$route  = $_GET['route'] ?? '';
$sub    = preg_replace('#^(?:threeacross|unzucker)/?#', '', $route);
$method = $_SERVER['REQUEST_METHOD'];

// ---------------------------------------------------------------------------
// Gate auth — one lock, per-tool keys (see unzucker_auth)
//
// Shared reads (ping/site) and the trigram linking step both tools use accept
// either key. The SMACK YOUR BATCH UP offline poster (BATCH, PLEASE) drives the SON OF
// A BATCH authoring routes (gram/*) with its 'sybu' key. The Unzucker IG
// bulk-import "data bazooka" (upload/posts) stays 'unzucker'-only. Anything
// else defaults to 'unzucker'.
// ---------------------------------------------------------------------------

$uz_shared_subs = ['ping', 'site', 'trigram'];          // either tool's key
$uz_sob_subs    = ['gram/upload', 'gram/post', 'gram/verify']; // SMACK YOUR BATCH UP only

if (in_array($sub, $uz_shared_subs, true)) {
    $uz_allowed_key_types = ['unzucker', 'sybu'];
} elseif (in_array($sub, $uz_sob_subs, true)) {
    $uz_allowed_key_types = ['sybu'];
} else {
    $uz_allowed_key_types = ['unzucker'];
}

if (!unzucker_auth($pdo, $uz_allowed_key_types)) {
    uz_error(401, 'Invalid or missing API key.');
}

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

// SON OF A BATCH (BATCH, PLEASE) authoring routes are deliberate single-post
// composition / edits from the offline poster — NOT the Instagram "data
// bazooka" bulk import. They keep the GRAMOFSMACK mode lock (carousel only) but
// are exempt from the non-empty-site import lock, so a photographer can post a
// new batch to an established gram site from a coffee shop without first opening
// an admin import window. The Instagram import path (posts/upload/trigram) is
// unchanged and still fully guarded.
$uz_authoring_subs = ['gram/upload', 'gram/post'];
$uz_is_authoring   = in_array($sub, $uz_authoring_subs, true);

// SECAUDIT 2026-06-25 Finding 1 remediation: authoring is no longer a blanket
// exemption from owner authorization. An established site (>5 items) must have
// the owner enable offline posting ONCE (Admin → API Keys, password+2FA) — a
// persistent consent gate, not the per-session friction Sean rejected. Empty/
// new sites stay free. A server-side hourly volume budget (uz_authoring_budget)
// caps flooding even when enabled — the ~50 client cap is UX, not the control.
$uz_authoring_on = ((string)($pdo->query(
    "SELECT setting_val FROM snap_settings WHERE setting_key='gram_authoring_enabled' LIMIT 1"
)->fetchColumn() ?: '0')) === '1';

if ($method === 'POST') {
    if ($uz_site_mode !== 'carousel') {
        uz_error(409, "GRAMOFSMACK (carousel) installs only. This site is '{$uz_site_mode}' — no bueno.");
    }
    if ($uz_is_authoring) {
        // Owner-consent gate for offline posting onto an established site.
        if ($uz_content > 5 && !$uz_authoring_on) {
            uz_error(403, "Offline posting is not enabled for this site. Turn it on in Admin → API Keys (requires your password and 2FA).");
        }
        // Per-request volume budget is enforced inside each authoring route.
    } else {
        // Non-empty-site lock for the bulk IMPORT path (unchanged). The threshold
        // measures content that existed BEFORE this import, so a clean site lets
        // its own uploads through and every accepted write slides the window.
        if (!$uz_import_authorized && $uz_content > 5) {
            uz_error(403, "This site already holds {$uz_content} items. To import into a site that is not empty, authorize the import on the admin API Keys page first.");
        }
        $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('import_authorized_until', ?)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
        )->execute([(string)(time() + 3600)]);
        $uz_import_authorized = true;
    }
}

// ---------------------------------------------------------------------------
// GET threeacross/ping
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
// GET threeacross/site
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
// POST threeacross/posts/check  — bulk existence check by ig_id
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
// POST threeacross/posts
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
        // SECAUDIT 2026-06-25 Finding 2: block directory traversal here too.
        // (Files arrive out-of-band via FTP, so a lexical check — no realpath —
        // is the right guard: relative, no '..', no NUL.)
        if ($img_path[0] === '/' || strpos($img_path, '..') !== false || strpos($img_path, "\0") !== false) {
            uz_error(400, "images[$seq].path is not allowed.");
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
        // uploaded JPEG is already on disk (saved by threeacross/upload). Without
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
// POST threeacross/upload
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
// POST threeacross/trigram
// ---------------------------------------------------------------------------
// Second call in the two-call trigram import flow.  Called after all three
// posts have been created via POST threeacross/posts.
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

// ===========================================================================
// SON OF A BATCH — BATCH, PLEASE authoring routes
// ===========================================================================
// Native offline GRAMOFSMACK poster. Mirrors smack-post-gram.php's controls
// EXACTLY (per-image crop/layout in snap_post_images) and accepts client-side
// 400² + 400px thumbs so the shared host skips its GD pass. Exempt from the
// import bazooka (see $uz_is_authoring) but still carousel-mode locked.

// ---------------------------------------------------------------------------
// POST threeacross/gram/upload — full image + optional client thumbs
// ---------------------------------------------------------------------------

if ($sub === 'gram/upload' && $method === 'POST') {
    if (empty($_FILES['image'])) {
        uz_error(400, 'No file uploaded. Expected multipart field: image');
    }
    uz_authoring_budget($pdo, 1);  // SECAUDIT Findings 1+4 — hourly image budget
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    // Originals may be JPEG, PNG or WebP — parity with smack-post-solo.php, which
    // already decodes all three. Thumbnails stay strict JPEG: the client
    // thumbnailer (snap_thumbs) always emits JPEG, and gram/post's $valid_thumb
    // only trusts t_/a_*.jpe?g paths, so thumbs MUST be named .jpg regardless of
    // the original's extension.
    $IMG_MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    $save_strict_jpeg = function (array $file, string $dest_dir, string $name) use ($finfo): string {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            uz_error(400, 'Upload error code ' . $file['error']);
        }
        if ($finfo->file($file['tmp_name']) !== 'image/jpeg') {
            uz_error(400, 'Thumbnails must be JPEG.');
        }
        if ($file['size'] > 20 * 1024 * 1024) {
            uz_error(400, 'File too large (max 20 MB).');
        }
        $dest = $dest_dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            uz_error(500, 'Failed to save ' . $name . '. Check img_uploads/ permissions.');
        }
        return $dest;
    };

    // Validate the original and pin its stored extension to the detected content
    // type (never trust the client-supplied extension).
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        uz_error(400, 'Upload error code ' . $_FILES['image']['error']);
    }
    $orig_mime = $finfo->file($_FILES['image']['tmp_name']);
    if (!isset($IMG_MIME_EXT[$orig_mime])) {
        uz_error(400, 'Only JPEG, PNG or WebP images are accepted.');
    }
    if ($_FILES['image']['size'] > 20 * 1024 * 1024) {
        uz_error(400, 'File too large (max 20 MB).');
    }
    $orig_ext = $IMG_MIME_EXT[$orig_mime];

    // Sanitise just the client filename stem; force the real extension on.
    $stem = preg_replace('/[^a-z0-9_.-]/', '', strtolower(basename($_FILES['image']['name'])));
    $stem = preg_replace('/\.(jpe?g|png|webp)$/', '', $stem);
    if ($stem === '' || strlen($stem) > 100) {
        $stem = date('YmdHis') . '_' . bin2hex(random_bytes(4));
    }
    $filename = $stem . '.' . $orig_ext;

    $year_month = date('Y/m');
    $site_root  = dirname(__DIR__);
    $dest_dir   = $site_root . '/img_uploads/' . $year_month;
    $thumb_dir  = $dest_dir . '/thumbs';
    if (!is_dir($dest_dir))  mkdir($dest_dir, 0755, true);
    if (!is_dir($thumb_dir)) mkdir($thumb_dir, 0755, true);

    if (file_exists($dest_dir . '/' . $filename)) {
        $filename = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $orig_ext;
    }
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest_dir . '/' . $filename)) {
        uz_error(500, 'Failed to save the image. Check img_uploads/ permissions.');
    }
    $rel_image  = 'img_uploads/' . $year_month . '/' . $filename;
    // Thumbs are always t_/a_<stem>.jpg so they match gram/post's $valid_thumb
    // pattern even when the original is .png/.webp.
    $thumb_stem = pathinfo($filename, PATHINFO_FILENAME);

    $resp = ['status' => 'ok', 'path' => $rel_image,
             'thumb_square' => '', 'thumb_aspect' => ''];

    // Client thumbs are mandatory in the tool; save them under the t_/a_ naming
    // the skins expect so the server can skip its own GD pass entirely.
    if (!empty($_FILES['thumb_square'])) {
        $save_strict_jpeg($_FILES['thumb_square'], $thumb_dir, 't_' . $thumb_stem . '.jpg');
        $resp['thumb_square'] = 'img_uploads/' . $year_month . '/thumbs/t_' . $thumb_stem . '.jpg';
    }
    if (!empty($_FILES['thumb_aspect'])) {
        $save_strict_jpeg($_FILES['thumb_aspect'], $thumb_dir, 'a_' . $thumb_stem . '.jpg');
        $resp['thumb_aspect'] = 'img_uploads/' . $year_month . '/thumbs/a_' . $thumb_stem . '.jpg';
    }

    $dim = @getimagesize($dest_dir . '/' . $filename);
    $resp['width']  = $dim ? (int)$dim[0] : 0;
    $resp['height'] = $dim ? (int)$dim[1] : 0;

    uz_ok($resp);
}

// ---------------------------------------------------------------------------
// POST threeacross/gram/post — create a gram post with full per-image controls
// ---------------------------------------------------------------------------

if ($sub === 'gram/post' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $title      = trim($body['title']      ?? '');
    $desc       = trim($body['body']       ?? '');
    $post_date  = trim($body['post_date']  ?? '');
    $images     = $body['images']          ?? [];
    $tags       = $body['tags']            ?? [];
    $status     = ($body['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
    $allow_cmt  = !empty($body['allow_comments']) ? 1 : 0;
    $allow_dl   = !empty($body['allow_download']) ? 1 : 0;
    $dl_url     = substr(trim($body['download_url'] ?? ''), 0, 512);
    $pano_rows  = max(1, min(3, (int)($body['panorama_rows'] ?? 1)));
    $want_type  = $body['post_type'] ?? '';
    // Post-level frame defaults.
    $p_size  = max(10, min(100, (int)($body['post_img_size_pct'] ?? 100)));
    $p_bpx   = max(0,  min(50,  (int)($body['post_border_px']    ?? 0)));
    $p_bcol  = preg_match('/^#[0-9a-fA-F]{6}$/', $body['post_border_color'] ?? '') ? $body['post_border_color'] : '#000000';
    $p_bg    = preg_match('/^#[0-9a-fA-F]{6}$/', $body['post_bg_color']     ?? '') ? $body['post_bg_color']     : '#ffffff';
    $p_shad  = max(0, min(3, (int)($body['post_shadow'] ?? 0)));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $post_date)) {
        $post_date = date('Y-m-d H:i:s');
    }
    if (empty($images) || !is_array($images)) uz_error(400, 'images array is required.');
    // SECAUDIT 2026-06-25 Finding 1: per-call image ceiling (a single compose
    // unit is a carousel <=10 or a trigram chunk; 30 is a safe hard ceiling)
    // plus the rolling hourly budget across authoring routes.
    if (count($images) > 30) uz_error(400, 'Too many images in one post (max 30).');
    uz_authoring_budget($pdo, count($images));

    $tag_string = '';
    if (is_array($tags) && count($tags) > 0) {
        $tag_string = implode(' ', array_map(fn($t) => '#' . ltrim($t, '#'), $tags));
    }

    $site_root = dirname(__DIR__);

    // Per-image control sanitiser → clamped style array matching the web poster.
    $style_of = function (array $im): array {
        $crop = ($im['crop_mode'] ?? 'fit') === 'fill' ? 'fill' : 'fit';
        $fx   = max(0, min(100, (int)($im['focus_x'] ?? 50)));
        $fy   = max(0, min(100, (int)($im['focus_y'] ?? 50)));
        $zoom = max(100, min(300, (int)($im['zoom'] ?? 100)));
        if ($crop === 'fill') {
            // Fill ignores the frame controls (matches smack-post-gram.php).
            return ['crop' => 'fill', 'size' => 100, 'bpx' => 0,
                    'bcol' => '#000000', 'bg' => '#ffffff', 'shadow' => 0,
                    'fx' => $fx, 'fy' => $fy, 'zoom' => $zoom];
        }
        return [
            'crop'   => 'fit',
            'size'   => max(10, min(100, (int)($im['size_pct'] ?? 100))),
            'bpx'    => max(0,  min(50,  (int)($im['border_px'] ?? 0))),
            'bcol'   => preg_match('/^#[0-9a-fA-F]{6}$/', $im['border_color'] ?? '') ? $im['border_color'] : '#000000',
            'bg'     => preg_match('/^#[0-9a-fA-F]{6}$/', $im['bg_color'] ?? '') ? $im['bg_color'] : '#ffffff',
            'shadow' => max(0, min(3, (int)($im['shadow'] ?? 0))),
            'fx' => $fx, 'fy' => $fy, 'zoom' => $zoom,
        ];
    };

    // Trust a client thumb only if its path is shaped like one we'd have saved
    // (img_uploads/YYYY/MM/thumbs/t_*.jpg or a_*.jpg) AND the file is on disk.
    // Anything else falls back to a server GD pass — never stored blindly.
    $valid_thumb = function ($p) use ($site_root): string {
        $p = trim((string)$p);
        if ($p === '' || strlen($p) > 500) return '';
        if (!preg_match('#^img_uploads/[0-9]{4}/[0-9]{2}/thumbs/[ta]_[A-Za-z0-9_.\-]+\.jpe?g$#', $p)) return '';
        return is_file($site_root . '/' . $p) ? $p : '';
    };

    $pdo->beginTransaction();
    try {
        // 1) Create every snap_images row, carrying its style for the pivot.
        $img_ins = $pdo->prepare("
            INSERT INTO snap_images (
                img_slug, img_file, img_title, img_description,
                img_date, img_width, img_height, img_orientation,
                img_thumb_square, img_thumb_aspect,
                img_source_file, img_status, sort_order,
                allow_comments, allow_download, download_url
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $built = [];  // each: ['image_id'=>, 'style'=>, 'split'=>bool, 'pos'=>]
        foreach ($images as $seq => $im) {
            $path = trim($im['path'] ?? '');
            if ($path === '' || strlen($path) > 500) uz_error(400, "images[$seq].path invalid.");
            if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                uz_error(400, "images[$seq].path must be a .jpg/.jpeg/.png/.webp file.");
            }
            // SECAUDIT 2026-06-25 Finding 2: block directory traversal. The path
            // must be relative, contain no '..' or NUL, and resolve to a real
            // file under img_uploads/ (it was just uploaded via gram/upload).
            if ($path[0] === '/' || strpos($path, '..') !== false || strpos($path, "\0") !== false) {
                uz_error(400, "images[$seq].path is not allowed.");
            }
            $real = realpath($site_root . '/' . $path);
            $root = realpath($site_root . '/img_uploads');
            if ($real === false || $root === false || strncmp($real, $root . '/', strlen($root) + 1) !== 0) {
                uz_error(400, "images[$seq].path must resolve under img_uploads/.");
            }
            $w = (int)($im['width'] ?? 0);
            $h = (int)($im['height'] ?? 0);
            if ($w < 1 || $h < 1) {
                $dim = @getimagesize($site_root . '/' . ltrim($path, '/'));
                $w = $dim ? (int)$dim[0] : 0;
                $h = $dim ? (int)$dim[1] : 0;
            }
            $orient = ($h > $w) ? 1 : (($w === $h) ? 2 : 0);  // 0=land,1=port,2=square

            $st = $style_of($im);

            // Client thumbs are authoritative when valid (path-checked + on
            // disk) — skip GD; otherwise generate server-side as a fallback.
            $t_sq = $valid_thumb($im['thumb_square'] ?? '');
            $t_as = $valid_thumb($im['thumb_aspect'] ?? '');
            if ($t_sq === '' || $t_as === '') {
                $gen = snapsmack_generate_thumbs($path, $site_root, 400, 400, $st['fx'], $st['fy'], $st['zoom']);
                if ($gen !== false) {
                    $t_sq = $t_sq ?: $gen['sq_path'];
                    $t_as = $t_as ?: $gen['asp_path'];
                }
            }

            $img_slug = uz_unique_img_slug($pdo, uz_slug_base('', $post_date, $seq));
            $img_ins->execute([
                $img_slug, $path, '', $desc,
                $post_date, $w, $h, $orient,
                $t_sq ?: null, $t_as ?: null,
                'sob:' . $img_slug, $status, (int)$seq,
                $allow_cmt, $allow_dl, $dl_url,
            ]);
            $img_id = (int)$pdo->lastInsertId();
            if ($tag_string !== '') snap_sync_tags($pdo, $img_id, $tag_string);

            $built[] = ['image_id' => $img_id, 'style' => $st,
                        'split' => !empty($im['split'])];
        }

        // 2) Partition split-out images (each becomes its own single post),
        //    matching smack-post-gram.php's split behaviour.
        $group   = array_values(array_filter($built, fn($b) => !$b['split']));
        $singles = array_values(array_filter($built, fn($b) =>  $b['split']));

        $pi_ins = $pdo->prepare("
            INSERT INTO snap_post_images
                (post_id, image_id, sort_position, is_cover,
                 img_size_pct, img_border_px, img_border_color, img_bg_color,
                 img_shadow, img_crop_mode, img_focus_x, img_focus_y, img_zoom)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $post_ins = $pdo->prepare("
            INSERT INTO snap_posts
                (title, slug, description, post_type, status, created_at,
                 allow_comments, allow_download, download_url, panorama_rows,
                 post_img_size_pct, post_border_px, post_border_color,
                 post_bg_color, post_shadow)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $make_post = function (array $members, string $ptype, int $rows)
                use ($pdo, $post_ins, $pi_ins, $title, $desc, $status, $post_date,
                     $allow_cmt, $allow_dl, $dl_url, $p_size, $p_bpx, $p_bcol, $p_bg, $p_shad): int {
            $slug = uz_unique_post_slug($pdo, 'sob-' . date('Ymd-His', strtotime($post_date)) . '-' . bin2hex(random_bytes(2)));
            $post_ins->execute([
                $title, $slug, $desc, $ptype, $status, $post_date,
                $allow_cmt, $allow_dl, $dl_url, $rows,
                $p_size, $p_bpx, $p_bcol, $p_bg, $p_shad,
            ]);
            $pid = (int)$pdo->lastInsertId();
            foreach ($members as $pos => $b) {
                $s = $b['style'];
                $pi_ins->execute([
                    $pid, $b['image_id'], $pos, ($pos === 0 ? 1 : 0),
                    $s['size'], $s['bpx'], $s['bcol'], $s['bg'], $s['shadow'],
                    $s['crop'], $s['fx'], $s['fy'], $s['zoom'],
                ]);
                $pdo->prepare("UPDATE snap_images SET post_id = ? WHERE id = ?")->execute([$pid, $b['image_id']]);
            }
            // Newest posts go to the TOP of the feed (Instagram-style). Seat this
            // post at sort_order=1 and shift the rest of the published feed down,
            // instead of leaving it at the default 0 — which the gram feed query
            // demotes to the BOTTOM. Without this every SMACK YOUR BATCH UP batch landed at
            // the end of the feed.
            $pdo->prepare("UPDATE snap_posts SET sort_order = sort_order + 1 WHERE sort_order > 0")->execute();
            $pdo->prepare("UPDATE snap_posts SET sort_order = 1 WHERE id = ?")->execute([$pid]);
            return $pid;
        };

        $main_post_id  = 0;
        $split_post_ids = [];
        if (!empty($group)) {
            $ptype = (count($group) === 1) ? 'single'
                   : (($want_type === 'panorama') ? 'panorama' : 'carousel');
            $main_post_id = $make_post($group, $ptype, $ptype === 'panorama' ? $pano_rows : 1);
        }
        foreach ($singles as $simg) {
            $split_post_ids[] = $make_post([$simg], 'single', 1);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        uz_error(500, 'Gram post failed: ' . $e->getMessage());
    }

    uz_ok([
        'post_id'        => $main_post_id,
        'image_ids'      => array_map(fn($b) => $b['image_id'], $built),
        'split_post_ids' => $split_post_ids,
    ]);
}

// ---------------------------------------------------------------------------
// GET threeacross/gram/verify — confirm a synced post exists (positive verify)
// ---------------------------------------------------------------------------
// BATCH, PLEASE pulls the live post back after a sync and confirms it matches
// the local draft before marking it synced (the SYBU lesson: confirm
// positively, never infer success from no-error).

if ($sub === 'gram/verify' && $method === 'GET') {
    $post_id = (int)($_GET['post_id'] ?? 0);
    if (!$post_id) uz_error(400, 'post_id is required.');

    $stmt = $pdo->prepare("
        SELECT p.id, p.post_type, p.status, p.description, p.trigram_id,
               (SELECT COUNT(*) FROM snap_post_images pi WHERE pi.post_id = p.id) AS image_count
        FROM snap_posts p
        WHERE p.id = ?
        LIMIT 1
    ");
    $stmt->execute([$post_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) uz_error(404, 'Post not found.');

    // SECAUDIT 2026-06-25 Finding 5: return only what positive verification
    // needs (existence + image count). No caption — don't make this a
    // caption-enumeration oracle.
    uz_ok([
        'post_id'     => (int)$row['id'],
        'post_type'   => $row['post_type'],
        'post_status' => $row['status'],
        'image_count' => (int)$row['image_count'],
        'trigram_id'  => $row['trigram_id'] !== null ? (int)$row['trigram_id'] : null,
    ]);
}

uz_error(404, 'Unknown threeacross route.');
// ===== SNAPSMACK EOF =====
