<?php
/**
 * SNAPSMACK FORUM API
 * Deployed to: snapsmack.ca/api/forum/
 *
 * All routes handled here via ?path= (rewritten by .htaccess).
 *
 * Routes:
 *   POST   register                  Register an install; returns api_key
 *   GET    categories                List all active boards with counts
 *   GET    threads?cat=N&page=N      List threads (newest active first; pinned float)
 *   GET    threads/{id}              Thread + all replies
 *   POST   threads                   Create a thread
 *   POST   threads/{id}/replies      Add a reply
 *   PATCH  installs/me               Update display_name when blog name changes
 *   DELETE threads/{id}              Soft-delete own thread (mod: any)
 *   DELETE replies/{id}              Soft-delete own reply (mod: any)
 */

require_once __DIR__ . '/config.php';

// ── Headers ───────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
// Calls are server-to-server (install PHP → snapsmack.ca), but permissive
// CORS doesn't hurt and allows future browser-direct debugging.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Database ──────────────────────────────────────────────────────────────────
function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

// ── Response helpers ──────────────────────────────────────────────────────────
function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(int $http_code, string $error_code, string $message): void {
    http_response_code($http_code);
    echo json_encode(['error' => $error_code, 'message' => $message]);
    exit;
}

function body(): array {
    static $parsed = null;
    if ($parsed === null) {
        $parsed = json_decode(file_get_contents('php://input'), true) ?? [];
    }
    return $parsed;
}

// ── Auth ──────────────────────────────────────────────────────────────────────

/**
 * Validate the Bearer token against ss_forum_installs.
 * Updates last_seen_at on every authenticated request.
 * Returns the install row on success; terminates with 401/403 on failure.
 */
function require_auth(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        api_error(401, 'MISSING_KEY', 'Authorization: Bearer {api_key} header is required.');
    }
    $key = trim($m[1]);

    // Mod key must not be used for install-identity operations
    if ($key === FORUM_MOD_KEY) {
        api_error(403, 'MOD_KEY', 'Use an install API key, not the moderator key.');
    }

    $pdo  = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT * FROM ss_forum_installs WHERE api_key = ? AND is_banned = 0 LIMIT 1"
    );
    $stmt->execute([$key]);
    $install = $stmt->fetch();

    if (!$install) {
        api_error(403, 'INVALID_KEY', 'API key not recognised or install is banned.');
    }

    $pdo->prepare("UPDATE ss_forum_installs SET last_seen_at = NOW() WHERE id = ?")
        ->execute([$install['id']]);

    return $install;
}

/**
 * Returns true if the request carries the moderator key.
 * Mods can act on any content; they are not tied to an install record.
 */
function is_mod(): bool {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]) === FORUM_MOD_KEY;
    }
    return false;
}

// ── Router ────────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$path   = trim($_GET['path'] ?? '', '/');
$parts  = $path !== '' ? explode('/', $path) : [];

// ─────────────────────────────────────────────────────────────────────────────
// POST /register
// Register a new install. Idempotent: re-registering the same domain returns
// the existing key so a lost key can be recovered without manual intervention.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $path === 'register') {
    $b            = body();
    $domain       = trim($b['domain']       ?? '');
    $display_name = trim($b['display_name'] ?? '');
    $ss_version   = trim($b['ss_version']   ?? '');

    if ($domain === '' || $display_name === '') {
        api_error(400, 'MISSING_FIELDS', 'domain and display_name are required.');
    }

    // Normalise: strip protocol and trailing slash
    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = rtrim($domain, '/');
    $domain = strtolower($domain);

    if (mb_strlen($domain)       > 255) api_error(400, 'DOMAIN_TOO_LONG',   'domain must be 255 chars or fewer.');
    if (mb_strlen($display_name) > 100) api_error(400, 'NAME_TOO_LONG',     'display_name must be 100 chars or fewer.');

    $pdo = get_pdo();

    // Already registered?
    $stmt = $pdo->prepare("SELECT api_key FROM ss_forum_installs WHERE domain = ? LIMIT 1");
    $stmt->execute([$domain]);
    $existing = $stmt->fetch();
    if ($existing) {
        // Update display_name and version in case they've changed since first registration
        $pdo->prepare(
            "UPDATE ss_forum_installs SET display_name = ?, ss_version = ?, last_seen_at = NOW() WHERE domain = ?"
        )->execute([$display_name, $ss_version, $domain]);
        respond(['api_key' => $existing['api_key'], 'status' => 'existing']);
    }

    $api_key = bin2hex(random_bytes(32));

    $pdo->prepare(
        "INSERT INTO ss_forum_installs (api_key, domain, display_name, ss_version) VALUES (?, ?, ?, ?)"
    )->execute([$api_key, $domain, $display_name, $ss_version]);

    respond(['api_key' => $api_key, 'status' => 'registered'], 201);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET /categories
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $path === 'categories') {
    require_auth();
    $pdo = get_pdo();
    $cats = $pdo->query(
        "SELECT id, slug, name, description, thread_count, reply_count
         FROM ss_forum_categories
         WHERE is_active = 1
         ORDER BY sort_order ASC"
    )->fetchAll();
    respond(['categories' => $cats]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET /threads?cat=N&page=N
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $path === 'threads') {
    require_auth();
    $pdo      = get_pdo();
    $cat_id   = (int)($_GET['cat']  ?? 0);
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 25;
    $offset   = ($page - 1) * $per_page;

    $where  = "t.is_deleted = 0";
    $params = [];
    if ($cat_id > 0) {
        $where   .= " AND t.category_id = ?";
        $params[] = $cat_id;
    }

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ss_forum_threads t WHERE $where");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetchColumn();

    $list_params = array_merge($params, [$per_page, $offset]);
    $stmt = $pdo->prepare("
        SELECT t.id, t.category_id, t.display_name, t.title,
               t.is_pinned, t.is_locked, t.reply_count,
               t.last_reply_at, t.created_at,
               c.name AS category_name, c.slug AS category_slug
        FROM ss_forum_threads t
        JOIN ss_forum_categories c ON c.id = t.category_id
        WHERE $where
        ORDER BY t.is_pinned DESC, t.last_reply_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($list_params);
    $threads = $stmt->fetchAll();

    respond([
        'threads'     => $threads,
        'total_count' => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'has_more'    => ($offset + count($threads)) < $total,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET /threads/{id}
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && count($parts) === 2 && $parts[0] === 'threads' && ctype_digit($parts[1])) {
    require_auth();
    $thread_id = (int)$parts[1];
    $pdo = get_pdo();

    $stmt = $pdo->prepare("
        SELECT t.*, c.name AS category_name, c.slug AS category_slug
        FROM ss_forum_threads t
        JOIN ss_forum_categories c ON c.id = t.category_id
        WHERE t.id = ? AND t.is_deleted = 0
        LIMIT 1
    ");
    $stmt->execute([$thread_id]);
    $thread = $stmt->fetch();
    if (!$thread) {
        api_error(404, 'THREAD_NOT_FOUND', 'That thread does not exist or has been removed.');
    }

    $reply_stmt = $pdo->prepare("
        SELECT id, install_id, display_name, body, created_at
        FROM ss_forum_replies
        WHERE thread_id = ? AND is_deleted = 0
        ORDER BY created_at ASC
    ");
    $reply_stmt->execute([$thread_id]);

    respond(['thread' => $thread, 'replies' => $reply_stmt->fetchAll()]);
}

// ─────────────────────────────────────────────────────────────────────────────
// POST /threads
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $path === 'threads') {
    $install = require_auth();
    $b       = body();

    $cat_id = (int)trim($b['category_id'] ?? 0);
    $title  = trim($b['title']            ?? '');
    $text   = trim($b['body']             ?? '');

    if (!$cat_id || $title === '' || $text === '') {
        api_error(400, 'MISSING_FIELDS', 'category_id, title, and body are required.');
    }
    if (mb_strlen($title) > 200)  api_error(400, 'TITLE_TOO_LONG', 'Title must be 200 characters or fewer.');
    if (mb_strlen($text)  > 20000) api_error(400, 'BODY_TOO_LONG', 'Body must be 20,000 characters or fewer.');

    $pdo = get_pdo();

    $cat = $pdo->prepare("SELECT id FROM ss_forum_categories WHERE id = ? AND is_active = 1 LIMIT 1");
    $cat->execute([$cat_id]);
    if (!$cat->fetch()) {
        api_error(400, 'INVALID_CATEGORY', 'Category not found or inactive.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO ss_forum_threads (category_id, install_id, display_name, title, body) VALUES (?, ?, ?, ?, ?)"
        )->execute([$cat_id, $install['id'], $install['display_name'], $title, $text]);
        $thread_id = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "UPDATE ss_forum_categories SET thread_count = thread_count + 1 WHERE id = ?"
        )->execute([$cat_id]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        api_error(500, 'SERVER_ERROR', 'Failed to create thread.');
    }

    respond(['thread_id' => $thread_id], 201);
}

// ─────────────────────────────────────────────────────────────────────────────
// POST /threads/{id}/replies
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST'
    && count($parts) === 3
    && $parts[0] === 'threads'
    && ctype_digit($parts[1])
    && $parts[2] === 'replies'
) {
    $install   = require_auth();
    $thread_id = (int)$parts[1];
    $b         = body();
    $text      = trim($b['body'] ?? '');

    if ($text === '') api_error(400, 'MISSING_FIELDS', 'body is required.');
    if (mb_strlen($text) > 10000) api_error(400, 'BODY_TOO_LONG', 'Reply must be 10,000 characters or fewer.');

    $pdo = get_pdo();

    $stmt = $pdo->prepare(
        "SELECT id, category_id, is_locked FROM ss_forum_threads WHERE id = ? AND is_deleted = 0 LIMIT 1"
    );
    $stmt->execute([$thread_id]);
    $thread = $stmt->fetch();
    if (!$thread) api_error(404, 'THREAD_NOT_FOUND', 'Thread not found.');
    if ($thread['is_locked']) api_error(403, 'THREAD_LOCKED', 'This thread is locked and no longer accepts replies.');

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO ss_forum_replies (thread_id, install_id, display_name, body) VALUES (?, ?, ?, ?)"
        )->execute([$thread_id, $install['id'], $install['display_name'], $text]);
        $reply_id = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "UPDATE ss_forum_threads SET reply_count = reply_count + 1, last_reply_at = NOW() WHERE id = ?"
        )->execute([$thread_id]);
        $pdo->prepare(
            "UPDATE ss_forum_categories SET reply_count = reply_count + 1 WHERE id = ?"
        )->execute([$thread['category_id']]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        api_error(500, 'SERVER_ERROR', 'Failed to add reply.');
    }

    respond(['reply_id' => $reply_id], 201);
}

// ─────────────────────────────────────────────────────────────────────────────
// PATCH /installs/me — update display_name when site_name changes
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'PATCH' && $path === 'installs/me') {
    $install      = require_auth();
    $b            = body();
    $display_name = trim($b['display_name'] ?? '');

    if ($display_name === '') api_error(400, 'MISSING_FIELDS', 'display_name is required.');
    if (mb_strlen($display_name) > 100) api_error(400, 'NAME_TOO_LONG', 'display_name must be 100 characters or fewer.');

    get_pdo()->prepare(
        "UPDATE ss_forum_installs SET display_name = ? WHERE id = ?"
    )->execute([$display_name, $install['id']]);

    respond(['status' => 'updated', 'display_name' => $display_name]);
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE /threads/{id}
// Own threads: install must match. Moderator: any thread.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'DELETE' && count($parts) === 2 && $parts[0] === 'threads' && ctype_digit($parts[1])) {
    $mod        = is_mod();
    $install    = $mod ? null : require_auth();
    $thread_id  = (int)$parts[1];
    $pdo        = get_pdo();

    $stmt = $pdo->prepare(
        "SELECT id, install_id, category_id FROM ss_forum_threads WHERE id = ? AND is_deleted = 0 LIMIT 1"
    );
    $stmt->execute([$thread_id]);
    $thread = $stmt->fetch();
    if (!$thread) api_error(404, 'THREAD_NOT_FOUND', 'Thread not found.');

    if (!$mod && (int)$thread['install_id'] !== (int)$install['id']) {
        api_error(403, 'FORBIDDEN', 'You can only delete your own threads.');
    }

    $pdo->prepare("UPDATE ss_forum_threads SET is_deleted = 1 WHERE id = ?")->execute([$thread_id]);
    $pdo->prepare(
        "UPDATE ss_forum_categories SET thread_count = GREATEST(0, thread_count - 1) WHERE id = ?"
    )->execute([$thread['category_id']]);

    respond(['status' => 'deleted']);
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE /replies/{id}
// Own replies: install must match. Moderator: any reply.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'DELETE' && count($parts) === 2 && $parts[0] === 'replies' && ctype_digit($parts[1])) {
    $mod      = is_mod();
    $install  = $mod ? null : require_auth();
    $reply_id = (int)$parts[1];
    $pdo      = get_pdo();

    $stmt = $pdo->prepare(
        "SELECT id, install_id, thread_id FROM ss_forum_replies WHERE id = ? AND is_deleted = 0 LIMIT 1"
    );
    $stmt->execute([$reply_id]);
    $reply = $stmt->fetch();
    if (!$reply) api_error(404, 'REPLY_NOT_FOUND', 'Reply not found.');

    if (!$mod && (int)$reply['install_id'] !== (int)$install['id']) {
        api_error(403, 'FORBIDDEN', 'You can only delete your own replies.');
    }

    $pdo->prepare("UPDATE ss_forum_replies SET is_deleted = 1 WHERE id = ?")->execute([$reply_id]);

    // Decrement counts on thread and category
    $t_stmt = $pdo->prepare("SELECT category_id FROM ss_forum_threads WHERE id = ? LIMIT 1");
    $t_stmt->execute([$reply['thread_id']]);
    $parent = $t_stmt->fetch();

    $pdo->prepare(
        "UPDATE ss_forum_threads SET reply_count = GREATEST(0, reply_count - 1) WHERE id = ?"
    )->execute([$reply['thread_id']]);

    if ($parent) {
        $pdo->prepare(
            "UPDATE ss_forum_categories SET reply_count = GREATEST(0, reply_count - 1) WHERE id = ?"
        )->execute([$parent['category_id']]);
    }

    respond(['status' => 'deleted']);
}

// ─────────────────────────────────────────────────────────────────────────────
// 404 — no route matched
// ─────────────────────────────────────────────────────────────────────────────
api_error(404, 'NOT_FOUND', 'Endpoint not found.');
