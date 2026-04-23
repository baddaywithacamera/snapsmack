<?php
/**
 * SNAPSMACK FORUM API
 * Deployed to: snapsmack.ca/api/forum/
 *
 * All routes handled here via ?path= (rewritten by .htaccess).
 *
 * Routes:
 *   POST   register                           Register an install; returns api_key
 *   GET    categories                         List all active boards with counts
 *   GET    threads?cat=N&page=N&tag=slug      List threads (pinned first, then newest active)
 *   GET    threads/{id}                       Thread + replies + reactions + solved state
 *   POST   threads                            Create a thread (rate-limited)
 *   PATCH  threads/{id}                       Mod: pin / lock flags; own/mod: edit body
 *   DELETE threads/{id}                       Soft-delete own thread (mod: any)
 *   POST   threads/{id}/replies               Add a reply (rate-limited)
 *   PATCH  replies/{id}                       Edit own reply body (mod: any)
 *   DELETE replies/{id}                       Soft-delete own reply (mod: any)
 *   POST   replies/{id}/solve                 Mark reply as accepted answer (thread author or mod)
 *   DELETE replies/{id}/solve                 Unmark accepted answer (thread author or mod)
 *   POST   threads/{id}/react                 Add / toggle emoji reaction on thread
 *   DELETE threads/{id}/react                 Remove own reaction from thread
 *   POST   replies/{id}/react                 Add / toggle emoji reaction on reply
 *   DELETE replies/{id}/react                 Remove own reaction from reply
 *   POST   threads/{id}/read                  Mark thread as read (update read state)
 *   GET    search?q=term&cat=N&page=N         Full-text search across threads and replies
 *   GET    tags                               List all tags with thread counts
 *   POST   threads/{id}/tags                  Mod: add tag to thread
 *   DELETE threads/{id}/tags/{tag_id}         Mod: remove tag from thread
 *   GET    notifications?page=N               Get notifications for this install
 *   POST   notifications/read                 Mark notification(s) as read
 *   PATCH  installs/me                        Update display_name when blog name changes
 */

require_once __DIR__ . '/config.php';

// ── Headers ───────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
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

/** Returns true if the request carries the moderator key. */
function is_mod(): bool {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]) === FORUM_MOD_KEY;
    }
    return false;
}

/** Returns true if the authenticated install has the moderator flag, or the global mod key is used. */
function is_install_mod(?array $install = null): bool {
    if (is_mod()) return true;
    return $install && !empty($install['is_moderator']);
}

// ── Utilities ─────────────────────────────────────────────────────────────────

/**
 * Generate a plain-text excerpt from post body.
 * Strips basic markdown, caps at $len characters.
 */
function make_excerpt(string $body, int $len = 300): string {
    // Strip markdown-style syntax: headers, bold, italic, code, links
    $text = preg_replace('/#{1,6}\s+/', '', $body);          // headings
    $text = preg_replace('/\*{1,2}(.+?)\*{1,2}/', '$1', $text); // bold/italic
    $text = preg_replace('/`[^`]+`/', '', $text);             // inline code
    $text = preg_replace('/```[\s\S]*?```/', '', $text);      // fenced code
    $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $text); // links
    $text = preg_replace('/\s+/', ' ', trim($text));

    if (mb_strlen($text) <= $len) return $text;
    return mb_substr($text, 0, $len - 1) . '…';
}

/**
 * Rate limiting — sliding window.
 * Limits: thread = 3/hour, reply = 10/hour, react = 30/10min
 * Prunes old records on each check.
 */
function check_rate_limit(int $install_id, string $action): void {
    $pdo = get_pdo();

    $windows = [
        'thread' => ['seconds' => 3600, 'max' => 3],
        'reply'  => ['seconds' => 3600, 'max' => 10],
        'react'  => ['seconds' => 600,  'max' => 30],
    ];

    if (!isset($windows[$action])) return;
    $w = $windows[$action];

    // Prune old entries for this install+action before counting
    $pdo->prepare(
        "DELETE FROM ss_forum_rate_limit
         WHERE install_id = ? AND action = ? AND created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)"
    )->execute([$install_id, $action, $w['seconds']]);

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM ss_forum_rate_limit
         WHERE install_id = ? AND action = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)"
    );
    $stmt->execute([$install_id, $action, $w['seconds']]);
    $count = (int)$stmt->fetchColumn();

    if ($count >= $w['max']) {
        api_error(429, 'RATE_LIMITED', "Slow down — you can post $w[max] {$action}s per " . ($w['seconds'] === 3600 ? 'hour' : '10 minutes') . '.');
    }

    $pdo->prepare(
        "INSERT INTO ss_forum_rate_limit (install_id, action) VALUES (?, ?)"
    )->execute([$install_id, $action]);
}

/**
 * Fetch reactions for a set of targets in one query.
 * Returns [target_id => ['counts' => ['👍'=>2,...], 'my_emoji' => '👍'|null]]
 */
function fetch_reactions(string $target_type, array $ids, int $caller_install_id): array {
    if (empty($ids)) return [];
    $pdo = get_pdo();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT target_id, install_id, emoji
         FROM ss_forum_reactions
         WHERE target_type = ? AND target_id IN ($placeholders)"
    );
    $stmt->execute(array_merge([$target_type], $ids));
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $tid = (int)$row['target_id'];
        if (!isset($result[$tid])) {
            $result[$tid] = ['counts' => [], 'my_emoji' => null];
        }
        $emoji = $row['emoji'];
        $result[$tid]['counts'][$emoji] = ($result[$tid]['counts'][$emoji] ?? 0) + 1;
        if ((int)$row['install_id'] === $caller_install_id) {
            $result[$tid]['my_emoji'] = $emoji;
        }
    }
    return $result;
}

/**
 * Rebuild the tag_cache JSON string for a thread from ss_forum_thread_tags.
 * Stored as comma-separated slugs for lightweight listing queries.
 */
function rebuild_tag_cache(int $thread_id): string {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        "SELECT t.slug FROM ss_forum_tags t
         JOIN ss_forum_thread_tags tt ON tt.tag_id = t.id
         WHERE tt.thread_id = ?
         ORDER BY t.name ASC"
    );
    $stmt->execute([$thread_id]);
    return implode(',', array_column($stmt->fetchAll(), 'slug'));
}

/**
 * Create notifications for a new reply.
 * Notifies: thread author (type=reply_to_thread), anyone else who has replied (type=reply_to_watched).
 * Does not notify the person who just replied.
 */
function create_reply_notifications(int $reply_id, int $thread_id, int $actor_install_id, string $actor_name, string $actor_domain, string $thread_title): void {
    $pdo = get_pdo();

    // Thread author
    $tstmt = $pdo->prepare("SELECT install_id FROM ss_forum_threads WHERE id = ? LIMIT 1");
    $tstmt->execute([$thread_id]);
    $thread_author_id = (int)($tstmt->fetchColumn() ?? 0);

    // All unique participants (excluding actor)
    $pstmt = $pdo->prepare(
        "SELECT DISTINCT install_id FROM ss_forum_replies
         WHERE thread_id = ? AND is_deleted = 0 AND install_id != ?"
    );
    $pstmt->execute([$thread_id, $actor_install_id]);
    $participants = array_column($pstmt->fetchAll(), 'install_id');

    $notified = [];

    // Thread author first
    if ($thread_author_id && $thread_author_id !== $actor_install_id) {
        $pdo->prepare(
            "INSERT INTO ss_forum_notifications
             (install_id, type, thread_id, reply_id, actor_name, actor_domain, thread_title)
             VALUES (?, 'reply_to_thread', ?, ?, ?, ?, ?)"
        )->execute([$thread_author_id, $thread_id, $reply_id, $actor_name, $actor_domain, $thread_title]);
        $notified[] = $thread_author_id;
    }

    // Other participants
    foreach ($participants as $pid) {
        $pid = (int)$pid;
        if ($pid === $actor_install_id || in_array($pid, $notified)) continue;
        $pdo->prepare(
            "INSERT INTO ss_forum_notifications
             (install_id, type, thread_id, reply_id, actor_name, actor_domain, thread_title)
             VALUES (?, 'reply_to_watched', ?, ?, ?, ?, ?)"
        )->execute([$pid, $thread_id, $reply_id, $actor_name, $actor_domain, $thread_title]);
        $notified[] = $pid;
    }
}

// ── Router ────────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$path   = trim($_GET['path'] ?? '', '/');
$parts  = $path !== '' ? explode('/', $path) : [];

// ─────────────────────────────────────────────────────────────────────────────
// POST /register
// Register a new install. Idempotent: re-registering the same domain returns
// the existing key so a lost key can be recovered.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $path === 'register') {
    $b            = body();
    $domain       = trim($b['domain']       ?? '');
    $display_name = trim($b['display_name'] ?? '');
    $ss_version   = trim($b['ss_version']   ?? '');

    if ($domain === '' || $display_name === '') {
        api_error(400, 'MISSING_FIELDS', 'domain and display_name are required.');
    }

    $domain = preg_replace('/^https?:\/\//i', '', $domain);
    $domain = rtrim($domain, '/');
    $domain = strtolower($domain);

    if (mb_strlen($domain)       > 255) api_error(400, 'DOMAIN_TOO_LONG', 'domain must be 255 chars or fewer.');
    if (mb_strlen($display_name) > 100) api_error(400, 'NAME_TOO_LONG',   'display_name must be 100 chars or fewer.');

    $pdo = get_pdo();

    $stmt = $pdo->prepare("SELECT api_key FROM ss_forum_installs WHERE domain = ? LIMIT 1");
    $stmt->execute([$domain]);
    $existing = $stmt->fetch();
    if ($existing) {
        $pdo->prepare(
            "UPDATE ss_forum_installs SET display_name = ?, ss_version = ?, last_seen_at = NOW() WHERE domain = ?"
        )->execute([$display_name, $ss_version, $domain]);
        $mod_stmt = $pdo->prepare("SELECT is_moderator FROM ss_forum_installs WHERE domain = ? LIMIT 1");
        $mod_stmt->execute([$domain]);
        $mod_row = $mod_stmt->fetch();
        respond(['api_key' => $existing['api_key'], 'status' => 'existing', 'is_moderator' => (bool)($mod_row['is_moderator'] ?? 0)]);
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
// GET /threads?cat=N&page=N&tag=slug
// Returns thread list with excerpts, last-reply info, solved state, unread flag.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $path === 'threads') {
    $install  = require_auth();
    $pdo      = get_pdo();
    $cat_id   = (int)($_GET['cat']  ?? 0);
    $tag_slug = trim($_GET['tag']   ?? '');
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 25;
    $offset   = ($page - 1) * $per_page;

    $where  = "t.is_deleted = 0";
    $params = [];

    if ($cat_id > 0) {
        $where   .= " AND t.category_id = ?";
        $params[] = $cat_id;
    }

    // Tag filter: join through pivot
    $tag_join = '';
    if ($tag_slug !== '') {
        $tag_join  = "JOIN ss_forum_thread_tags tt2 ON tt2.thread_id = t.id
                      JOIN ss_forum_tags tg2 ON tg2.id = tt2.tag_id AND tg2.slug = ?";
        $params_tag = [$tag_slug];
        // prepend to params (tag join params come before WHERE params in the query)
        $params_for_count = array_merge($params_tag, $params);
    } else {
        $tag_join = '';
        $params_tag = [];
        $params_for_count = $params;
    }

    $count_stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT t.id)
         FROM ss_forum_threads t $tag_join
         WHERE $where"
    );
    $count_stmt->execute($params_for_count);
    $total = (int)$count_stmt->fetchColumn();

    $list_params = array_merge($params_tag, $params, [$per_page, $offset]);
    $stmt = $pdo->prepare("
        SELECT t.id, t.category_id, t.display_name, t.title, t.excerpt,
               t.is_pinned, t.is_locked, t.is_solved, t.solved_reply_id,
               t.reply_count, t.view_count, t.reaction_count, t.tag_cache,
               t.last_reply_at, t.last_reply_display_name, t.last_reply_domain,
               t.created_at,
               c.name AS category_name, c.slug AS category_slug,
               i.domain AS author_domain,
               rs.read_reply_count
        FROM ss_forum_threads t
        JOIN ss_forum_categories c ON c.id = t.category_id
        LEFT JOIN ss_forum_installs i ON i.id = t.install_id
        LEFT JOIN ss_forum_read_state rs ON rs.thread_id = t.id AND rs.install_id = ?
        $tag_join
        WHERE $where
        ORDER BY t.is_pinned DESC, t.last_reply_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge([$install['id']], $list_params));
    $threads = $stmt->fetchAll();

    // Annotate with has_new (replies since last read)
    foreach ($threads as &$t) {
        $read_count = $t['read_reply_count'];
        $t['has_new']  = ($read_count === null) ? true : ((int)$t['reply_count'] > (int)$read_count);
        $t['is_unread'] = ($read_count === null);
        unset($t['read_reply_count']);

        // Expand tag_cache into array for clients
        $t['tags'] = $t['tag_cache'] !== '' ? explode(',', $t['tag_cache']) : [];
    }
    unset($t);

    respond([
        'threads'       => $threads,
        'total_count'   => $total,
        'page'          => $page,
        'per_page'      => $per_page,
        'has_more'      => ($offset + count($threads)) < $total,
        'caller_is_mod' => is_install_mod($install),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET /threads/{id}
// Full thread with replies, reactions, solved state. Increments view count.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && count($parts) === 2 && $parts[0] === 'threads' && ctype_digit($parts[1])) {
    $install   = require_auth();
    $thread_id = (int)$parts[1];
    $pdo       = get_pdo();

    $stmt = $pdo->prepare("
        SELECT t.*, c.name AS category_name, c.slug AS category_slug,
               i.domain AS author_domain
        FROM ss_forum_threads t
        JOIN ss_forum_categories c ON c.id = t.category_id
        LEFT JOIN ss_forum_installs i ON i.id = t.install_id
        WHERE t.id = ? AND t.is_deleted = 0
        LIMIT 1
    ");
    $stmt->execute([$thread_id]);
    $thread = $stmt->fetch();
    if (!$thread) {
        api_error(404, 'THREAD_NOT_FOUND', 'That thread does not exist or has been removed.');
    }

    // Increment view count (fire and forget — ignore failure)
    try {
        $pdo->prepare("UPDATE ss_forum_threads SET view_count = view_count + 1 WHERE id = ?")
            ->execute([$thread_id]);
        $thread['view_count'] = (int)$thread['view_count'] + 1;
    } catch (Exception $e) {}

    // Replies
    $reply_stmt = $pdo->prepare("
        SELECT r.id, r.install_id, r.display_name, r.body, r.created_at,
               r.is_edited, r.edited_at, r.reaction_count,
               i.domain AS author_domain
        FROM ss_forum_replies r
        LEFT JOIN ss_forum_installs i ON i.id = r.install_id
        WHERE r.thread_id = ? AND r.is_deleted = 0
        ORDER BY r.created_at ASC
    ");
    $reply_stmt->execute([$thread_id]);
    $replies = $reply_stmt->fetchAll();

    // Reactions on thread
    $thread_reactions = fetch_reactions('thread', [$thread_id], (int)$install['id']);
    $thread['reactions'] = $thread_reactions[$thread_id]['counts'] ?? [];
    $thread['my_reaction'] = $thread_reactions[$thread_id]['my_emoji'] ?? null;

    // Reactions on replies
    $reply_ids = array_column($replies, 'id');
    $reply_reactions = fetch_reactions('reply', array_map('intval', $reply_ids), (int)$install['id']);
    foreach ($replies as &$r) {
        $rid = (int)$r['id'];
        $r['reactions']   = $reply_reactions[$rid]['counts'] ?? [];
        $r['my_reaction'] = $reply_reactions[$rid]['my_emoji'] ?? null;
    }
    unset($r);

    // Tags for this thread
    $tag_stmt = $pdo->prepare(
        "SELECT tg.id, tg.slug, tg.name
         FROM ss_forum_tags tg
         JOIN ss_forum_thread_tags tt ON tt.tag_id = tg.id
         WHERE tt.thread_id = ?
         ORDER BY tg.name ASC"
    );
    $tag_stmt->execute([$thread_id]);
    $thread['tags'] = $tag_stmt->fetchAll();

    // Read state for caller
    $rs_stmt = $pdo->prepare(
        "SELECT read_reply_count FROM ss_forum_read_state WHERE install_id = ? AND thread_id = ? LIMIT 1"
    );
    $rs_stmt->execute([$install['id'], $thread_id]);
    $rs = $rs_stmt->fetch();
    $thread['caller_read_reply_count'] = $rs ? (int)$rs['read_reply_count'] : null;

    respond([
        'thread'        => $thread,
        'replies'       => $replies,
        'caller_is_mod' => is_install_mod($install),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// POST /threads
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $path === 'threads') {
    $install = require_auth();

    check_rate_limit((int)$install['id'], 'thread');

    $b      = body();
    $cat_id = (int)trim($b['category_id'] ?? 0);
    $title  = trim($b['title']            ?? '');
    $text   = trim($b['body']             ?? '');

    if (!$cat_id || $title === '' || $text === '') {
        api_error(400, 'MISSING_FIELDS', 'category_id, title, and body are required.');
    }
    if (mb_strlen($title) > 200)   api_error(400, 'TITLE_TOO_LONG', 'Title must be 200 characters or fewer.');
    if (mb_strlen($text)  > 20000) api_error(400, 'BODY_TOO_LONG',  'Body must be 20,000 characters or fewer.');

    $pdo = get_pdo();

    $cat = $pdo->prepare("SELECT id FROM ss_forum_categories WHERE id = ? AND is_active = 1 LIMIT 1");
    $cat->execute([$cat_id]);
    if (!$cat->fetch()) {
        api_error(400, 'INVALID_CATEGORY', 'Category not found or inactive.');
    }

    $excerpt = make_excerpt($text);

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO ss_forum_threads
             (category_id, install_id, display_name, title, body, excerpt,
              last_reply_display_name, last_reply_domain)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $cat_id,
            $install['id'],
            $install['display_name'],
            $title,
            $text,
            $excerpt,
            $install['display_name'],
            $install['domain'],
        ]);
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
// PATCH /threads/{id}
// Mod: pin/lock flags.
// Own (or mod): edit body.
// These are distinguished by which fields are in the request body.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'PATCH' && count($parts) === 2 && $parts[0] === 'threads' && ctype_digit($parts[1])) {
    $install   = require_auth();
    $thread_id = (int)$parts[1];
    $b         = body();
    $pdo       = get_pdo();

    $stmt = $pdo->prepare("SELECT * FROM ss_forum_threads WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $stmt->execute([$thread_id]);
    $thread = $stmt->fetch();
    if (!$thread) api_error(404, 'THREAD_NOT_FOUND', 'Thread not found.');

    $is_mod_caller = is_install_mod($install);
    $is_own        = (int)$thread['install_id'] === (int)$install['id'];

    // ── Body edit ──────────────────────────────────────────────────────────
    if (isset($b['body'])) {
        if (!$is_own && !$is_mod_caller) {
            api_error(403, 'FORBIDDEN', 'You can only edit your own threads.');
        }
        $new_body = trim($b['body']);
        if ($new_body === '') api_error(400, 'MISSING_FIELDS', 'body cannot be empty.');
        if (mb_strlen($new_body) > 20000) api_error(400, 'BODY_TOO_LONG', 'Body must be 20,000 characters or fewer.');

        // Snapshot to edit history
        $pdo->prepare(
            "INSERT INTO ss_forum_edit_history (target_type, target_id, install_id, body_before)
             VALUES ('thread', ?, ?, ?)"
        )->execute([$thread_id, $install['id'], $thread['body']]);

        $new_excerpt = make_excerpt($new_body);

        $pdo->prepare(
            "UPDATE ss_forum_threads
             SET body = ?, excerpt = ?, is_edited = 1, edited_at = NOW()
             WHERE id = ?"
        )->execute([$new_body, $new_excerpt, $thread_id]);

        respond(['status' => 'updated', 'excerpt' => $new_excerpt]);
    }

    // ── Mod flags (pin/lock) ───────────────────────────────────────────────
    if (isset($b['is_pinned']) || isset($b['is_locked'])) {
        if (!$is_mod_caller) {
            api_error(403, 'FORBIDDEN', 'Only moderators can change pin/lock flags.');
        }

        $pinned = $thread['is_pinned'];
        $locked = $thread['is_locked'];
        if (isset($b['is_pinned'])) $pinned = $b['is_pinned'] ? 1 : 0;
        if (isset($b['is_locked'])) $locked = $b['is_locked'] ? 1 : 0;

        $pdo->prepare("UPDATE ss_forum_threads SET is_pinned = ?, is_locked = ? WHERE id = ?")
            ->execute([$pinned, $locked, $thread_id]);

        respond(['status' => 'updated', 'is_pinned' => (bool)$pinned, 'is_locked' => (bool)$locked]);
    }

    api_error(400, 'MISSING_FIELDS', 'Provide body (to edit content) or is_pinned/is_locked (mod flags).');
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE /threads/{id}
// Own threads: install must match. Moderator: any thread.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'DELETE' && count($parts) === 2 && $parts[0] === 'threads' && ctype_digit($parts[1])) {
    $mod       = is_mod();
    $install   = $mod ? null : require_auth();
    $has_power = is_install_mod($install);
    $thread_id = (int)$parts[1];
    $pdo       = get_pdo();

    $stmt = $pdo->prepare(
        "SELECT id, install_id, category_id FROM ss_forum_threads WHERE id = ? AND is_deleted = 0 LIMIT 1"
    );
    $stmt->execute([$thread_id]);
    $thread = $stmt->fetch();
    if (!$thread) api_error(404, 'THREAD_NOT_FOUND', 'Thread not found.');

    if (!$has_power && (int)$thread['install_id'] !== (int)$install['id']) {
        api_error(403, 'FORBIDDEN', 'You can only delete your own threads.');
    }

    $pdo->prepare("UPDATE ss_forum_threads SET is_deleted = 1 WHERE id = ?")->execute([$thread_id]);
    $pdo->prepare(
        "UPDATE ss_forum_categories SET thread_count = GREATEST(0, thread_count - 1) WHERE id = ?"
    )->execute([$thread['category_id']]);

    respond(['status' => 'deleted']);
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

    check_rate_limit((int)$install['id'], 'reply');

    $b    = body();
    $text = trim($b['body'] ?? '');

    if ($text === '') api_error(400, 'MISSING_FIELDS', 'body is required.');
    if (mb_strlen($text) > 10000) api_error(400, 'BODY_TOO_LONG', 'Reply must be 10,000 characters or fewer.');

    $pdo = get_pdo();

    $stmt = $pdo->prepare(
        "SELECT id, category_id, is_locked, title FROM ss_forum_threads WHERE id = ? AND is_deleted = 0 LIMIT 1"
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
            "UPDATE ss_forum_threads
             SET reply_count = reply_count + 1,
                 last_reply_at = NOW(),
                 last_reply_display_name = ?,
                 last_reply_domain = ?
             WHERE id = ?"
        )->execute([$install['display_name'], $install['domain'], $thread_id]);

        $pdo->prepare(
            "UPDATE ss_forum_categories SET reply_count = reply_count + 1 WHERE id = ?"
        )->execute([$thread['category_id']]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        api_error(500, 'SERVER_ERROR', 'Failed to add reply.');
    }

    // Notifications (outside transaction, non-critical)
    try {
        create_reply_notifications(
            $reply_id, $thread_id, (int)$install['id'],
            $install['display_name'], $install['domain'], $thread['title']
        );
    } catch (Exception $e) {}

    respond(['reply_id' => $reply_id], 201);
}

// ─────────────────────────────────────────────────────────────────────────────
// PATCH /replies/{id}  — edit reply body (own or mod)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'PATCH' && count($parts) === 2 && $parts[0] === 'replies' && ctype_digit($parts[1])) {
    $install  = require_auth();
    $reply_id = (int)$parts[1];
    $b        = body();
    $new_body = trim($b['body'] ?? '');
    $pdo      = get_pdo();

    if ($new_body === '') api_error(400, 'MISSING_FIELDS', 'body is required.');
    if (mb_strlen($new_body) > 10000) api_error(400, 'BODY_TOO_LONG', 'Reply must be 10,000 characters or fewer.');

    $stmt = $pdo->prepare("SELECT * FROM ss_forum_replies WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $stmt->execute([$reply_id]);
    $reply = $stmt->fetch();
    if (!$reply) api_error(404, 'REPLY_NOT_FOUND', 'Reply not found.');

    if (!is_install_mod($install) && (int)$reply['install_id'] !== (int)$install['id']) {
        api_error(403, 'FORBIDDEN', 'You can only edit your own replies.');
    }

    // Snapshot to edit history
    $pdo->prepare(
        "INSERT INTO ss_forum_edit_history (target_type, target_id, install_id, body_before)
         VALUES ('reply', ?, ?, ?)"
    )->execute([$reply_id, $install['id'], $reply['body']]);

    $pdo->prepare(
        "UPDATE ss_forum_replies SET body = ?, is_edited = 1, edited_at = NOW() WHERE id = ?"
    )->execute([$new_body, $reply_id]);

    respond(['status' => 'updated']);
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE /replies/{id}
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'DELETE' && count($parts) === 2 && $parts[0] === 'replies' && ctype_digit($parts[1])) {
    $mod       = is_mod();
    $install   = $mod ? null : require_auth();
    $has_power = is_install_mod($install);
    $reply_id  = (int)$parts[1];
    $pdo       = get_pdo();

    $stmt = $pdo->prepare(
        "SELECT id, install_id, thread_id FROM ss_forum_replies WHERE id = ? AND is_deleted = 0 LIMIT 1"
    );
    $stmt->execute([$reply_id]);
    $reply = $stmt->fetch();
    if (!$reply) api_error(404, 'REPLY_NOT_FOUND', 'Reply not found.');

    if (!$has_power && (int)$reply['install_id'] !== (int)$install['id']) {
        api_error(403, 'FORBIDDEN', 'You can only delete your own replies.');
    }

    $pdo->prepare("UPDATE ss_forum_replies SET is_deleted = 1 WHERE id = ?")->execute([$reply_id]);

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

    // If this was the solved reply, unsolve the thread
    $pdo->prepare(
        "UPDATE ss_forum_threads SET is_solved = 0, solved_reply_id = NULL
         WHERE id = ? AND solved_reply_id = ?"
    )->execute([$reply['thread_id'], $reply_id]);

    respond(['status' => 'deleted']);
}

// ─────────────────────────────────────────────────────────────────────────────
// POST /replies/{id}/solve  — mark as accepted answer
// DELETE /replies/{id}/solve — unmark
// Thread author or mod only.
// ─────────────────────────────────────────────────────────────────────────────
if (count($parts) === 3 && $parts[0] === 'replies' && ctype_digit($parts[1]) && $parts[2] === 'solve') {
    $install  = require_auth();
    $reply_id = (int)$parts[1];
    $pdo      = get_pdo();

    $rstmt = $pdo->prepare("SELECT thread_id FROM ss_forum_replies WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $rstmt->execute([$reply_id]);
    $reply = $rstmt->fetch();
    if (!$reply) api_error(404, 'REPLY_NOT_FOUND', 'Reply not found.');

    $thread_id = (int)$reply['thread_id'];
    $tstmt = $pdo->prepare("SELECT install_id FROM ss_forum_threads WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $tstmt->execute([$thread_id]);
    $thread = $tstmt->fetch();
    if (!$thread) api_error(404, 'THREAD_NOT_FOUND', 'Thread not found.');

    $is_thread_author = (int)$thread['install_id'] === (int)$install['id'];
    if (!$is_thread_author && !is_install_mod($install)) {
        api_error(403, 'FORBIDDEN', 'Only the thread author or a moderator can mark an accepted answer.');
    }

    if ($method === 'POST') {
        $pdo->prepare(
            "UPDATE ss_forum_threads SET is_solved = 1, solved_reply_id = ? WHERE id = ?"
        )->execute([$reply_id, $thread_id]);
        respond(['status' => 'solved', 'solved_reply_id' => $reply_id]);
    }

    if ($method === 'DELETE') {
        $pdo->prepare(
            "UPDATE ss_forum_threads SET is_solved = 0, solved_reply_id = NULL WHERE id = ?"
        )->execute([$thread_id]);
        respond(['status' => 'unsolved']);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// POST /threads/{id}/react   — add or toggle emoji on thread
// DELETE /threads/{id}/react — remove own reaction
// ─────────────────────────────────────────────────────────────────────────────
if (count($parts) === 3 && $parts[0] === 'threads' && ctype_digit($parts[1]) && $parts[2] === 'react') {
    $install   = require_auth();
    $thread_id = (int)$parts[1];
    $pdo       = get_pdo();

    $tstmt = $pdo->prepare("SELECT id FROM ss_forum_threads WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $tstmt->execute([$thread_id]);
    if (!$tstmt->fetch()) api_error(404, 'THREAD_NOT_FOUND', 'Thread not found.');

    if ($method === 'POST') {
        check_rate_limit((int)$install['id'], 'react');

        $b     = body();
        $emoji = trim($b['emoji'] ?? '');
        if ($emoji === '') api_error(400, 'MISSING_FIELDS', 'emoji is required.');
        if (mb_strlen($emoji) > 12) api_error(400, 'INVALID_EMOJI', 'emoji value too long.');

        // Check existing reaction for this install on this thread
        $existing = $pdo->prepare(
            "SELECT id, emoji FROM ss_forum_reactions
             WHERE target_type='thread' AND target_id=? AND install_id=? LIMIT 1"
        );
        $existing->execute([$thread_id, $install['id']]);
        $current = $existing->fetch();

        if ($current) {
            if ($current['emoji'] === $emoji) {
                // Same emoji: toggle off
                $pdo->prepare("DELETE FROM ss_forum_reactions WHERE id = ?")->execute([$current['id']]);
                $pdo->prepare(
                    "UPDATE ss_forum_threads SET reaction_count = GREATEST(0, reaction_count - 1) WHERE id = ?"
                )->execute([$thread_id]);
                respond(['status' => 'removed', 'emoji' => $emoji]);
            } else {
                // Different emoji: replace
                $pdo->prepare("UPDATE ss_forum_reactions SET emoji = ? WHERE id = ?")->execute([$emoji, $current['id']]);
                respond(['status' => 'changed', 'emoji' => $emoji]);
            }
        } else {
            $pdo->prepare(
                "INSERT INTO ss_forum_reactions (target_type, target_id, install_id, emoji) VALUES ('thread', ?, ?, ?)"
            )->execute([$thread_id, $install['id'], $emoji]);
            $pdo->prepare(
                "UPDATE ss_forum_threads SET reaction_count = reaction_count + 1 WHERE id = ?"
            )->execute([$thread_id]);
            respond(['status' => 'added', 'emoji' => $emoji], 201);
        }
    }

    if ($method === 'DELETE') {
        $del = $pdo->prepare(
            "DELETE FROM ss_forum_reactions
             WHERE target_type='thread' AND target_id=? AND install_id=?"
        );
        $del->execute([$thread_id, $install['id']]);
        if ($del->rowCount() > 0) {
            $pdo->prepare(
                "UPDATE ss_forum_threads SET reaction_count = GREATEST(0, reaction_count - 1) WHERE id = ?"
            )->execute([$thread_id]);
        }
        respond(['status' => 'removed']);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// POST /replies/{id}/react   — add or toggle emoji on reply
// DELETE /replies/{id}/react — remove own reaction
// ─────────────────────────────────────────────────────────────────────────────
if (count($parts) === 3 && $parts[0] === 'replies' && ctype_digit($parts[1]) && $parts[2] === 'react') {
    $install  = require_auth();
    $reply_id = (int)$parts[1];
    $pdo      = get_pdo();

    $rstmt = $pdo->prepare("SELECT id FROM ss_forum_replies WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $rstmt->execute([$reply_id]);
    if (!$rstmt->fetch()) api_error(404, 'REPLY_NOT_FOUND', 'Reply not found.');

    if ($method === 'POST') {
        check_rate_limit((int)$install['id'], 'react');

        $b     = body();
        $emoji = trim($b['emoji'] ?? '');
        if ($emoji === '') api_error(400, 'MISSING_FIELDS', 'emoji is required.');
        if (mb_strlen($emoji) > 12) api_error(400, 'INVALID_EMOJI', 'emoji value too long.');

        $existing = $pdo->prepare(
            "SELECT id, emoji FROM ss_forum_reactions
             WHERE target_type='reply' AND target_id=? AND install_id=? LIMIT 1"
        );
        $existing->execute([$reply_id, $install['id']]);
        $current = $existing->fetch();

        if ($current) {
            if ($current['emoji'] === $emoji) {
                $pdo->prepare("DELETE FROM ss_forum_reactions WHERE id = ?")->execute([$current['id']]);
                $pdo->prepare(
                    "UPDATE ss_forum_replies SET reaction_count = GREATEST(0, reaction_count - 1) WHERE id = ?"
                )->execute([$reply_id]);
                respond(['status' => 'removed', 'emoji' => $emoji]);
            } else {
                $pdo->prepare("UPDATE ss_forum_reactions SET emoji = ? WHERE id = ?")->execute([$emoji, $current['id']]);
                respond(['status' => 'changed', 'emoji' => $emoji]);
            }
        } else {
            $pdo->prepare(
                "INSERT INTO ss_forum_reactions (target_type, target_id, install_id, emoji) VALUES ('reply', ?, ?, ?)"
            )->execute([$reply_id, $install['id'], $emoji]);
            $pdo->prepare(
                "UPDATE ss_forum_replies SET reaction_count = reaction_count + 1 WHERE id = ?"
            )->execute([$reply_id]);
            respond(['status' => 'added', 'emoji' => $emoji], 201);
        }
    }

    if ($method === 'DELETE') {
        $del = $pdo->prepare(
            "DELETE FROM ss_forum_reactions
             WHERE target_type='reply' AND target_id=? AND install_id=?"
        );
        $del->execute([$reply_id, $install['id']]);
        if ($del->rowCount() > 0) {
            $pdo->prepare(
                "UPDATE ss_forum_replies SET reaction_count = GREATEST(0, reaction_count - 1) WHERE id = ?"
            )->execute([$reply_id]);
        }
        respond(['status' => 'removed']);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// POST /threads/{id}/read  — mark thread as read (update read state)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST' && count($parts) === 3 && $parts[0] === 'threads' && ctype_digit($parts[1]) && $parts[2] === 'read') {
    $install   = require_auth();
    $thread_id = (int)$parts[1];
    $pdo       = get_pdo();

    $tstmt = $pdo->prepare("SELECT reply_count FROM ss_forum_threads WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $tstmt->execute([$thread_id]);
    $thread = $tstmt->fetch();
    if (!$thread) api_error(404, 'THREAD_NOT_FOUND', 'Thread not found.');

    $pdo->prepare(
        "INSERT INTO ss_forum_read_state (install_id, thread_id, read_reply_count)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE read_reply_count = VALUES(read_reply_count), last_read_at = NOW()"
    )->execute([$install['id'], $thread_id, $thread['reply_count']]);

    respond(['status' => 'read', 'read_reply_count' => (int)$thread['reply_count']]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET /search?q=term&cat=N&page=N
// Full-text search across thread titles/bodies and reply bodies.
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $path === 'search') {
    $install  = require_auth();
    $q        = trim($_GET['q']    ?? '');
    $cat_id   = (int)($_GET['cat'] ?? 0);
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 20;
    $offset   = ($page - 1) * $per_page;

    if (mb_strlen($q) < 2) {
        api_error(400, 'QUERY_TOO_SHORT', 'Search query must be at least 2 characters.');
    }
    if (mb_strlen($q) > 200) {
        api_error(400, 'QUERY_TOO_LONG', 'Search query must be 200 characters or fewer.');
    }

    $pdo = get_pdo();

    // Thread search
    $t_where  = "t.is_deleted = 0 AND MATCH(t.title, t.body) AGAINST(? IN BOOLEAN MODE)";
    $t_params = [$q];
    if ($cat_id > 0) { $t_where .= " AND t.category_id = ?"; $t_params[] = $cat_id; }

    $thread_stmt = $pdo->prepare("
        SELECT 'thread' AS result_type,
               t.id, t.title, t.excerpt, t.display_name, t.created_at,
               t.reply_count, t.view_count, t.is_solved,
               c.name AS category_name, c.slug AS category_slug,
               NULL AS thread_id, NULL AS thread_title,
               MATCH(t.title, t.body) AGAINST(? IN BOOLEAN MODE) AS relevance
        FROM ss_forum_threads t
        JOIN ss_forum_categories c ON c.id = t.category_id
        WHERE $t_where
        ORDER BY relevance DESC, t.last_reply_at DESC
        LIMIT ? OFFSET ?
    ");
    $thread_stmt->execute(array_merge([$q], $t_params, [$per_page, $offset]));
    $thread_results = $thread_stmt->fetchAll();

    // Reply search (only if no thread results fill the page, or always include both)
    $r_where  = "r.is_deleted = 0 AND t.is_deleted = 0 AND MATCH(r.body) AGAINST(? IN BOOLEAN MODE)";
    $r_params = [$q];
    if ($cat_id > 0) { $r_where .= " AND t.category_id = ?"; $r_params[] = $cat_id; }

    $reply_stmt = $pdo->prepare("
        SELECT 'reply' AS result_type,
               r.id, NULL AS title, r.body AS excerpt, r.display_name, r.created_at,
               NULL AS reply_count, NULL AS view_count, NULL AS is_solved,
               c.name AS category_name, c.slug AS category_slug,
               t.id AS thread_id, t.title AS thread_title,
               MATCH(r.body) AGAINST(? IN BOOLEAN MODE) AS relevance
        FROM ss_forum_replies r
        JOIN ss_forum_threads t ON t.id = r.thread_id
        JOIN ss_forum_categories c ON c.id = t.category_id
        WHERE $r_where
        ORDER BY relevance DESC, r.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $reply_stmt->execute(array_merge([$q], $r_params, [$per_page, $offset]));
    $reply_results = $reply_stmt->fetchAll();

    respond([
        'query'          => $q,
        'threads'        => $thread_results,
        'replies'        => $reply_results,
        'page'           => $page,
        'per_page'       => $per_page,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET /tags  — list all tags with thread counts
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $path === 'tags') {
    require_auth();
    $pdo  = get_pdo();
    $tags = $pdo->query(
        "SELECT id, slug, name, thread_count FROM ss_forum_tags ORDER BY thread_count DESC, name ASC"
    )->fetchAll();
    respond(['tags' => $tags]);
}

// ─────────────────────────────────────────────────────────────────────────────
// POST /threads/{id}/tags  — add tag to thread (mod only)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST' && count($parts) === 3 && $parts[0] === 'threads' && ctype_digit($parts[1]) && $parts[2] === 'tags') {
    $install   = require_auth();
    $thread_id = (int)$parts[1];
    $b         = body();
    $pdo       = get_pdo();

    if (!is_install_mod($install)) api_error(403, 'FORBIDDEN', 'Only moderators can tag threads.');

    $tstmt = $pdo->prepare("SELECT id FROM ss_forum_threads WHERE id = ? AND is_deleted = 0 LIMIT 1");
    $tstmt->execute([$thread_id]);
    if (!$tstmt->fetch()) api_error(404, 'THREAD_NOT_FOUND', 'Thread not found.');

    // Accept tag_id (existing) or slug+name (create new)
    $tag_id   = (int)($b['tag_id'] ?? 0);
    $tag_slug = trim($b['slug'] ?? '');
    $tag_name = trim($b['name'] ?? '');

    if ($tag_id > 0) {
        $check = $pdo->prepare("SELECT id FROM ss_forum_tags WHERE id = ? LIMIT 1");
        $check->execute([$tag_id]);
        if (!$check->fetch()) api_error(404, 'TAG_NOT_FOUND', 'Tag not found.');
    } elseif ($tag_slug !== '' && $tag_name !== '') {
        // Create tag if needed
        $tag_slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($tag_slug));
        $tag_slug = preg_replace('/-+/', '-', trim($tag_slug, '-'));
        if (mb_strlen($tag_slug) > 50) api_error(400, 'SLUG_TOO_LONG', 'Tag slug must be 50 chars or fewer.');
        if (mb_strlen($tag_name) > 80) api_error(400, 'NAME_TOO_LONG', 'Tag name must be 80 chars or fewer.');

        $pdo->prepare("INSERT IGNORE INTO ss_forum_tags (slug, name) VALUES (?, ?)")->execute([$tag_slug, $tag_name]);
        $id_stmt = $pdo->prepare("SELECT id FROM ss_forum_tags WHERE slug = ? LIMIT 1");
        $id_stmt->execute([$tag_slug]);
        $tag_id = (int)$id_stmt->fetchColumn();
    } else {
        api_error(400, 'MISSING_FIELDS', 'Provide tag_id, or slug+name to create a new tag.');
    }

    try {
        $pdo->prepare("INSERT IGNORE INTO ss_forum_thread_tags (thread_id, tag_id) VALUES (?, ?)")
            ->execute([$thread_id, $tag_id]);

        $pdo->prepare(
            "UPDATE ss_forum_tags SET thread_count = (
                SELECT COUNT(*) FROM ss_forum_thread_tags WHERE tag_id = ?
             ) WHERE id = ?"
        )->execute([$tag_id, $tag_id]);

        // Rebuild tag_cache on the thread
        $cache = rebuild_tag_cache($thread_id);
        $pdo->prepare("UPDATE ss_forum_threads SET tag_cache = ? WHERE id = ?")->execute([$cache, $thread_id]);
    } catch (Exception $e) {
        api_error(500, 'SERVER_ERROR', 'Failed to apply tag.');
    }

    respond(['status' => 'tagged', 'tag_id' => $tag_id]);
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE /threads/{id}/tags/{tag_id}  — remove tag from thread (mod only)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'DELETE'
    && count($parts) === 4
    && $parts[0] === 'threads'
    && ctype_digit($parts[1])
    && $parts[2] === 'tags'
    && ctype_digit($parts[3])
) {
    $install   = require_auth();
    $thread_id = (int)$parts[1];
    $tag_id    = (int)$parts[3];
    $pdo       = get_pdo();

    if (!is_install_mod($install)) api_error(403, 'FORBIDDEN', 'Only moderators can remove tags.');

    $pdo->prepare("DELETE FROM ss_forum_thread_tags WHERE thread_id = ? AND tag_id = ?")
        ->execute([$thread_id, $tag_id]);

    $pdo->prepare(
        "UPDATE ss_forum_tags SET thread_count = GREATEST(0, (
            SELECT COUNT(*) FROM ss_forum_thread_tags WHERE tag_id = ?
         )) WHERE id = ?"
    )->execute([$tag_id, $tag_id]);

    $cache = rebuild_tag_cache($thread_id);
    $pdo->prepare("UPDATE ss_forum_threads SET tag_cache = ? WHERE id = ?")->execute([$cache, $thread_id]);

    respond(['status' => 'untagged']);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET /notifications?page=N  — get notifications for this install
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $path === 'notifications') {
    $install  = require_auth();
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $per_page = 30;
    $offset   = ($page - 1) * $per_page;
    $pdo      = get_pdo();

    $count_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM ss_forum_notifications WHERE install_id = ?"
    );
    $count_stmt->execute([$install['id']]);
    $total = (int)$count_stmt->fetchColumn();

    $unread_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM ss_forum_notifications WHERE install_id = ? AND is_read = 0"
    );
    $unread_stmt->execute([$install['id']]);
    $unread_count = (int)$unread_stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT id, type, thread_id, reply_id, actor_name, actor_domain, thread_title, is_read, created_at
         FROM ss_forum_notifications
         WHERE install_id = ?
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([$install['id'], $per_page, $offset]);
    $notifications = $stmt->fetchAll();

    respond([
        'notifications' => $notifications,
        'unread_count'  => $unread_count,
        'total_count'   => $total,
        'page'          => $page,
        'per_page'      => $per_page,
        'has_more'      => ($offset + count($notifications)) < $total,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// POST /notifications/read  — mark notification(s) as read
// Body: { "ids": [1,2,3] }  or  { "all": true }
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $path === 'notifications/read') {
    $install = require_auth();
    $b       = body();
    $pdo     = get_pdo();

    if (!empty($b['all'])) {
        $pdo->prepare(
            "UPDATE ss_forum_notifications SET is_read = 1 WHERE install_id = ?"
        )->execute([$install['id']]);
        respond(['status' => 'all_read']);
    }

    $ids = array_filter(array_map('intval', (array)($b['ids'] ?? [])));
    if (empty($ids)) api_error(400, 'MISSING_FIELDS', 'Provide ids array or all:true.');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params       = array_merge($ids, [$install['id']]);
    $pdo->prepare(
        "UPDATE ss_forum_notifications SET is_read = 1
         WHERE id IN ($placeholders) AND install_id = ?"
    )->execute($params);

    respond(['status' => 'read', 'count' => count($ids)]);
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
// 404 — no route matched
// ─────────────────────────────────────────────────────────────────────────────
api_error(404, 'NOT_FOUND', 'Endpoint not found.');
