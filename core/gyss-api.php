<?php
/**
 * SNAPSMACK — GET YOUR SHIT SORTED API
 *
 * Authenticated REST endpoints for the GYSS desktop photo-sorting tool.
 * All endpoints require a valid Bearer token from snap_ohsnap_keys (key_type = 'gyss').
 *
 * Route structure: gyss/{resource}
 *   GET  gyss/ping           — connection test, returns site vitals (incl. site_mode)
 *   GET  gyss/prompt         — this site's whole-post AI prompt (for fleet sync)
 *   POST gyss/prompt         — set this site's whole-post AI prompt
 *   GET  gyss/photos         — filtered photo export with metadata + modified_at
 *   GET  gyss/meta           — categories and albums for filter/edit dropdowns
 *   POST gyss/batch-update   — push a diff of sorted/edited records back to the blog
 *   GET  gyss/gram-posts     — GRAMOFSMACK grid feed as posts (cover thumb + count)
 *   POST gyss/gram-reorder   — rewrite the GRAMOFSMACK feed order (post sort_order)
 *   POST gyss/gram-carousel  — combine selected single posts into one carousel
 *
 * Conflict detection (v0.2):
 *   batch-update accepts optional expected_modified_at per record. If the live
 *   modified_at differs, the record is returned in conflicts[] instead of applied.
 *   Pass force:true per record to skip the check and overwrite explicitly.
 *
 * Scope: two modes, keyed off snap_settings.site_mode.
 *   - SMACKONEOUT (photoblog): the original photo sorter. photos/meta/batch-update
 *     operate on snap_images + snap_image_cat_map.
 *   - GRAMOFSMACK (carousel): the gram-* endpoints operate on the post layer
 *     (snap_posts / snap_post_images), reusing the SAME battle-built core the web
 *     lighttable uses (sv_convert_to_carousel + the reorder/pin-in-place logic).
 *     Carousel-builder + feed-reorder only; trigram management stays in the web
 *     lighttable (trigram members are excluded from gram-posts).
 *   - SMACKTALK (longform): NOT SUPPORTED, and never will be — structurally
 *     incompatible, not merely unbuilt. Longform images live INSIDE the essay body
 *     ([mosaic:ID] post-body shortcodes + one featured_asset_id), so there is no
 *     grid of tiles and no per-image order that means anything to sort. photos and
 *     batch-update refuse with 409 on this mode; longform belongs to SmackPress
 *     (core/smackpress-api.php).
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
require_once __DIR__ . '/alt-text.php';

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
// SECAUDIT 039: enforce key EXPIRY. smack-api-keys.php stamps every generated key
// with an expires_at (mandatory <=4-week keys since 0.7.263) and core/api-auth.php
// rejects past-expiry keys — but this handler only checked is_active, so an
// "expired" GYSS key kept working indefinitely. Same expiry-aware pattern as
// api-auth.php: NULL expires_at = legacy key (no expiry), a set past expiry is
// rejected. Falls back to the old query if the column doesn't exist yet, so a
// site mid-migration keeps working.
try {
    $key_stmt = $pdo->prepare("
        SELECT id FROM snap_ohsnap_keys
        WHERE key_hash = ? AND key_type = 'gyss' AND is_active = 1
          AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 1
    ");
    $key_stmt->execute([$key_hash]);
    $api_key_row = $key_stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $key_stmt = $pdo->prepare("
            SELECT id FROM snap_ohsnap_keys
            WHERE key_hash = ? AND key_type = 'gyss' AND is_active = 1
            LIMIT 1
        ");
        $key_stmt->execute([$key_hash]);
        $api_key_row = $key_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
        gy_err('Database error during auth', 503);
    }
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
        // site_mode drives which GYSS mode the client offers: 'photoblog' =
        // SMACKONEOUT (photo sorter), 'carousel' = GRAMOFSMACK (grid/carousel).
        'site_mode' => $settings['site_mode'] ?? 'photoblog',
    ]);
}


// =============================================================================
// ENDPOINT: GET gyss/prompt
// This site's WHOLE-POST AI prompt — the single-call prompt that fills every
// field (caption/ALT/tags/colours) in ONE request. First-class get so the fleet
// prompt-sync can pull it into the shared pool without piggy-backing on the
// enrich-list. `is_default` = true when no custom prompt is saved (the built-in
// is being used).
// =============================================================================
if ($resource === 'prompt' && $method === 'GET') {
    $gp_saved = '';
    try {
        $gp_stmt = $pdo->prepare("SELECT setting_val FROM snap_settings WHERE setting_key = 'ai_post_enrichment_prompt' LIMIT 1");
        $gp_stmt->execute();
        $gp_saved = trim((string)($gp_stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) { $gp_saved = ''; }
    gy_ok([
        'prompt'     => snap_ai_post_enrichment_prompt($pdo),  // saved, or the built-in default
        'is_default' => $gp_saved === '',
    ]);
}

// =============================================================================
// ENDPOINT: POST gyss/prompt   Body: {"prompt": "..."}
// Set this site's WHOLE-POST AI prompt. An empty prompt CLEARS the custom one
// (the site falls back to the built-in default) — it never stores a blank. Same
// setting the enrich flow persists, exposed on its own so the fleet sync can
// push a prompt to a site without running an enrichment.
// =============================================================================
if ($resource === 'prompt' && $method === 'POST') {
    $gp_body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($gp_body) || !array_key_exists('prompt', $gp_body)) {
        gy_err('Body must be JSON with a "prompt" string');
    }
    $gp_new = mb_substr(trim((string)$gp_body['prompt']), 0, 12000);
    try {
        if ($gp_new === '') {
            $pdo->prepare("DELETE FROM snap_settings WHERE setting_key = 'ai_post_enrichment_prompt'")->execute();
        } else {
            $pdo->prepare(
                "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('ai_post_enrichment_prompt', ?)
                 ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
            )->execute([$gp_new]);
        }
    } catch (Throwable $e) {
        gy_err('Could not save the prompt: ' . $e->getMessage(), 500);
    }
    gy_ok([
        'prompt'     => snap_ai_post_enrichment_prompt($pdo),
        'is_default' => $gp_new === '',
        'saved'      => $gp_new !== '',
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
//   rights       — clear | unclear | unknown | all (optional)
//   limit        — int, default 200, max 500
//   offset       — int, default 0
// =============================================================================
if ($resource === 'photos' && $method === 'GET') {

    // SMACKTALK (longform) has no sortable photo feed — its images live INSIDE
    // essays via [mosaic:ID] post-body shortcodes plus one featured asset, so
    // there is no per-image order to arrange. Refuse rather than hand back a list
    // whose sort_order means nothing. (Longform is SmackPress's job.)
    if (($settings['site_mode'] ?? 'photoblog') === 'smacktalk') {
        gy_err('GYSS does not support SMACKTALK (longform) sites — images live inside essays and are not sortable. Use SmackPress to manage longform posts.', 409);
    }

    $date_from   = $_GET['date_from']   ?? '';
    $date_to     = $_GET['date_to']     ?? '';
    $category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
    $album_id    = isset($_GET['album_id'])    ? (int)$_GET['album_id']    : null;
    $rights      = strtolower(trim((string)($_GET['rights'] ?? 'all')));
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
    if ($rights === 'clear') {
        $where[] = "EXISTS (SELECT 1 FROM snap_image_tags rit JOIN snap_tags rt ON rt.id = rit.tag_id WHERE rit.image_id = i.id AND rt.slug = 'certifiedrights')";
    } elseif ($rights === 'unclear') {
        $where[] = "EXISTS (SELECT 1 FROM snap_image_tags rit JOIN snap_tags rt ON rt.id = rit.tag_id WHERE rit.image_id = i.id AND rt.slug = 'unclearrights')";
    } elseif ($rights === 'unknown') {
        $where[] = "NOT EXISTS (SELECT 1 FROM snap_image_tags rit JOIN snap_tags rt ON rt.id = rit.tag_id WHERE rit.image_id = i.id AND rt.slug IN ('certifiedrights', 'unclearrights'))";
    } elseif ($rights !== 'all' && $rights !== '') {
        gy_err('Invalid rights filter; use clear, unclear, unknown, or all', 400);
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
                i.img_slug,
                i.download_url,
                i.img_date        AS posted_date,
                i.modified_at,
                i.img_license,
                CASE
                    WHEN EXISTS (SELECT 1 FROM snap_image_tags rit JOIN snap_tags rt ON rt.id = rit.tag_id WHERE rit.image_id = i.id AND rt.slug = 'unclearrights') THEN 'unclear'
                    WHEN EXISTS (SELECT 1 FROM snap_image_tags rit JOIN snap_tags rt ON rt.id = rit.tag_id WHERE rit.image_id = i.id AND rt.slug = 'certifiedrights') THEN 'clear'
                    ELSE 'unknown'
                END AS rights_status,
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
            'licence'       => $row['img_license'],
            'rights_status' => $row['rights_status'],
            'category_id'   => $row['category_id'] !== null ? (int)$row['category_id'] : null,
            'category_name' => $row['category_name'],
            'album_id'      => $row['album_id'] !== null ? (int)$row['album_id'] : null,
            'album_name'    => $row['album_name'],
            'filename'      => basename((string)$row['img_file']),
            'thumb_url'     => gy_thumb_url($row['img_file']),
            'source_page_url' => BASE_URL . ltrim((string)$row['img_slug'], '/'),
            'highres_download_url' => snap_api_safe_link((string)$row['download_url']),
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
// ENDPOINT: GET gyss/library
// Offline-sorter bulk export — the per-blog local library GYSS sorts against.
//
// Returns, in ONE call:
//   images       — published image records. Full set, OR (with ?since=) only rows
//                  changed since that server timestamp.
//   categories   — full category list (whole; small).
//   albums       — full album list (whole; small).
//   cat_map      — [[image_id, category_id], ...]  membership, WHOLE.
//   album_map    — [[image_id, album_id],   ...]  membership, WHOLE.
//   current_ids  — every published image id, WHOLE.
//   synced_at    — the SERVER clock; the client stores THIS as the next ?since.
//
// Why maps + current_ids are always whole even in a delta:
//   * Re-tagging an image mutates snap_image_cat_map, NOT snap_images.modified_at,
//     so a since-filter on images would miss it. Shipping the whole (tiny, int-
//     pair) maps lets the client refresh every local image's membership.
//   * snap_images HARD-deletes (img_status is only published|draft), so a removed
//     row is simply absent from a since-filter. current_ids lets the client prune
//     locally-orphaned rows (and their thumbs).
//
// Scope: image/category/album data ONLY — never users, keys, or secrets. This is
// the GYSS trust boundary (SECAUDIT 039); do NOT widen it to a full DB dump.
// =============================================================================
if ($resource === 'library' && $method === 'GET') {

    if (($settings['site_mode'] ?? 'photoblog') === 'smacktalk') {
        gy_err('GYSS does not support SMACKTALK (longform) sites — images live inside essays and are not sortable.', 409);
    }

    // Parse optional since. Value only ever reaches a prepared param, but we
    // normalise to a canonical datetime so a garbage string is a clean 400, not a
    // silent empty delta. Parsed literally (no timezone shift): the client sends
    // back the exact synced_at string we handed it, which came from NOW().
    $since_raw = trim((string)($_GET['since'] ?? ''));
    $since_sql = null;
    if ($since_raw !== '') {
        $dt = date_create(str_replace('T', ' ', $since_raw));
        if ($dt === false) {
            gy_err('Invalid since parameter — expected an ISO datetime (YYYY-MM-DD HH:MM:SS).', 400);
        }
        $since_sql = $dt->format('Y-m-d H:i:s');
    }

    try {
        // Server high-water mark, captured BEFORE the reads. The client stores
        // this as the next ?since (never its own clock — avoids skew). Overlap is
        // safe: image upserts on the client are idempotent.
        $synced_at = (string)$pdo->query("SELECT NOW()")->fetchColumn();

        // --- IMAGES (full, or changed-since) ---
        $img_where  = ["i.img_status = 'published'"];
        $img_params = [];
        if ($since_sql !== null) {
            $img_where[]  = 'i.modified_at >= ?';
            $img_params[] = $since_sql;
        }
        $img_where_sql = implode(' AND ', $img_where);
        // Read-only path: never mutate schema on a GET. Only SELECT the colour tag
        // if the column exists yet — the write paths create it on first post; an
        // install that updated core but hasn't posted since simply reports ''.
        $has_color = (bool)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'snap_images'
               AND COLUMN_NAME = 'img_color_mode'"
        )->fetchColumn();
        $color_select = $has_color ? "i.img_color_mode AS color_mode" : "'' AS color_mode";
        $img_stmt = $pdo->prepare("
            SELECT i.id, i.img_title AS title, i.img_description AS description,
                   i.img_alt AS alt, $color_select, i.sort_order, i.img_file,
                   i.img_date AS posted_date, i.modified_at,
                   i.img_width, i.img_height
            FROM snap_images i
            WHERE $img_where_sql
            ORDER BY i.sort_order ASC, i.id DESC
        ");
        $img_stmt->execute($img_params);
        $images = [];
        foreach ($img_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $images[] = [
                'id'          => (int)$row['id'],
                'title'       => $row['title'],
                'description' => $row['description'],
                'alt'         => $row['alt'],
                'color_mode'  => $row['color_mode'] ?? '',
                'sort_order'  => (int)$row['sort_order'],
                'filename'    => basename((string)$row['img_file']),
                'posted_date' => $row['posted_date'],
                'modified_at' => $row['modified_at'],
                'width'       => $row['img_width']  !== null ? (int)$row['img_width']  : null,
                'height'      => $row['img_height'] !== null ? (int)$row['img_height'] : null,
                'thumb_url'   => gy_thumb_url($row['img_file']),
            ];
        }

        // --- CATEGORIES + ALBUMS (whole; small) ---
        $categories = $pdo->query("SELECT id, cat_name AS name FROM snap_categories ORDER BY cat_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($categories as &$c) { $c['id'] = (int)$c['id']; } unset($c);
        $albums = $pdo->query("SELECT id, album_name AS name FROM snap_albums ORDER BY album_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($albums as &$a) { $a['id'] = (int)$a['id']; } unset($a);

        // --- MEMBERSHIP MAPS (whole int pairs — catches re-tags on unchanged images) ---
        $cat_map = [];
        foreach ($pdo->query("SELECT image_id, category_id FROM snap_image_cat_map")->fetchAll(PDO::FETCH_NUM) as $p) {
            $cat_map[] = [(int)$p[0], (int)$p[1]];
        }
        $album_map = [];
        foreach ($pdo->query("SELECT image_id, album_id FROM snap_image_album_map")->fetchAll(PDO::FETCH_NUM) as $p) {
            $album_map[] = [(int)$p[0], (int)$p[1]];
        }

        // --- CURRENT IDS (whole published set — client prunes orphans / hard deletes) ---
        $current_ids = array_map('intval',
            $pdo->query("SELECT id FROM snap_images WHERE img_status = 'published' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN));

    } catch (Exception $e) {
        gy_err('Database error building library export', 500);
    }

    gy_ok([
        'full'        => $since_sql === null,
        'synced_at'   => $synced_at,
        'site_mode'   => $settings['site_mode'] ?? 'photoblog',
        'base_url'    => BASE_URL,
        'categories'  => $categories,
        'albums'      => $albums,
        'cat_map'     => $cat_map,
        'album_map'   => $album_map,
        'current_ids' => $current_ids,
        'images'      => $images,
    ]);
}

// =============================================================================
// ENDPOINT: GET gyss/enrichment-audit
// Read-only list of published photos with missing metadata. GYSS owns iteration;
// the server never runs a long batch.
// =============================================================================
if ($resource === 'enrichment-audit' && $method === 'GET') {
    $limit = min(max((int)($_GET['limit'] ?? 500), 1), 1000);
    $rows = $pdo->query("
        SELECT i.id, i.img_title, i.img_description, i.img_alt, i.img_file, i.img_display_options,
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
        if (trim((string)($row['img_alt'] ?? '')) === '') $missing[] = 'alt';
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

    $allowed_fields = ['title', 'caption', 'alt', 'tags', 'category', 'album', 'colors'];
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

    $parsed = ['title'=>'', 'caption'=>'', 'alt'=>'', 'tags'=>'', 'category'=>'', 'album'=>'', 'colors'=>''];
    foreach (preg_split('/\R/', trim($result['text'])) as $line) {
        if (preg_match('/^(TITLE|CAPTION|ALT|TAGS|CATEGORY|ALBUM|COLORS):\s*(.*)$/i', trim($line), $m)) {
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
        $alt = (string)($image['img_alt'] ?? '');
        if (in_array('alt', $fields, true) && ($overwrite || trim($alt) === '') && $parsed['alt'] !== '') {
            $alt = snap_sanitize_alt($parsed['alt']);   // AI output = untrusted → sanitize
            $applied[] = 'alt';
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
        $pdo->prepare("UPDATE snap_images SET img_title = ?, img_description = ?, img_alt = ?, img_display_options = ? WHERE id = ?")
            ->execute([$title, $caption, $alt, $display_json, $id]);

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

    // Same refusal as gyss/photos, on the WRITE path: never let a stale client
    // rewrite sort_order on a SMACKTALK install, where image order is meaningless.
    if (($settings['site_mode'] ?? 'photoblog') === 'smacktalk') {
        gy_err('GYSS does not support SMACKTALK (longform) sites — images live inside essays and are not sortable. Use SmackPress to manage longform posts.', 409);
    }
    $pdo->exec("ALTER TABLE snap_images ADD COLUMN IF NOT EXISTS img_color_mode VARCHAR(10) NOT NULL DEFAULT ''");

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
        // Colour/B&W classification tag (search/filter, not a render change).
        if (isset($upd['color_mode'])) {
            $set_parts[]  = 'img_color_mode = ?';
            $set_params[] = snap_normalize_color_mode($upd['color_mode']);
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
    if ($sort_touched && ($settings['fediverse_enabled'] ?? '0') === '1') {
        try {
            if (!function_exists('sv_sync_fedi_dates')) {
                @require_once __DIR__ . '/fediverse.php';
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


// =============================================================================
// ENDPOINT: GET gyss/gram-posts   (GRAMOFSMACK only)
// The grid feed as POSTS: cover thumb, image count, post_type, feed order.
// Trigram members are EXCLUDED — trigram management lives in the web lighttable.
//
// Query params: limit (int, default 500, max 1000)
// =============================================================================
if ($resource === 'gram-posts' && $method === 'GET') {
    if (($settings['site_mode'] ?? 'photoblog') !== 'carousel') {
        gy_err('This site is not in GRAMOFSMACK (carousel) mode.', 409);
    }
    $limit = min(max((int)($_GET['limit'] ?? 500), 1), 1000);

    try {
        // Feed order mirrors smack-lt-gram.php exactly: new (sort_order 0) group
        // first, then ascending, then id DESC. Cover file: prefer the is_cover
        // pivot row, fall back to any image linked by post_id (singles without a
        // pivot row). Trigram members excluded (p.trigram_id IS NULL).
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.title,
                p.status,
                p.sort_order,
                p.post_type,
                p.created_at,
                (SELECT COUNT(*) FROM snap_post_images spi WHERE spi.post_id = p.id) AS image_count,
                COALESCE(
                    (SELECT i1.img_file FROM snap_post_images pi1 JOIN snap_images i1 ON i1.id = pi1.image_id
                      WHERE pi1.post_id = p.id AND pi1.is_cover = 1 LIMIT 1),
                    (SELECT i2.img_file FROM snap_images i2 WHERE i2.post_id = p.id ORDER BY i2.id LIMIT 1)
                ) AS cover_file
            FROM snap_posts p
            WHERE p.trigram_id IS NULL
              AND p.post_type IN ('single','carousel','panorama')
            ORDER BY CASE WHEN p.sort_order > 0 THEN 1 ELSE 0 END ASC,
                     p.sort_order ASC, p.id DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        gy_err('Database error fetching grid posts', 500);
    }

    $posts = [];
    foreach ($rows as $row) {
        $count = (int)$row['image_count'];
        $posts[] = [
            'id'          => (int)$row['id'],
            'title'       => (string)$row['title'],
            'status'      => (string)$row['status'],
            'sort_order'  => (int)$row['sort_order'],
            'post_type'   => (string)$row['post_type'],
            'image_count' => $count,
            // A post is combinable only if it's an ungrouped published single.
            'combinable'  => ($count <= 1 && $row['post_type'] === 'single' && $row['status'] === 'published'),
            'created_at'  => $row['created_at'],
            'thumb_url'   => gy_thumb_url((string)$row['cover_file']),
        ];
    }

    gy_ok(['total' => count($posts), 'posts' => $posts]);
}


// =============================================================================
// ENDPOINT: POST gyss/gram-reorder   (GRAMOFSMACK only)
// Rewrite the feed order for the NON-TRIGRAM posts GYSS shows. Because GYSS
// excludes trigram members from its grid, we must NOT use the lighttable's
// subset-splice (which would relocate the unseen trigrams). Instead we keep every
// trigram post pinned at its exact current feed slot, and fill the remaining
// (non-trigram) slots with the submitted order. Then re-stamp fediverse dates.
//
// Request body (JSON): { "ids": [postId, postId, ...] }   (the new visible order)
// =============================================================================
if ($resource === 'gram-reorder' && $method === 'POST') {
    if (($settings['site_mode'] ?? 'photoblog') !== 'carousel') {
        gy_err('This site is not in GRAMOFSMACK (carousel) mode.', 409);
    }
    $data      = json_decode((string)file_get_contents('php://input'), true);
    $submitted = array_values(array_filter(array_map('intval', $data['ids'] ?? [])));
    if (!$submitted) gy_err('No post ids provided.');

    // Canonical feed order (same ordering as the lighttable + public feed).
    $all_ids = array_map('intval', $pdo->query(
        "SELECT id FROM snap_posts
          ORDER BY CASE WHEN sort_order > 0 THEN 1 ELSE 0 END ASC,
                   sort_order ASC, id DESC"
    )->fetchAll(PDO::FETCH_COLUMN));

    // Which posts are trigram members — they stay pinned at their canonical slot.
    $trigram_ids = [];
    foreach ($pdo->query("SELECT id FROM snap_posts WHERE trigram_id IS NOT NULL")
                 ->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        $trigram_ids[(int)$tid] = true;
    }

    // Build the fill queue: submitted ids that exist and aren't trigram members,
    // unique, in submitted order.
    $canon_set = array_flip($all_ids);
    $queue = []; $seen = [];
    foreach ($submitted as $sid) {
        if (isset($canon_set[$sid]) && !isset($trigram_ids[$sid]) && !isset($seen[$sid])) {
            $queue[] = $sid; $seen[$sid] = true;
        }
    }
    // Append any non-trigram posts the client DIDN'T send (partial payload / a
    // post added since load), in canonical order — so the queue is an exact
    // permutation of the non-trigram set: no post duplicated, none dropped.
    foreach ($all_ids as $id) {
        if (!isset($trigram_ids[$id]) && !isset($seen[$id])) { $queue[] = $id; $seen[$id] = true; }
    }

    // Walk the canonical feed: trigram slots keep their post; every other slot is
    // filled from the queue in order.
    $result = [];
    $qi = 0;
    foreach ($all_ids as $id) {
        $result[] = isset($trigram_ids[$id]) ? $id : $queue[$qi++];
    }

    try {
        $stmt = $pdo->prepare("UPDATE snap_posts SET sort_order = ? WHERE id = ?");
        $pdo->beginTransaction();
        foreach ($result as $pos => $id) {
            $stmt->execute([$pos + 1, $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        gy_err('Failed to write the new order — nothing changed.', 500);
    }

    // Keep the fediverse order honest (same imprint the lighttable reorder does).
    if (($settings['fediverse_enabled'] ?? '0') === '1') {
        try {
            if (!function_exists('sv_sync_fedi_dates')) { @require_once __DIR__ . '/fediverse.php'; }
            if (function_exists('sv_sync_fedi_dates')) { sv_sync_fedi_dates($pdo, $settings); }
        } catch (Throwable $e) {
            error_log('GYSS gram-reorder: fedi re-stamp failed — ' . $e->getMessage());
        }
    }

    gy_ok(['reordered' => count($queue)]);
}


// =============================================================================
// ENDPOINT: POST gyss/gram-carousel   (GRAMOFSMACK only)
// Combine 2+ selected single posts into ONE carousel, in the given order, with a
// chosen cover. Copies smack-lt-gram.php's `create_carousel` handler: guards
// (D4 — ungrouped published singles only), maps posts→images, calls the shared
// federation-aware sv_convert_to_carousel(), then pins the carousel where the
// earliest source sat (D3).
//
// Request body (JSON): { "ids": [postId, ...], "cover_post_id": postId }
// =============================================================================
if ($resource === 'gram-carousel' && $method === 'POST') {
    if (($settings['site_mode'] ?? 'photoblog') !== 'carousel') {
        gy_err('This site is not in GRAMOFSMACK (carousel) mode.', 409);
    }
    $data = json_decode((string)file_get_contents('php://input'), true);
    $ids  = array_values(array_unique(array_filter(array_map('intval', $data['ids'] ?? []))));
    if (count($ids) < 2) gy_err('Select at least two posts to combine into a carousel.');

    $cover_pid = (int)($data['cover_post_id'] ?? 0);
    if (!in_array($cover_pid, $ids, true)) $cover_pid = $ids[0];

    // Load type/trigram/existence.
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $meta = $pdo->prepare("SELECT id, post_type, trigram_id, status FROM snap_posts WHERE id IN ($ph)");
    $meta->execute($ids);
    $meta_rows = $meta->fetchAll(PDO::FETCH_ASSOC);
    if (count($meta_rows) !== count($ids)) {
        gy_err('One or more selected posts no longer exist — refresh and try again.');
    }
    // D4 — only ungrouped published singles combine.
    foreach ($meta_rows as $r) {
        if (($r['post_type'] ?? 'single') !== 'single' || (int)($r['trigram_id'] ?? 0) > 0) {
            gy_err('Only ungrouped single posts can be combined. Deselect any carousel, panorama, or trigram tile.');
        }
        if (($r['status'] ?? '') !== 'published') {
            gy_err('Only PUBLISHED posts can be combined into a carousel.');
        }
    }

    // Map posts → image ids in the given (selection) order.
    $img_for_post = [];
    $imgStmt = $pdo->prepare("SELECT id FROM snap_images WHERE post_id = ? ORDER BY id");
    foreach ($ids as $pid) {
        $imgStmt->execute([$pid]);
        $img_for_post[$pid] = array_map('intval', $imgStmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $image_ids = [];
    foreach ($ids as $pid) foreach ($img_for_post[$pid] as $iid) $image_ids[] = $iid;
    if (count($image_ids) < 2) gy_err('The selected posts have fewer than two images to combine.');
    $cover_id = $img_for_post[$cover_pid][0] ?? $image_ids[0];

    // Earliest feed slot any source holds — pin the carousel there (D3).
    $seq_before = array_map('intval', $pdo->query(
        "SELECT id FROM snap_posts
          ORDER BY CASE WHEN sort_order > 0 THEN 1 ELSE 0 END ASC,
                   sort_order ASC, id DESC"
    )->fetchAll(PDO::FETCH_COLUMN));
    $insert_at = 0;
    foreach ($seq_before as $sid) {
        if (in_array($sid, $ids, true)) break;
        $insert_at++;
    }

    // Shared merge core (federation-aware, transactional).
    if (!function_exists('sv_convert_to_carousel')) { @require_once __DIR__ . '/fediverse.php'; }
    if (!function_exists('sv_convert_to_carousel')) {
        gy_err('Carousel engine unavailable on this install.', 500);
    }
    $res     = sv_convert_to_carousel($pdo, $settings, $image_ids, $cover_id);
    $ok      = (bool)($res[0] ?? false);
    $msg     = (string)($res[1] ?? '');
    $new_pid = (int)($res[2] ?? 0);
    if (!$ok) gy_err($msg ?: 'Combine failed.');

    if ($new_pid <= 0) {
        $new_pid = (int)$pdo->query("SELECT id FROM snap_posts WHERE post_type = 'carousel' ORDER BY id DESC LIMIT 1")->fetchColumn();
    }

    // Pin in place — splice the carousel into the earliest source's slot.
    if ($new_pid > 0) {
        try {
            $seq_after = array_map('intval', $pdo->query(
                "SELECT id FROM snap_posts
                  ORDER BY CASE WHEN sort_order > 0 THEN 1 ELSE 0 END ASC,
                           sort_order ASC, id DESC"
            )->fetchAll(PDO::FETCH_COLUMN));
            $seq_after = array_values(array_filter($seq_after, fn($x) => $x !== $new_pid));
            $insert_at = min($insert_at, count($seq_after));
            array_splice($seq_after, $insert_at, 0, [$new_pid]);

            $so = $pdo->prepare("UPDATE snap_posts SET sort_order = ? WHERE id = ?");
            $pdo->beginTransaction();
            foreach ($seq_after as $pos => $sid) { $so->execute([$pos + 1, $sid]); }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // Non-fatal: the carousel exists; it just sits on top until a reorder.
        }
    }

    gy_ok(['post_id' => $new_pid, 'message' => $msg]);
}


// --- FALLTHROUGH ---
gy_err('Unknown GYSS endpoint', 404);

// ===== SNAPSMACK EOF =====
