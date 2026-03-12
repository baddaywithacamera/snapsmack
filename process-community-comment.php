<?php
/**
 * SNAPSMACK - Community Comment Handler
 * Alpha v0.7.3
 *
 * AJAX endpoint. Submits a new comment or deletes an existing comment.
 *
 * Comment identity modes (set in smack-community-config.php):
 *   open       — guest name + optional email accepted; no account needed (default)
 *   hybrid     — accounts get full identity; guests still accepted
 *   registered — community account required (original behaviour)
 *
 * POST (submit):
 *   post_id      (int,    required)
 *   comment_text (string, required)
 *   guest_name   (string, required when guest in open/hybrid mode)
 *   guest_email  (string, optional when guest in open/hybrid mode)
 *
 * POST (delete):
 *   action       'delete'
 *   comment_id   (int, required) — only authenticated account authors can delete
 *
 * Returns JSON on submit:
 *   { comment_id, username, display_name, avatar_url, comment_text, created_at, date_label }
 *   Guest variant adds: { is_guest: true, guest_name }
 *
 * Returns JSON on delete:
 *   { deleted: true, comment_id: int }
 */

header('Content-Type: application/json');

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/community-session.php';

// --- METHOD ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// --- SETTINGS ---
$settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                ->fetchAll(PDO::FETCH_KEY_PAIR);

if (($settings['community_enabled'] ?? '1') !== '1' ||
    ($settings['community_comments_enabled'] ?? '1') !== '1') {
    http_response_code(403);
    echo json_encode(['error' => 'comments_disabled']);
    exit;
}

$comment_identity = $settings['comment_identity'] ?? 'open';

// --- CURRENT USER (may be null for open/hybrid guest submissions) ---
$user = community_current_user();

$action_type = trim($_POST['action'] ?? 'submit');

// ============================================================================
// DELETE — always requires an authenticated account user
// ============================================================================
if ($action_type === 'delete') {
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'not_authenticated']);
        exit;
    }

    $comment_id = (int)($_POST['comment_id'] ?? 0);
    if ($comment_id < 1) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_comment_id']);
        exit;
    }

    // Verify ownership — only the account author can delete; guest comments are not deletable via UI
    $owner = $pdo->prepare("SELECT user_id FROM snap_community_comments WHERE id = ? LIMIT 1");
    $owner->execute([$comment_id]);
    $owner_id = $owner->fetchColumn();

    if ($owner_id === false) {
        http_response_code(404);
        echo json_encode(['error' => 'comment_not_found']);
        exit;
    }

    // NULL user_id means a guest comment — not deletable through this endpoint
    if ($owner_id === null || (int)$owner_id !== (int)$user['id']) {
        http_response_code(403);
        echo json_encode(['error' => 'not_your_comment']);
        exit;
    }

    // Soft-delete: preserve comment IDs and prevent thread gaps in the UI
    $pdo->prepare("UPDATE snap_community_comments SET status = 'deleted' WHERE id = ?")
        ->execute([$comment_id]);

    echo json_encode(['deleted' => true, 'comment_id' => $comment_id]);
    exit;
}

// ============================================================================
// SUBMIT
// ============================================================================

// --- AUTH GATE (registered mode only) ---
if ($comment_identity === 'registered' && !$user) {
    http_response_code(401);
    echo json_encode(['error' => 'not_authenticated']);
    exit;
}

// --- INPUT ---
$post_id      = (int)($_POST['post_id'] ?? 0);
$comment_text = trim($_POST['comment_text'] ?? '');

if ($post_id < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_post_id']);
    exit;
}

if (empty($comment_text)) {
    http_response_code(400);
    echo json_encode(['error' => 'empty_comment']);
    exit;
}

if (mb_strlen($comment_text) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'comment_too_long', 'max' => 2000]);
    exit;
}

// --- GUEST FIELDS (open / hybrid, not logged in) ---
$is_guest   = ($comment_identity !== 'registered') && !$user;
$guest_name  = null;
$guest_email = null;

if ($is_guest) {
    $guest_name = trim($_POST['guest_name'] ?? '');
    if (empty($guest_name)) {
        http_response_code(400);
        echo json_encode(['error' => 'guest_name_required']);
        exit;
    }
    if (mb_strlen($guest_name) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'guest_name_too_long']);
        exit;
    }
    $guest_email_raw = trim($_POST['guest_email'] ?? '');
    $guest_email = ($guest_email_raw !== '' && filter_var($guest_email_raw, FILTER_VALIDATE_EMAIL))
        ? $guest_email_raw
        : null;
}

// --- EMAIL VERIFIED CHECK (account users only) ---
if (!$is_guest && $user && !$user['email_verified']) {
    http_response_code(403);
    echo json_encode(['error' => 'email_not_verified']);
    exit;
}

// --- VERIFY POST EXISTS AND ALLOWS COMMENTS ---
$post_stmt = $pdo->prepare("SELECT id, allow_comments FROM snap_images WHERE id = ? LIMIT 1");
$post_stmt->execute([$post_id]);
$post_row = $post_stmt->fetch();

if (!$post_row) {
    http_response_code(404);
    echo json_encode(['error' => 'post_not_found']);
    exit;
}

if ((int)$post_row['allow_comments'] !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'comments_off_for_post']);
    exit;
}

// --- RATE LIMIT (IP-based for both guests and accounts) ---
if (!community_rate_limit('comments')) {
    http_response_code(429);
    echo json_encode(['error' => 'rate_limited', 'message' => 'Too many comments. Slow down.']);
    exit;
}

// --- INSERT ---
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

if ($is_guest) {
    $pdo->prepare("
        INSERT INTO snap_community_comments (post_id, user_id, comment_text, guest_name, guest_email, ip)
        VALUES (?, NULL, ?, ?, ?, ?)
    ")->execute([$post_id, $comment_text, $guest_name, $guest_email, $ip]);
} else {
    $pdo->prepare("
        INSERT INTO snap_community_comments (post_id, user_id, comment_text, ip)
        VALUES (?, ?, ?, ?)
    ")->execute([$post_id, (int)$user['id'], $comment_text, $ip]);
}

$comment_id = (int)$pdo->lastInsertId();
$created_at = date('Y-m-d H:i:s');

if ($is_guest) {
    echo json_encode([
        'comment_id'   => $comment_id,
        'is_guest'     => true,
        'guest_name'   => $guest_name,
        'username'     => null,
        'display_name' => $guest_name,
        'avatar_url'   => null,
        'comment_text' => $comment_text,
        'created_at'   => $created_at,
        'date_label'   => date('Y-m-d', strtotime($created_at)),
    ]);
} else {
    echo json_encode([
        'comment_id'   => $comment_id,
        'is_guest'     => false,
        'guest_name'   => null,
        'username'     => $user['username'],
        'display_name' => $user['display_name'] ?: $user['username'],
        'avatar_url'   => $user['avatar_url'],
        'comment_text' => $comment_text,
        'created_at'   => $created_at,
        'date_label'   => date('Y-m-d', strtotime($created_at)),
    ]);
}
