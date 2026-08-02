<?php
/**
 * SNAPSMACK — GET YOUR SHIT SORTED API
 *
 * Authenticated REST endpoints for the GYSS desktop photo-sorting tool.
 * All endpoints require a valid Bearer token from snap_ohsnap_keys (key_type = 'gyss').
 *
 * Route structure: gyss/{resource}
 *   GET  gyss/ping           — connection test, returns site vitals
 *   GET  gyss/photos         — filtered photo export with metadata + modified_at
 *   GET  gyss/meta           — categories and albums for filter/edit dropdowns
 *   POST gyss/batch-update   — push a diff of sorted/edited records back to the blog
 *
 * Conflict detection (v0.2):
 *   batch-update accepts optional expected_modified_at per record. If the live
 *   modified_at differs, the record is returned in conflicts[] instead of applied.
 *   Pass force:true per record to skip the check and overwrite explicitly.
 *
 * Scope: SMACKONEOUT (site_mode 1.0 photoblog) only. Operates on snap_images
 *        and snap_image_cat_map. A separate GRAMOFSMACK sorter will be built later.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// --- ENVIRONMENT BOOTSTRAP ---
if (!defined('BASE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
    define('BASE_URL', $protocol . '://' . $_SERVER['HTTP_HOST'] . '/');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/ai-provider.php';
require_once __DIR__ . '/ai-enrichment-prompts.php';
require_once __DIR__ . '/snap-tags.php';

// --- CORS: allow GYSS desktop app (tauri:// and file://) ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (preg_match('#^(file://|tauri://)#', $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- RESPONSE HELPERS ---
function gy_respond(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function gy_ok(array $data = []): void  { gy_respond(array_merge(['ok' => true], $data)); }
function gy_err(string $msg, int $code = 400): void { gy_respond(['ok' => false, 'error' => $msg], $code); }

// --- ROUTE PARSING ---
$parts    = explode('/', trim($GLOBALS['route'] ?? ($_GET['route'] ?? ''), '/'));
$resource = $parts[1] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];

// --- SETTINGS ---
try {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    gy_err('Database unavailable', 503);
}

// --- BEARER TOKEN AUTH (key_type = gyss) ---
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$raw_key     = '';
if (preg_match('/^Bearer\s+(\S+)$/i', $auth_header, $m)) {
    $raw_key = $m[1];
}
if (!$raw_key) {
    gy_err('Authorization header required', 401);
}

$key_hash = hash('sha256', $raw_key);
try {
    $key_stmt = $pdo->prepare("
        SELECT id FROM snap_ohsnap_keys
        WHERE key_hash = ? AND key_type = 'gyss' AND is_active = 1
        LIMIT 1
    ");
    $key_stmt->execute([$key_hash]);
    $api_key_row = $key_stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    gy_err('Database error during auth', 503);
}

if (!$api_key_row) {
    gy_err('Invalid or revoked GYSS API key', 401);
}

// Touch last_used_at
$pdo->prepare("UPDATE snap_ohsnap_keys SET last_used_at = NOW() WHERE id = ?")
    ->execute([$api_key_row['id']]);

// --- HELPERS ---

/**
 * Build an absolute thumb URL for an image file path.
 * Uses aspect thumbnail (a_ prefix). Falls back to full image if thumb missing.
 */
function gy_thumb_url(string $img_file): string {
    if (!$img_file) return '';
    $thumb_rel = ltrim(dirname($img_file) . '/thumbs/a_' . basename($img_file), '/');
    $thumb_abs = dirname(__DIR__) . '/uploads/' . $thumb_rel;
    if (file_exists($thumb_abs)) {
        return BASE_URL . 'uploads/' . $thumb_rel;
    }
    // Fall back to full image
    return BASE_URL . 'uploads/' . ltrim($img_file, '/');
}


// =============================================================================
// ENDPOINT: GET gyss/ping
// Connection test. Returns site vitals.
// =============================================================================
if ($resource === 'ping' && $method === 'GET') {
    gy_ok([
        'site_name' => $settings['site_name']   ?? 'SnapSmack',
        'tagline'   => $settings['site_tagline'] ?? '',
        'version'   => SNAPSMACK_VERSION,
        'base_url'  => BASE_URL,
    ]);
}


// =============================================================================
// ENDPOINT: GET gyss/photos
// Filtered photo export. Returns thumb URLs + editable metadata + modified_at.
//
// Query params:
//   date_from    — ISO date string (optional)
//   date_to      — ISO date string (optional)
//   category_id  — int (optional)
//   album_id     — int (optional)
//   limit        — int, default 200, max 500
//   offset       — int, default 0
// =============================================================================
if ($resource === 'photos' && $method === 'GET') {

    $date_from   = $_GET['date_from']   ?? '';
    $date_to     = $_GET['date_to']     ?? '';
    $category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $album_id    = isset($_GET['album_id'])    ? (int)$_GET['album_id']    : null;
    $limit       = min((int)($_GET['limit']  ?? 200), 500);
    $offset      = max((int)($_GET['offset'] ?? 0), 0);
    if ($limit < 1) $limit = 200;

    // Build WHERE clauses
    $where  = ["i.img_status = 'published'"];
    $params = [];

    if ($date_from) {
        $where[]  = 'i.img_date >= ?';
        $params[] = $date_from . ' 00:00:00';
    }
    if ($date_to) {
        $where[]  = 'i.img_date <= ?';
        $params[] = $date_to . ' 23:59:59';
    }
    if ($category_id !== null) {
        $where[]  = 'EXISTS (SELECT 1 FROM snap_image_cat_map cm WHERE cm.image_id = i.id AND cm.category_id = ?)';
        $params[] = $category_id;
    }
    if ($album_id !== null) {
        $where[]  = 'EXISTS (SELECT 1 FROM snap_image_album_map am WHERE am.image_id = i.id AND am.album_id = ?)';
        $params[] = $album_id;
    }

    $where_sql = implode(' AND ', $where);

    // Count total matching (for pagination info)
    try {
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_images i WHERE $where_sql");
        $count_stmt->execute($params);
        $total = (int)$count_stmt->fetchColumn();
    } catch (Exception $e) {
        gy_err('Database error fetching count', 500);
    }

    // Fetch page
    $params_page   = $params;
    $params_page[] = $limit;
    $params_page[] = $offset;

    try {
        $stmt = $pdo->prepare("
            SELECT
                i.id,
                i.img_title       AS title,
                i.img_description AS description,
                i.sort_order,
                i.img_file,
                i.img_date        AS posted_date,
                i.modified_at,
                (SELECT c2.id       FROM snap_image_cat_map cm2 JOIN snap_categories c2 ON c2.id = cm2.category_id WHERE cm2.image_id = i.id LIMIT 1) AS category_id,
                (SELECT c2.cat_name FROM snap_image_cat_map cm2 JOIN snap_categories c2 ON c2.id = cm2.category_id WHERE cm2.image_id = i.id LIMIT 1) AS category_name,
                (SELECT a2.id         FROM snap_image_album_map am2 JOIN snap_albums a2 ON a2.id = am2.album_id WHERE am2.image_id = i.id LIMIT 1) AS album_id,
                (SELECT a2.album_name FROM snap_image_album_map am2 JOIN snap_albums a2 ON a2.id = am2.album_id WHERE am2.image_id = i.id LIMIT 1) AS album_name
            FROM snap_images i
            WHERE $where_sql
            ORDER BY i.sort_order ASC, i.id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params_page);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        gy_err('Database error fetching photos', 500);
    }

    $photos = [];
    foreach ($rows as $row) {
        $photos[] = [
            'id'            => (int)$row['id'],
            'title'         => $row['title'],
            'description'   => $row['description'],
            'sort_order'    => (int)$row['sort_order'],
            'posted_date'   => $row['posted_date'],
            'modified_at'   => $row['modified_at'],
            'category_id'   => $row['category_id'] !== null ? (int)$row['category_id'] : null,
            'category_name' => $row['category_name'],
            'album_id'      => $row['album_id'] !== null ? (int)$row['album_id'] : null,
            'album_name'    => $row['album_name'],
            'filename'      => basename((string)$row['img_file']),
            'thumb_url'     => gy_thumb_url($row['img_file']),
        ];
    }

    gy_ok(['total' => $total, 'photos' => $photos]);
}


// =============================================================================
// ENDPOINT: GET gyss/meta
// Returns categories and albums for filter/edit dropdowns.
// =============================================================================
if ($resource === 'meta' && $method === 'GET') {
    try {
        $cats = $pdo->query("
            SELECT c.id, c.cat_name AS name, COUNT(cm.image_id) AS `count`
            FROM snap_categories c
            LEFT JOIN snap_image_cat_map cm ON cm.category_id = c.id
            GROUP BY c.id, c.cat_name
            ORDER BY c.cat_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $albums = $pdo->query("
            SELECT a.id, a.album_name AS name, COUNT(am.image_id) AS `count`
            FROM snap_albums a
            LEFT JOIN snap_image_album_map am ON am.album_id = a.id
            GROUP BY a.id, a.album_name
            ORDER BY a.album_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        gy_err('Database error fetching meta', 500);
    }

    // Cast count to int
    foreach ($cats   as &$c) { $c['id'] = (int)$c['id']; $c['count'] = (int)$c['count']; }
    foreach ($albums as &$a) { $a['id'] = (int)$a['id']; $a['count'] = (int)$a['count']; }
    unset($c, $a);

    gy_ok(['categories' => $cats, 'albums' => $albums]);
}

// =============================================================================
// ENDPOINT: GET gyss/enrichment-audit
// Read-only list of published photos with missing metadata. GYSS owns iteration;
// the server never runs a long batch.
// =============================================================================
if ($resource === 'enrichment-audit' && $method === 'GET') {
    $limit = min(max((int)($_GET['limit'] ?? 500), 1), 1000);
    $rows = $pdo->query("
        SELECT i.id, i.img_title, i.img_description, i.img_file, i.img_display_options,
               (SELECT COUNT(*) FROM snap_image_tags it WHERE it.image_id = i.id) AS tag_count,
               (SELECT COUNT(*) FROM snap_image_cat_map cm WHERE cm.image_id = i.id) AS cat_count,
               (SELECT COUNT(*) FROM snap_image_album_map am WHERE am.image_id = i.id) AS album_count
        FROM snap_images i
        WHERE i.img_status = 'published'
        ORDER BY i.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $row) {
        $missing = [];
        if (trim((string)$row['img_title']) === '')       $missing[] = 'title';
        if (trim((string)$row['img_description']) === '') $missing[] = 'caption';
        if ((int)$row['tag_count'] === 0)                 $missing[] = 'tags';
        if ((int)$row['cat_count'] === 0)                 $missing[] = 'category';
        if ((int)$row['album_count'] === 0)               $missing[] = 'album';
        $display = json_decode((string)($row['img_display_options'] ?? ''), true);
        if (empty($display['ai_colors']))                   $missing[] = 'colors';
        if (!$missing) continue;
        $items[] = [
            'id'        => (int)$row['id'],
            'title'     => (string)$row['img_title'],
            'thumb_url' => gy_thumb_url((string)$row['img_file']),
            'missing'   => $missing,
        ];
        if (count($items) >= $limit) break;
    }
    gy_ok([
        'total'   => count($items),
        'items'   => $items,
        'prompt'  => snap_ai_post_enrichment_prompt($pdo),
        'ai_ready'=> snap_ai_active(),
    ]);
}

// =============================================================================
// ENDPOINT: POST gyss/enrich-one
// One image per request by design. The desktop app owns queueing and resume.
// =============================================================================
if ($resource === 'enrich-one' && $method === 'POST') {
    $data = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($data)) gy_err('JSON request body required');
    $id = (int)($data['id'] ?? 0);
    if ($id <= 0) gy_err('Valid image id required');
    if (!snap_ai_active()) gy_err(
        snap_ai_status() === 'expired' ? snap_ai_expired_message() : 'AI is not configured on this site.',
        409
    );

    $allowed_fields = ['title', 'caption', 'tags', 'category', 'album', 'colors'];
    $fields = array_values(array_intersect(
        $allowed_fields,
        is_array($data['fields'] ?? null) ? $data['fields'] : $allowed_fields
    ));
    if (!$fields) gy_err('Select at least one metadata field.');
    $overwrite = !empty($data['overwrite']);
    $prompt = mb_substr(trim((string)($data['prompt'] ?? '')), 0, 12000);
    if ($prompt === '') $prompt = snap_ai_post_enrichment_prompt($pdo);

    $stmt = $pdo->prepare("SELECT * FROM snap_images WHERE id = ? AND img_status = 'published' LIMIT 1");
    $stmt->execute([$id]);
    $image = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$image) gy_err('Published image not found.', 404);

    $relative = ltrim((string)$image['img_file'], '/\\');
    $path = dirname(__DIR__) . '/uploads/' . $relative;
    if (!is_file($path) || !is_readable($path)) gy_err('Image file is missing or unreadable.', 404);
    if (filesize($path) > 20 * 1024 * 1024) gy_err('Image is larger than the 20 MB enrichment limit.', 413);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'image/jpeg';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        gy_err('Unsupported image type for enrichment.', 415);
    }

    $cats = $pdo->query("SELECT id, cat_name AS name FROM snap_categories ORDER BY cat_name")->fetchAll(PDO::FETCH_ASSOC);
    $albums = $pdo->query("SELECT id, album_name AS name FROM snap_albums ORDER BY album_name")->fetchAll(PDO::FETCH_ASSOC);
    $options = "AVAILABLE CATEGORIES (exact names only):\n"
             . implode("\n", array_map(fn($r) => '- ' . $r['name'], $cats))
             . "\n\nAVAILABLE ALBUMS (exact names only):\n"
             . implode("\n", array_map(fn($r) => '- ' . $r['name'], $albums));

    $result = snap_ai_vision(
        $prompt,
        $options,
        [['mime' => $mime, 'data' => base64_encode((string)file_get_contents($path))]],
        1024
    );
    if (!$result['ok']) gy_err($result['error'] ?: 'AI enrichment failed.', 502);

    $parsed = ['title'=>'', 'caption'=>'', 'tags'=>'', 'category'=>'', 'album'=>'', 'colors'=>''];
    foreach (preg_split('/\R/', trim($result['text'])) as $line) {
        if (preg_match('/^(TITLE|CAPTION|TAGS|CATEGORY|ALBUM|COLORS):\s*(.*)$/i', trim($line), $m)) {
            $parsed[strtolower($m[1])] = trim($m[2]);
        }
    }
    if (!$parsed['title'] && !$parsed['caption'] && !$parsed['tags']) {
        gy_err('AI returned an unreadable metadata response.', 502);
    }

    $current_tags = snap_get_tags($pdo, $id);
    $has_tags = !empty($current_tags);
    $cat_count = (int)$pdo->query("SELECT COUNT(*) FROM snap_image_cat_map WHERE image_id = " . $id)->fetchColumn();
    $album_count = (int)$pdo->query("SELECT COUNT(*) FROM snap_image_album_map WHERE image_id = " . $id)->fetchColumn();
    $applied = [];

    $pdo->beginTransaction();
    try {
        $title = (string)$image['img_title'];
        $caption = (string)$image['img_description'];
        if (in_array('title', $fields, true) && ($overwrite || trim($title) === '') && $parsed['title'] !== '') {
            $title = mb_substr($parsed['title'], 0, 255);
            $applied[] = 'title';
        }
        if (in_array('caption', $fields, true) && ($overwrite || trim($caption) === '') && $parsed['caption'] !== '') {
            $caption = mb_substr($parsed['caption'], 0, 20000);
            $applied[] = 'caption';
        }
        $display_options = json_decode((string)($image['img_display_options'] ?? ''), true);
        if (!is_array($display_options)) $display_options = [];
        $colors = !empty($display_options['ai_colors'])
            ? implode(' ', (array)$display_options['ai_colors'])
            : '';
        if (in_array('colors', $fields, true) && ($overwrite || trim($colors) === '') && $parsed['colors'] !== '') {
            preg_match_all('/#[0-9A-Fa-f]{6}/', $parsed['colors'], $color_matches);
            $colors = implode(' ', array_slice(array_map('strtoupper', $color_matches[0] ?? []), 0, 3));
            if ($colors !== '') {
                $display_options['ai_colors'] = explode(' ', $colors);
                $applied[] = 'colors';
            }
        }
        $display_json = $display_options ? json_encode($display_options, JSON_UNESCAPED_SLASHES) : null;
        $pdo->prepare("UPDATE snap_images SET img_title = ?, img_description = ?, img_display_options = ? WHERE id = ?")
            ->execute([$title, $caption, $display_json, $id]);

        $tags_applied = false;
        if (in_array('tags', $fields, true) && ($overwrite || !$has_tags) && $parsed['tags'] !== '') {
            snap_sync_tags($pdo, $id, $title . ' ' . $caption . ' ' . $parsed['tags'] . ' ' . $colors);
            $applied[] = 'tags';
            $tags_applied = true;
        }
        if (!$tags_applied && in_array('colors', $applied, true) && $colors !== '') {
            $existing_tag_text = implode(' ', array_map(
                static fn($tag) => '#' . $tag['slug'],
                $current_tags
            ));
            snap_sync_tags($pdo, $id, $existing_tag_text . ' ' . $colors);
        }

        $apply_names = static function (string $raw, array $options): array {
            $wanted = array_filter(array_map('trim', explode(',', $raw)));
            $ids = [];
            foreach ($options as $option) {
                foreach ($wanted as $name) {
                    if (mb_strtolower($name) === mb_strtolower((string)$option['name'])) {
                        $ids[] = (int)$option['id'];
                    }
                }
            }
            return array_values(array_unique($ids));
        };
        if (in_array('category', $fields, true) && ($overwrite || $cat_count === 0)) {
            $ids = $apply_names($parsed['category'], $cats);
            if ($ids) {
                if ($overwrite) $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id = ?")->execute([$id]);
                $ins = $pdo->prepare("INSERT IGNORE INTO snap_image_cat_map (image_id, category_id) VALUES (?, ?)");
                foreach ($ids as $option_id) $ins->execute([$id, $option_id]);
                $applied[] = 'category';
            }
        }
        if (in_array('album', $fields, true) && ($overwrite || $album_count === 0)) {
            $ids = $apply_names($parsed['album'], $albums);
            if ($ids) {
                if ($overwrite) $pdo->prepare("DELETE FROM snap_image_album_map WHERE image_id = ?")->execute([$id]);
                $ins = $pdo->prepare("INSERT IGNORE INTO snap_image_album_map (image_id, album_id) VALUES (?, ?)");
                foreach ($ids as $option_id) $ins->execute([$id, $option_id]);
                $applied[] = 'album';
            }
        }
        $pdo->prepare(
            "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('ai_post_enrichment_prompt', ?)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
        )->execute([$prompt]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        gy_err('Could not save enrichment: ' . $e->getMessage(), 500);
    }
    gy_ok(['id' => $id, 'applied' => $applied, 'metadata' => $parsed]);
}


// =============================================================================
// ENDPOINT: POST gyss/batch-update
// Push sorted/edited records back to the blog.
//
// Request body (JSON):
//   { "updates": [ { "id": int, "sort_order"?: int, "title"?: str,
//                    "description"?: str, "category_id"?: int,
//                    "expected_modified_at"?: str, "force"?: bool } ] }
//
// Response:
//   { ok, applied: int, failed: [{id, error}], conflicts: [{id, ...}] }
// =============================================================================
if ($resource === 'batch-update' && $method === 'POST') {

    $body = file_get_contents('php://input');
    $data = json_decode($body, true);

    if (!is_array($data) || !isset($data['updates']) || !is_array($data['updates'])) {
        gy_err('Request body must be JSON with an "updates" array', 400);
    }

    $updates = $data['updates'];
    if (count($updates) === 0) {
        gy_ok(['applied' => 0, 'failed' => [], 'conflicts' => []]);
    }
    if (count($updates) > 500) {
        gy_err('Maximum 500 updates per request', 400);
    }

    $applied   = 0;
    $failed    = [];
    $conflicts = [];
    $sort_touched = false;   // did any successful update carry a new sort_order?

    foreach ($updates as $upd) {
        $id = isset($upd['id']) ? (int)$upd['id'] : 0;
        if ($id <= 0) {
            $failed[] = ['id' => $id, 'error' => 'Invalid or missing id'];
            continue;
        }

        // Fetch current row
        try {
            $row_stmt = $pdo->prepare("
                SELECT i2.id, i2.img_title AS title, i2.img_description AS description,
                       i2.sort_order, i2.modified_at,
                       (SELECT cm3.category_id FROM snap_image_cat_map cm3 WHERE cm3.image_id = i2.id LIMIT 1) AS category_id
                FROM snap_images i2 WHERE i2.id = ? LIMIT 1
            ");
            $row_stmt->execute([$id]);
            $current = $row_stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $failed[] = ['id' => $id, 'error' => 'Database error'];
            continue;
        }

        if (!$current) {
            $failed[] = ['id' => $id, 'error' => 'Image not found'];
            continue;
        }

        // Conflict detection: check expected_modified_at unless force is set
        $force    = !empty($upd['force']);
        $expected = $upd['expected_modified_at'] ?? null;

        if (!$force && $expected !== null) {
            // Normalise both to comparable strings (strip microseconds if any)
            $exp_ts  = strtotime($expected);
            $live_ts = strtotime($current['modified_at']);
            if ($exp_ts !== false && $live_ts !== false && $exp_ts !== $live_ts) {
                // Collect "mine" from the update request
                $mine = ['sort_order' => (int)($upd['sort_order'] ?? $current['sort_order'])];
                if (isset($upd['title']))       $mine['title']       = $upd['title'];
                if (isset($upd['description'])) $mine['description'] = $upd['description'];
                if (isset($upd['category_id'])) $mine['category_id'] = (int)$upd['category_id'];

                // "theirs" = current live values
                $theirs = [
                    'title'       => $current['title'],
                    'description' => $current['description'],
                    'sort_order'  => (int)$current['sort_order'],
                    'category_id' => $current['category_id'] !== null ? (int)$current['category_id'] : null,
                ];

                $conflicts[] = [
                    'id'                   => $id,
                    'expected_modified_at' => $expected,
                    'current_modified_at'  => $current['modified_at'],
                    'mine'                 => $mine,
                    'theirs'               => $theirs,
                ];
                continue;
            }
        }

        // Build UPDATE for snap_images
        $set_parts  = [];
        $set_params = [];

        if (isset($upd['sort_order'])) {
            $set_parts[]  = 'sort_order = ?';
            $set_params[] = (int)$upd['sort_order'];
        }
        if (isset($upd['title'])) {
            $set_parts[]  = 'img_title = ?';
            $set_params[] = trim($upd['title']);
        }
        if (isset($upd['description'])) {
            $set_parts[]  = 'img_description = ?';
            $set_params[] = trim($upd['description']);
        }

        if ($set_parts) {
            $set_params[] = $id;
            try {
                $pdo->prepare("UPDATE snap_images SET " . implode(', ', $set_parts) . " WHERE id = ?")
                    ->execute($set_params);
            } catch (Exception $e) {
                $failed[] = ['id' => $id, 'error' => 'Failed to update image fields'];
                continue;
            }
        }

        // Category reassignment: replace all image categories with the new one
        if (isset($upd['category_id'])) {
            $new_cat = (int)$upd['category_id'];
            try {
                // Verify category exists
                $cat_check = $pdo->prepare("SELECT id FROM snap_categories WHERE id = ? LIMIT 1");
                $cat_check->execute([$new_cat]);
                if (!$cat_check->fetch()) {
                    $failed[] = ['id' => $id, 'error' => "Category $new_cat not found"];
                    continue;
                }
                $pdo->prepare("DELETE FROM snap_image_cat_map WHERE image_id = ?")
                    ->execute([$id]);
                $pdo->prepare("INSERT INTO snap_image_cat_map (image_id, category_id) VALUES (?, ?)")
                    ->execute([$id, $new_cat]);
            } catch (Exception $e) {
                $failed[] = ['id' => $id, 'error' => 'Failed to update category'];
                continue;
            }
        }

        if (isset($upd['sort_order'])) $sort_touched = true;
        $applied++;
    }

    // ── 438 / GYSS ordering fix: re-stamp the fediverse dates after a reorder ──
    // A GYSS push only rewrites snap_images.sort_order — that reorders the ON-SITE
    // feed but NOT the fediverse, which date-sorts. So a GYSS-set order scrambles
    // on delivery to Pixelfed unless we stamp strictly-decreasing fedi_published_at
    // down the new grid order. This mirrors smack-lt-gram.php's reorder re-stamp
    // exactly (sv_sync_fedi_dates already covers the SMACKONEOUT / snap_images
    // path since 0.7.403). Runs ONCE per batch, only when a reorder actually
    // happened and this install federates. Best-effort: an imprint failure must
    // never break an otherwise-successful save. NOTE: existing followers keep the
    // order they FIRST received — the fediverse pins a post's date at first sight;
    // re-sorting them needs a deliberate re-imprint + re-push, same as the site.
    if ($sort_touched && ($settings['smackverse_enabled'] ?? '0') === '1') {
        try {
            if (!function_exists('sv_sync_fedi_dates')) {
                @require_once __DIR__ . '/smackverse.php';
            }
            if (function_exists('sv_sync_fedi_dates')) {
                sv_sync_fedi_dates($pdo, $settings);
            }
        } catch (Throwable $e) {
            error_log('GYSS batch-update: fedi re-stamp failed — ' . $e->getMessage());
        }
    }

    gy_ok([
        'applied'   => $applied,
        'failed'    => $failed,
        'conflicts' => $conflicts,
    ]);
}


// --- FALLTHROUGH ---
gy_err('Unknown GYSS endpoint', 404);

// ===== SNAPSMACK EOF =====
